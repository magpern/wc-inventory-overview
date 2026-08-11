<?php
/**
 * Integration tests for M3 Inventory Overview integration:
 * WC_Inventory_Overview_List_Table Incoming/Position columns, capability
 * gating, bulk-fetch sequencing, drill-down, and variable-parent rollup.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Inventory_Position_List_Table extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	/**
	 * Reset schema/sequence, clear PO + supplier rows, and log in as an
	 * administrator (manage_woocommerce) by default.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * Truncate PO aggregate tables and suppliers so numbering/sequence resets stay unique.
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $po_id PO id.
	 * @return array<string,mixed>
	 */
	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * @return WC_Inventory_Overview_List_Table
	 */
	private function new_table(): WC_Inventory_Overview_List_Table {
		return new WC_Inventory_Overview_List_Table();
	}

	/**
	 * Variation IDs for a just-created variable product. Works around a
	 * pre-existing WooCommerce transient-caching quirk (unrelated to M3)
	 * where create_variable_product() saves the parent before its
	 * variations exist, priming get_children() to return an empty list.
	 *
	 * @param WC_Product_Variable $parent Variable product.
	 * @return int[]
	 */
	private function variation_ids( WC_Product_Variable $parent ): array {
		wc_delete_product_transients( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		return $fresh instanceof WC_Product_Variable ? $fresh->get_children() : array();
	}

	/**
	 * prepare_items() + display_rows() output, for HTML-level assertions.
	 *
	 * @param WC_Inventory_Overview_List_Table $table Table.
	 */
	private function render_table_html( WC_Inventory_Overview_List_Table $table ): string {
		$table->prepare_items();
		ob_start();
		$table->display_rows();
		return (string) ob_get_clean();
	}

	/**
	 * Authorized (manage_woocommerce) users see the Incoming and Position columns.
	 */
	public function test_authorized_user_sees_incoming_and_position_columns() {
		$table = $this->new_table();
		$cols  = $table->get_columns();

		$this->assertArrayHasKey( 'incoming', $cols );
		$this->assertArrayHasKey( 'position', $cols );
	}

	/**
	 * edit_products-only users do not see the Incoming/Position columns (same
	 * sensitivity tier as average cost / inventory value, no new capability).
	 */
	public function test_edit_products_only_user_does_not_see_columns() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$table = $this->new_table();
		$cols  = $table->get_columns();

		$this->assertArrayNotHasKey( 'incoming', $cols );
		$this->assertArrayNotHasKey( 'position', $cols );
	}

	/**
	 * edit_products-only users also receive no drill-down data in the rendered HTML.
	 */
	public function test_edit_products_only_user_gets_no_drilldown_data() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->place_po( $po['id'] );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertSame( array(), $table->position_map );
		$this->assertStringNotContainsString( 'wc-io-position-drilldown', $html );
		$this->assertStringNotContainsString( 'wc-io-incoming-qty', $html );
	}

	/**
	 * Simple product: Incoming/Position populate from the bulk position map and render.
	 */
	public function test_simple_product_incoming_and_position_render() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertArrayHasKey( $product->get_id(), $table->position_map );
		$this->assertEqualsWithDelta( 4.0, $table->position_map[ $product->get_id() ]['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 14.0, $table->position_map[ $product->get_id() ]['position'], 0.0001 );
		$this->assertStringContainsString( 'wc-io-incoming-qty', $html );
		$this->assertStringContainsString( 'wc-io-position-qty', $html );
	}

	/**
	 * Variation: keyed correctly in the bulk position map by its own ID.
	 */
	public function test_variation_incoming_and_position_render() {
		$variable     = $this->create_variable_product(
			array(),
			array(
				array(
					'name'      => 'A',
					'stock_qty' => 3,
				),
			)
		);
		$children     = $this->variation_ids( $variable );
		$variation_id = (int) $children[0];

		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => $variation_id,
				'qty_ordered'  => 6,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$table->prepare_items();

		$this->assertArrayHasKey( $variation_id, $table->position_map );
		$this->assertEqualsWithDelta( 6.0, $table->position_map[ $variation_id ]['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 9.0, $table->position_map[ $variation_id ]['position'], 0.0001 );
	}

	/**
	 * Binding sequencing constraint: variations are discovered later in
	 * group-building (per-parent query), after the initial product query --
	 * they must still receive correct Position data from the single bulk call.
	 */
	public function test_late_discovered_variation_receives_correct_position() {
		$variable = $this->create_variable_product(
			array(),
			array( array( 'name' => 'A' ), array( 'name' => 'B' ) )
		);
		$children = $this->variation_ids( $variable );
		$this->assertGreaterThanOrEqual( 2, count( $children ) );

		$po = $this->create_purchase_order();
		foreach ( $children as $i => $vid ) {
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'   => $variable->get_id(),
					'variation_id' => (int) $vid,
					'qty_ordered'  => 2 + $i,
				)
			);
		}
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$table->prepare_items();

		foreach ( $children as $i => $vid ) {
			$this->assertArrayHasKey( (int) $vid, $table->position_map, 'Late-discovered variation ' . $vid . ' must receive Position data' );
			$this->assertEqualsWithDelta( 2 + $i, $table->position_map[ (int) $vid ]['incoming'], 0.0001 );
		}
	}

	/**
	 * Variable-parent presentation rollup: parent Incoming/Position are the
	 * presentation-only sum of child variation figures (INV-8).
	 */
	public function test_variable_parent_presentation_sum() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array(
					'name'      => 'A',
					'stock_qty' => 1,
				),
				array(
					'name'      => 'B',
					'stock_qty' => 2,
				),
			)
		);
		$children = $this->variation_ids( $variable );

		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => (int) $children[0],
				'qty_ordered'  => 4,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => (int) $children[1],
				'qty_ordered'  => 5,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$table->prepare_items();

		$parent_group = null;
		foreach ( $table->item_groups as $group ) {
			if ( (int) $group['parent']->get_id() === (int) $variable->get_id() ) {
				$parent_group = $group;
				break;
			}
		}
		$this->assertNotNull( $parent_group );

		$agg = WC_Inventory_Overview_List_Table::compute_variable_aggregate(
			$parent_group['parent'],
			$parent_group['children'],
			$table->position_map
		);

		// Incoming: 4 + 5 = 9. Position: (1 on_hand + 4 incoming) + (2 on_hand + 5 incoming) = 12.
		$this->assertEqualsWithDelta( 9.0, $agg['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 12.0, $agg['position'], 0.0001 );
	}

	/**
	 * Drill-down preserves individual line identity: two independent lines
	 * for the same item remain two separate rows, never merged (INV-1/INV-7).
	 */
	public function test_drilldown_preserves_line_identity() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 5,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		// The PO number appears once per contributing line in the drill-down.
		$occurrences = substr_count( $html, esc_html( $po['po_number'] ) );
		$this->assertSame( 2, $occurrences, 'Two independent PO lines must render as two separate drill-down rows' );

		$this->assertStringContainsString( (string) wc_stock_amount( 3 ), $html );
		$this->assertStringContainsString( (string) wc_stock_amount( 5 ), $html );
	}

	/**
	 * Drill-down renders a delayed badge for a delayed contributing line.
	 */
	public function test_drilldown_shows_delayed_indication() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order(
			array(
				'expected_date'       => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 2,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringContainsString( 'wc-io-badge-delayed', $html );
	}

	// -----------------------------------------------------------------
	// M16: Supplier + Status drilldown columns (BR-M16-6/BR-M16-7/BR-M16-8)
	// -----------------------------------------------------------------

	/**
	 * The drilldown mini-table header renders the seven columns in the
	 * exact fixed order defined by BR-M16-8 -- Supplier and Status are the
	 * only new columns, inserted between PO number and Outstanding; the
	 * five pre-existing columns keep their relative order.
	 */
	public function test_drilldown_column_order_is_fixed_per_br_m16_8() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		// prepare_items() is not scoped to this test's own fixture -- other
		// products may still be present from earlier tests in this file
		// (dbDelta()'s implicit commit breaks per-test transaction
		// rollback, a pre-existing quirk of this suite, not introduced by
		// M16). A bare strpos() for the first "<thead><tr>" in the whole
		// page can false-match an unrelated "Variations on this page"
		// mini-table from a leftover variable-parent product. Scope the
		// search to this test's own detail panel instead.
		$panel_marker = 'wc-io-detail-panel-' . $product->get_id() . '"';
		$panel_start  = strpos( $html, $panel_marker );
		$this->assertNotFalse( $panel_start, 'This product\'s own detail panel must be present.' );

		$header_start = strpos( $html, '<thead><tr>', $panel_start );
		$header_end   = strpos( $html, '</tr></thead>', $header_start );
		$this->assertNotFalse( $header_start );
		$this->assertNotFalse( $header_end );
		$header = substr( $html, $header_start, $header_end - $header_start );

		$expected_order = array( 'PO number', 'Supplier', 'Status', 'Outstanding', 'Expected date', 'Confidence', 'Delayed' );
		$last_pos       = -1;
		foreach ( $expected_order as $label ) {
			$pos = strpos( $header, $label );
			$this->assertNotFalse( $pos, "Drilldown header must contain the column \"$label\"." );
			$this->assertGreaterThan( $last_pos, $pos, "Column \"$label\" must appear after the previous column, per the BR-M16-8 fixed order." );
			$last_pos = $pos;
		}
	}

	/**
	 * The Supplier column displays the PO's own denormalized
	 * supplier_name_snapshot -- never a live Suppliers lookup -- and
	 * continues to display it unchanged even after the supplier is later
	 * archived (BR-M16-6).
	 */
	public function test_drilldown_supplier_column_shows_snapshot_and_survives_archive() {
		$supplier = $this->create_supplier( array( 'name' => 'M16 Snapshot Supplier' ) );
		$product  = $this->create_simple_product();
		// create_purchase_order() calls the raw repository create_draft(),
		// which (unlike WC_Inventory_Overview_PO_Service::create_draft())
		// does not auto-populate supplier_name_snapshot from the supplier
		// row -- pass it explicitly, matching the same pattern used by
		// tests/unit/purchase-orders/test-po-numbering.php and
		// test-po-architecture.php.
		$po = $this->create_purchase_order(
			array(
				'supplier_id'             => (int) $supplier['id'],
				'supplier_name_snapshot'  => 'M16 Snapshot Supplier',
			)
		);
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		WC_Inventory_Overview_Suppliers::archive( (int) $supplier['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringContainsString( 'M16 Snapshot Supplier', $html, 'Archived supplier\'s name must still render from the PO snapshot (BR-M16-6).' );
	}

	/**
	 * The Status column uses WC_Inventory_Overview_PO_Statuses::label() --
	 * the same shared label map used elsewhere in the codebase, never a
	 * locally re-derived label (BR-M16-7).
	 */
	public function test_drilldown_status_column_uses_shared_label() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$expected_label = WC_Inventory_Overview_PO_Statuses::label( WC_Inventory_Overview_PO_Statuses::PLACED );
		$this->assertStringContainsString( $expected_label, $html );
	}

	/**
	 * Low-stock badge remains visible alongside Incoming -- composable, not
	 * mutually exclusive states (D13/INV-5).
	 */
	public function test_low_stock_badge_visible_alongside_incoming() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringContainsString( 'wc-io-badge-low', $html );
		$this->assertStringContainsString( 'wc-io-incoming-qty', $html );
	}

	/**
	 * Rendering the list table has no write side effects (fully read-only).
	 */
	public function test_no_write_side_effects() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$po      = $this->create_purchase_order();
		$line    = $this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$this->render_table_html( $table );

		$fresh_product = wc_get_product( $product->get_id() );
		$this->assertEquals( 10, $fresh_product->get_stock_quantity() );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEqualsWithDelta( 4.0, (float) $fresh_line['qty_ordered'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh_line['qty_cancelled'], 0.0001 );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( $po['id'] );
		$this->assertSame( WC_Inventory_Overview_PO_Statuses::PLACED, $fresh_po['status'] );
	}

	/**
	 * Query-scaling guard (WP7): rendering 20+ mixed simple/variation rows
	 * with open incoming supply issues at most one product-scoped and one
	 * variation-scoped SELECT against the PO lines table.
	 */
	public function test_position_query_count_bounded_for_twenty_plus_rows() {
		for ( $i = 0; $i < 15; $i++ ) {
			$product = $this->create_simple_product();
			$po      = $this->create_purchase_order();
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
				)
			);
			$this->place_po( $po['id'] );
		}

		for ( $i = 0; $i < 6; $i++ ) {
			$variable = $this->create_variable_product( array(), array( array( 'name' => 'Variation ' . $i ) ) );
			$children = $this->variation_ids( $variable );
			$po       = $this->create_purchase_order();
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'   => $variable->get_id(),
					'variation_id' => (int) $children[0],
					'qty_ordered'  => 1,
				)
			);
			$this->place_po( $po['id'] );
		}

		$lines_table      = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$position_queries = array();
		$counter          = static function ( $query ) use ( $lines_table, &$position_queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$position_queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$table = $this->new_table();
		$table->prepare_items();
		remove_filter( 'query', $counter );

		$this->assertLessThanOrEqual(
			2,
			count( $position_queries ),
			'Inventory Overview must not issue per-row Position queries (no N+1)'
		);
	}
}
