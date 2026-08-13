<?php
/**
 * Integration tests for M21 List_Table row-level Reorder Signal integration
 * (WP-M21-2/WP-M21-3): badges, row CSS state, capability gating, and the
 * variable-parent rollup counter.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Signal_List_Table extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

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
	 * @param WC_Inventory_Overview_List_Table $table Table.
	 */
	private function render_table_html( WC_Inventory_Overview_List_Table $table ): string {
		$table->prepare_items();
		ob_start();
		$table->display_rows();
		return (string) ob_get_clean();
	}

	private function set_edit_products_only_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );
	}

	/**
	 * Scope HTML assertions to one product's own parent-row fragment. Other
	 * tests' products remain present in the same rendered page (dbDelta()'s
	 * implicit commit breaks per-test transaction rollback in this suite --
	 * a pre-existing quirk, see test-inventory-position-list-table.php's
	 * test_drilldown_column_order_is_fixed_per_br_m16_8), so an unscoped
	 * assertStringNotContainsString() on the whole page can false-fail
	 * against a different test's row.
	 *
	 * @param string $html       Full rendered HTML.
	 * @param int    $product_id Product ID.
	 */
	private function row_html( string $html, int $product_id ): string {
		$marker = 'wc-io-parent-' . $product_id . '"';
		$start  = strpos( $html, $marker );
		$this->assertNotFalse( $start, 'Product ' . $product_id . '\'s own row must be present.' );
		$end = strpos( $html, '</tr>', $start );
		$this->assertNotFalse( $end );
		return substr( $html, $start, $end - $start );
	}

	/**
	 * @param WC_Product_Variable $parent Variable product.
	 * @return int[]
	 */
	private function variation_ids( WC_Product_Variable $parent ): array {
		wc_delete_product_transients( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		return $fresh instanceof WC_Product_Variable ? $fresh->get_children() : array();
	}

	// -----------------------------------------------------------------
	// WP-M21-2: simple-product row badges + row CSS state (BR-M21-1..4)
	// -----------------------------------------------------------------

	/**
	 * Low stock, insufficient incoming (position <= threshold): "Needs
	 * reorder" badge renders alongside the existing "Low stock" badge (D13),
	 * and the row-state class is added, for a manage_woocommerce viewer.
	 */
	public function test_needs_reorder_badge_and_row_class_for_manage_woocommerce() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );
		// on_hand=2, incoming=1, position=3 <= threshold(5) => needs_reorder.

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		$this->assertStringContainsString( 'wc-io-badge-low', $row, 'Existing Low stock badge must remain (D13).' );
		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $row );
		$this->assertStringNotContainsString( 'wc-io-badge-covered-incoming', $row );
	}

	/**
	 * Low stock, but incoming already covers it (position > threshold):
	 * "Covered by incoming" badge renders alongside the existing "Low stock"
	 * badge, never "Needs reorder".
	 */
	public function test_covered_by_incoming_badge_for_manage_woocommerce() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );
		// on_hand=2, incoming=10, position=12 > threshold(5) => covered_by_incoming.

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		$this->assertStringContainsString( 'wc-io-badge-low', $row );
		$this->assertStringContainsString( 'wc-io-badge-covered-incoming', $row );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $row );
	}

	/**
	 * edit_products-only viewers see exactly today's unchanged output: the
	 * existing Low stock badge, but no Reorder Signal badge at all
	 * (BR-M21-4, INV-M21-5) -- position_map is empty for them.
	 */
	public function test_edit_products_only_viewer_sees_no_reorder_signal() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$this->set_edit_products_only_user();

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertSame( array(), $table->position_map );
		$this->assertStringContainsString( 'wc-io-badge-low', $html, 'Pre-M21 Low stock badge must be unchanged.' );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $html );
		$this->assertStringNotContainsString( 'wc-io-badge-covered-incoming', $html );
	}

	/**
	 * A not-low-stock item never shows a Reorder Signal badge, regardless of
	 * capability or incoming supply (BR-M21-1).
	 */
	public function test_not_low_stock_item_shows_no_reorder_signal() {
		$product = $this->create_simple_product( array( 'stock_qty' => 50 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		$this->assertStringNotContainsString( 'wc-io-badge-low', $row );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $row );
		$this->assertStringNotContainsString( 'wc-io-badge-covered-incoming', $row );
	}

	/**
	 * Row-state class wc-io-needs-reorder is present for a needs-reorder row
	 * (manage_woocommerce), absent for edit_products-only viewers.
	 */
	public function test_row_state_class_gated_by_capability() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$this->assertStringContainsString( 'wc-io-needs-reorder', $html );

		$this->set_edit_products_only_user();
		$table2 = $this->new_table();
		$html2  = $this->render_table_html( $table2 );
		$this->assertStringNotContainsString( 'wc-io-needs-reorder', $html2 );
	}

	// -----------------------------------------------------------------
	// WP-M21-3: variable-parent rollup (BR-M21-5, INV-M21-7)
	// -----------------------------------------------------------------

	/**
	 * compute_variable_aggregate() gains an n_in_needs_reorder counter,
	 * parallel to the existing n_in_low counter, counting only children
	 * individually classified needs_reorder=true -- never a parent-level
	 * Position/threshold comparison (INV-8/INV-M21-7).
	 */
	public function test_variable_parent_rollup_counts_needs_reorder_children() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'A', 'stock_qty' => 1 ), // low, will need reorder (small incoming).
				array( 'name' => 'B', 'stock_qty' => 1 ), // low, will be covered (large incoming).
				array( 'name' => 'C', 'stock_qty' => 50 ), // not low at all.
			)
		);
		$children = $this->variation_ids( $variable );
		$this->assertGreaterThanOrEqual( 3, count( $children ) );

		foreach ( $children as $vid ) {
			$v = wc_get_product( $vid );
			$v->set_low_stock_amount( 5 );
			$v->save();
		}

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[0], 'qty_ordered' => 1 ) ); // position 1+1=2 <=5 needs reorder.
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[1], 'qty_ordered' => 10 ) ); // position 1+10=11 >5 covered.
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

		$this->assertArrayHasKey( 'n_in_needs_reorder', $agg );
		$this->assertSame( 1, $agg['n_in_needs_reorder'] );
	}

	/**
	 * The parent-row rendered HTML includes a "Needs reorder" child-badge
	 * (parallel to the existing wc-io-badge-low-child) when at least one
	 * child needs reorder, for a manage_woocommerce viewer.
	 */
	public function test_variable_parent_row_shows_needs_reorder_child_badge() {
		$variable = $this->create_variable_product(
			array(),
			array( array( 'name' => 'A', 'stock_qty' => 1 ) )
		);
		$children = $this->variation_ids( $variable );
		$v        = wc_get_product( $children[0] );
		$v->set_low_stock_amount( 5 );
		$v->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[0], 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $html );
	}

	/**
	 * Rendering the list table (with Reorder Signal computed) has no write
	 * side effects (fully read-only, INV-M21-1).
	 */
	public function test_no_write_side_effects() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$this->render_table_html( $table );

		$fresh_product = wc_get_product( $product->get_id() );
		$this->assertEquals( 2, $fresh_product->get_stock_quantity() );
	}
}
