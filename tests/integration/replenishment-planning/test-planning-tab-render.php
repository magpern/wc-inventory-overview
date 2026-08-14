<?php
/**
 * M24 WP-M24-6: Planning tab rendering (§10/§10.4/§21).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Planning_Tab_Render extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function render(): string {
		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		return ob_get_clean();
	}

	public function test_renders_supplier_group_and_line_fields() {
		$supplier = $this->create_supplier( array( 'name' => 'Render Test Supplier', 'default_currency' => 'USD' ) );
		$product  = $this->create_simple_product( array( 'name' => 'Render Test Product', 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->set_sku( 'RENDER-SKU-1' );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '9' );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringContainsString( 'Render Test Supplier', $output );
		$this->assertStringContainsString( 'USD', $output );
		$this->assertStringContainsString( 'Render Test Product', $output );
		$this->assertStringContainsString( 'RENDER-SKU-1', $output );
	}

	public function test_no_commit_or_create_button_anywhere() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringNotContainsString( 'Create Draft', $output );
		$this->assertStringNotContainsString( 'wc_io_po_create', $output );
		$this->assertStringNotContainsString( '<form', $output, 'M24 is read-only -- no mutating form anywhere on the Planning tab.' );
	}

	/**
	 * §10.4: a stale-preferred-supplier line renders with an explicit badge.
	 */
	public function test_stale_preferred_supplier_badge_rendered() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$stale_preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $stale_preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $stale_preferred['id'] );

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed', 'order_date' => '2026-01-01' ) );

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringContainsString( 'Preferred supplier unavailable', $output );
	}

	public function test_unresolved_section_rendered_with_reason() {
		$product = $this->create_simple_product( array( 'name' => 'No Supplier Product', 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringContainsString( 'Unresolved', $output );
		$this->assertStringContainsString( 'No Supplier Product', $output );
	}

	public function test_empty_plan_renders_no_items_message() {
		$_GET['tab'] = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$output      = $this->render();

		$this->assertStringContainsString( 'No items currently need reordering', $output );
	}

	/**
	 * §10.2: wc_io_plan_skipped notice renders when redirected from the
	 * bulk action with a nonzero skip count.
	 */
	public function test_skipped_notice_rendered() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$_GET['tab']               = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET['item_ids']          = (string) $product->get_id();
		$_GET['wc_io_plan_skipped'] = '2';
		$output                    = $this->render();

		$this->assertStringContainsString( 'were skipped because they are variable parent products', $output );
	}

	public function test_scoped_plan_via_item_ids_query_arg() {
		$in_scope = $this->create_simple_product( array( 'name' => 'In Scope', 'stock_qty' => 1 ) );
		$in_scope->set_low_stock_amount( 5 );
		$in_scope->save();

		$out_of_scope = $this->create_simple_product( array( 'name' => 'Out Of Scope', 'stock_qty' => 1 ) );
		$out_of_scope->set_low_stock_amount( 5 );
		$out_of_scope->save();

		$_GET['tab']      = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET['item_ids'] = (string) $in_scope->get_id();
		$output           = $this->render();

		$this->assertStringContainsString( 'In Scope', $output );
		$this->assertStringNotContainsString( 'Out Of Scope', $output );
	}

	/**
	 * Malformed item_ids (non-numeric fragments) never fatal -- silently
	 * dropped.
	 */
	public function test_malformed_item_ids_never_fatal() {
		$_GET['tab']      = WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING;
		$_GET['item_ids'] = 'not-a-number,,;DROP TABLE wp_posts;--';

		$output = $this->render();
		$this->assertStringContainsString( 'Replenishment Planning', $output );
	}
}
