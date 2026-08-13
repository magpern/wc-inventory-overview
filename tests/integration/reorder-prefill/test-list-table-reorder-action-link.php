<?php
/**
 * Integration tests for M22 WP-M22-4: the "Create Draft PO" quick-action
 * link on the Inventory Overview list table.
 *
 * Covers presence/href correctness for simple and variation needs_reorder
 * rows (BR-M22-1, BR-M22-3), EDIT_PO-gated visibility (BR-M22-2), absence
 * on covered_by_incoming and variable-parent rollup rows, and the hard
 * zero-new-query invariant (INV-M22-13, BR-M22-15).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_List_Table_Reorder_Action_Link extends WC_Inventory_Overview_Test_Case {

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

	public function tearDown(): void {
		remove_all_filters( 'wc_io_purchasing_capability_map' );
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	private function new_table(): WC_Inventory_Overview_List_Table {
		return new WC_Inventory_Overview_List_Table();
	}

	private function render_table_html( WC_Inventory_Overview_List_Table $table ): string {
		$table->prepare_items();
		ob_start();
		$table->display_rows();
		return (string) ob_get_clean();
	}

	private function row_html( string $html, int $product_id ): string {
		$marker = 'wc-io-parent-' . $product_id . '"';
		$start  = strpos( $html, $marker );
		$this->assertNotFalse( $start, 'Product ' . $product_id . '\'s own row must be present.' );
		$end = strpos( $html, '</tr>', $start );
		$this->assertNotFalse( $end );
		return substr( $html, $start, $end - $start );
	}

	private function set_view_po_only_user(): void {
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( array $map ): array {
				$map[ WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ] = 'wc_io_test_edit_po_cap_unassigned';
				return $map;
			}
		);
	}

	private function set_edit_products_only_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );
	}

	private function needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] ); // on_hand=2, incoming=1, position=3 <= threshold(5) => needs_reorder.

		return $product;
	}

	// ---------------------------------------------------------------
	// BR-M22-1, BR-M22-3, BR-M22-2: presence + correct href
	// ---------------------------------------------------------------

	public function test_link_present_with_correct_href_for_simple_product_needs_reorder_row() {
		$product = $this->needs_reorder_product();

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		$this->assertStringContainsString( 'wc-io-reorder-action', $row );
		$expected_href = WC_Inventory_Overview_PO_Admin::reorder_prefill_url( $product->get_id(), 0 );
		$this->assertStringContainsString( esc_url( $expected_href ), $row );
	}

	public function test_link_present_with_correct_href_for_variation_needs_reorder_row() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'A', 'stock_qty' => 1 ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$this->assertNotEmpty( $children );
		$variation_id = (int) $children[0];

		$v = wc_get_product( $variation_id );
		$v->set_low_stock_amount( 5 );
		$v->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => $variation_id, 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringContainsString( 'wc-io-reorder-action', $html );
		$expected_href = WC_Inventory_Overview_PO_Admin::reorder_prefill_url( $variable->get_id(), $variation_id );
		$this->assertStringContainsString( esc_url( $expected_href ), $html );
	}

	// ---------------------------------------------------------------
	// BR-M22-2 / INV-M22-14: EDIT_PO-gated, not merely manage_woocommerce
	// ---------------------------------------------------------------

	public function test_link_absent_for_view_po_only_viewer() {
		$product = $this->needs_reorder_product();
		$this->set_view_po_only_user();

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		// The badge itself is unaffected (it only requires manage_woocommerce).
		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $row );
		$this->assertStringNotContainsString( 'wc-io-reorder-action', $row );
	}

	public function test_link_absent_for_edit_products_only_viewer() {
		$product = $this->needs_reorder_product();
		$this->set_edit_products_only_user();

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );

		$this->assertStringNotContainsString( 'wc-io-reorder-action', $html );
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $html );
	}

	// ---------------------------------------------------------------
	// Absent on covered_by_incoming and variable-parent rollup rows
	// ---------------------------------------------------------------

	public function test_link_absent_on_covered_by_incoming_row() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] ); // position=12 > threshold(5) => covered_by_incoming.

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $product->get_id() );

		$this->assertStringContainsString( 'wc-io-badge-covered-incoming', $row );
		$this->assertStringNotContainsString( 'wc-io-reorder-action', $row );
	}

	public function test_link_absent_on_variable_parent_rollup_row() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'A', 'stock_qty' => 1 ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$v        = wc_get_product( $children[0] );
		$v->set_low_stock_amount( 5 );
		$v->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[0], 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] );

		$table = $this->new_table();
		$html  = $this->render_table_html( $table );
		$row   = $this->row_html( $html, $variable->get_id() );

		// Parent row shows the rollup child-badge, but never its own action link.
		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $row );
		$this->assertStringNotContainsString( 'wc-io-reorder-action', $row );
	}

	// ---------------------------------------------------------------
	// INV-M22-13 / BR-M22-15: zero new queries, fixed bound at scale
	// ---------------------------------------------------------------

	private function count_all_queries_for_prepare( int $n ): int {
		for ( $i = 0; $i < $n; $i++ ) {
			$this->needs_reorder_product();
		}

		$queries = array();
		$counter = static function ( $query ) use ( &$queries ) {
			if ( false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$table = $this->new_table();
		$table->prepare_items();
		remove_filter( 'query', $counter );

		return count( $queries );
	}

	/**
	 * The M22 link is derived entirely from already-loaded WC_Product
	 * objects and the already-computed needs_reorder boolean -- it must
	 * add zero SQL of its own. Query count at 5 vs. 60 needs-reorder rows
	 * must stay within the same small, fixed bound M21 already
	 * established (never a function of row count).
	 */
	public function test_query_count_unchanged_at_5_and_60_product_scale() {
		$n_at_5 = $this->count_all_queries_for_prepare( 5 );

		$this->purge_po_tables();

		$n_at_60 = $this->count_all_queries_for_prepare( 60 );

		// Bounded, not per-row: the M21 Position/Summary bulk calls already
		// establish a small fixed ceiling (see test-inventory-position-list-table.php's
		// own bound of <=4 for the Position-repository queries alone); this
		// asserts the *total* SELECT count for prepare_items() stays in the
		// same fixed neighborhood at 5 vs. 60 rows, proving M22 adds no
		// per-row query of its own.
		$this->assertLessThanOrEqual( $n_at_5 + 2, $n_at_60, 'Total query count must not grow materially between 5 and 60 needs-reorder rows (INV-M22-13).' );
	}
}
