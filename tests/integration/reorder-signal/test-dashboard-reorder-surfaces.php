<?php
/**
 * Integration tests for M21 Dashboard Reorder Signal surfaces (WP-M21-5):
 * "Needs Reorder" KPI card and the "Recent Low Stock Items" table's two new
 * columns -- both capability-gated (BR-M21-9, BR-M21-10, INV-M21-5).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Dashboard_Reorder_Surfaces extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$_GET     = array();
		$_REQUEST = array();
	}

	public function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
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

	private function set_manage_woocommerce_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function set_edit_products_only_user(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );
	}

	private function render_dashboard(): string {
		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		WC_Inventory_Overview_Plugin::instance()->render_inventory_profit_shell();
		return (string) ob_get_clean();
	}

	/**
	 * manage_woocommerce viewer sees the "Needs Reorder" KPI card and the
	 * two new "Recent Low Stock Items" columns.
	 */
	public function test_manage_woocommerce_viewer_sees_needs_reorder_surfaces() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$this->set_manage_woocommerce_user();
		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'data-wc-io-kpi="needs_reorder"', $html );
		$this->assertStringContainsString( 'column-incoming', $html );
		$this->assertStringContainsString( 'column-reorder', $html );
		$this->assertStringContainsString( 'Needs reorder', $html );
	}

	/**
	 * A low-stock item covered by incoming supply shows "Covered by
	 * incoming" in the Reorder status column, not "Needs reorder".
	 */
	public function test_covered_by_incoming_row_shows_covered_status() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$this->set_manage_woocommerce_user();
		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'Covered by incoming', $html );
	}

	/**
	 * edit_products-only viewers see exactly today's unchanged Dashboard:
	 * no "Needs Reorder" KPI, no new table columns (BR-M21-9, BR-M21-10,
	 * INV-M21-5) -- the existing Low Stock Items KPI and 5-column table
	 * remain present, unchanged.
	 */
	public function test_edit_products_only_viewer_sees_no_reorder_signal_surfaces() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$this->set_edit_products_only_user();
		$html = $this->render_dashboard();

		$this->assertStringContainsString( 'data-wc-io-kpi="low_stock"', $html, 'Pre-M21 Low Stock Items KPI must be unchanged.' );
		$this->assertStringNotContainsString( 'data-wc-io-kpi="needs_reorder"', $html );
		$this->assertStringNotContainsString( 'column-incoming', $html );
		$this->assertStringNotContainsString( 'column-reorder', $html );
	}

	/**
	 * No write side effects from rendering the Dashboard (INV-M21-1).
	 */
	public function test_no_write_side_effects() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$this->set_manage_woocommerce_user();
		$this->render_dashboard();

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEquals( 1, $fresh->get_stock_quantity() );
	}
}
