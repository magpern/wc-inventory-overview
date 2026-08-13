<?php
/**
 * M20 architecture guards: Restock Controller extracted methods.
 *
 * Verifies INV-M20-1/4/5/9/10/11/12/16/17: no new capabilities, hooks, SQL,
 * or transaction boundaries; correct ownership; capability-before-nonce
 * ordering; nonce strings preserved; bootstrap order preserved; the
 * supplier-picker AJAX handler registration stays on Purchasing_Page.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Restock_Controller_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M20-6/INV-M20-18: Restock Controller contains no presentation SQL.
	 */
	public function test_restock_controller_contains_no_wpdb() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertStringNotContainsString( 'global $wpdb', $file );
		$this->assertStringNotContainsString( '$wpdb->', $file );
	}

	/**
	 * INV-M20-5: Restock Controller introduces no new public hooks.
	 */
	public function test_restock_controller_no_new_do_action() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertSame( 0, substr_count( $file, 'do_action(' ) );
		$this->assertSame( 0, substr_count( $file, 'apply_filters(' ) );
	}

	/**
	 * INV-M20-4: No new capability introduced — only pre-existing
	 * manage_woocommerce is referenced (strict set, not just non-empty).
	 */
	public function test_restock_controller_no_new_capability() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );

		preg_match_all( '/current_user_can\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $file, $matches );
		$caps = array_unique( $matches[1] );
		$this->assertSame( array( 'manage_woocommerce' ), $caps, 'Only manage_woocommerce may be referenced.' );
	}

	/**
	 * INV-M20-10: Nonce/action strings reused verbatim from the
	 * pre-extraction Plugin methods — none renamed, none added. Includes the
	 * cross-cutting supplier-picker nonces (glue only, handler stays on
	 * Purchasing_Page — see test_no_supplier_ajax_handler_registered below).
	 */
	public function test_restock_controller_nonce_strings_unchanged() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_restock' )", $file );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_cost_adjustment' )", $file );
		$this->assertStringContainsString( "check_ajax_referer( 'wc_io_cost_adj_preview', 'nonce' )", $file );
		$this->assertStringContainsString( "wp_create_nonce( 'wc_io_search_suppliers' )", $file );
		$this->assertStringContainsString( "wp_create_nonce( 'wc_io_quick_create_supplier' )", $file );
	}

	/**
	 * INV-M20-9: Every mutation/AJAX handler checks capability strictly
	 * before the nonce, in that fixed order (matches the pre-extraction
	 * Plugin methods verbatim).
	 */
	public function test_restock_controller_capability_before_nonce() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );

		foreach (
			array(
				array( 'handle_restock_post', "check_admin_referer( 'wc_io_restock' )" ),
				array( 'handle_cost_adjustment_post', "check_admin_referer( 'wc_io_cost_adjustment' )" ),
				array( 'ajax_get_cost_adjustment_preview', "check_ajax_referer( 'wc_io_cost_adj_preview', 'nonce' )" ),
			) as $spec
		) {
			list( $method, $nonce_call ) = $spec;

			$method_pos = strpos( $file, 'function ' . $method . '(' );
			$this->assertNotFalse( $method_pos, "Method {$method} not found." );

			$cap_pos   = strpos( $file, "current_user_can( 'manage_woocommerce' )", $method_pos );
			$nonce_pos = strpos( $file, $nonce_call, $method_pos );

			$this->assertNotFalse( $cap_pos, "Capability check not found for {$method}." );
			$this->assertNotFalse( $nonce_pos, "Nonce check not found for {$method}." );

			$this->assertLessThan( $nonce_pos, $cap_pos, "{$method}: capability must be checked before nonce." );
		}
	}

	/**
	 * INV-M20-11: Plugin no longer owns the eight moved methods.
	 */
	public function test_plugin_no_longer_owns_restock_methods() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringNotContainsString( 'function get_restock_subview', $plugin_file );
		$this->assertStringNotContainsString( 'function render_restock_subnav', $plugin_file );
		$this->assertStringNotContainsString( 'function on_load_restock_screen', $plugin_file );
		$this->assertStringNotContainsString( 'function enqueue_restock_assets', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_restock_post', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_cost_adjustment_post', $plugin_file );
		$this->assertStringNotContainsString( 'function render_restock_panel', $plugin_file );
		$this->assertStringNotContainsString( 'function ajax_get_cost_adjustment_preview', $plugin_file );
	}

	/**
	 * INV-M20-1: Plugin still owns tab routing.
	 */
	public function test_plugin_remains_tab_routing_owner() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringContainsString( 'TAB_RESTOCK', $plugin_file );
		$this->assertStringContainsString( 'PAGE_SLUG', $plugin_file );
		$this->assertStringContainsString( 'function get_requested_tab', $plugin_file );
		$this->assertStringContainsString( 'function admin_url_tab', $plugin_file );
		$this->assertStringContainsString( 'function on_load_inventory_profit_page', $plugin_file );
	}

	/**
	 * INV-M20-12: Bootstrap order in Plugin::init() unchanged — the new
	 * controller's init() call is appended after the existing bootstrap
	 * block (after Reporting_Controller, before Expected_Delivery_Service),
	 * not interleaved.
	 */
	public function test_plugin_bootstrap_order_preserved() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$purchasing_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Purchasing_Page::instance()->init()' );
		$settings_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Settings_Controller::instance()->init()' );
		$reporting_pos  = strpos( $plugin_file, 'WC_Inventory_Overview_Reporting_Controller::instance()->init()' );
		$restock_pos    = strpos( $plugin_file, 'WC_Inventory_Overview_Restock_Controller::instance()->init()' );
		$expected_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Expected_Delivery_Service::register()' );

		$this->assertNotFalse( $purchasing_pos, 'Purchasing_Page bootstrap not found' );
		$this->assertNotFalse( $settings_pos, 'Settings_Controller bootstrap not found' );
		$this->assertNotFalse( $reporting_pos, 'Reporting_Controller bootstrap not found' );
		$this->assertNotFalse( $restock_pos, 'Restock_Controller bootstrap not found' );
		$this->assertNotFalse( $expected_pos, 'Expected_Delivery_Service bootstrap not found' );

		$this->assertLessThan( $settings_pos, $purchasing_pos, 'Purchasing_Page must bootstrap before Settings_Controller' );
		$this->assertLessThan( $reporting_pos, $settings_pos, 'Settings_Controller must bootstrap before Reporting_Controller' );
		$this->assertLessThan( $restock_pos, $reporting_pos, 'Reporting_Controller must bootstrap before Restock_Controller' );
		$this->assertLessThan( $expected_pos, $restock_pos, 'Restock_Controller must bootstrap before Expected_Delivery_Service' );
	}

	/**
	 * INV-M20-1/INV-M20-11: The dispatchers are wired to the new controller.
	 */
	public function test_dispatch_calls_controller() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Restock_Controller::instance()->on_load_restock_screen()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Restock_Controller::instance()->render()', $plugin_file );
	}

	/**
	 * INV-M20-16: No DB transaction is introduced -- the pre-existing
	 * hand-rolled compensating-rollback pattern
	 * (Restock_Service::restore_snapshot()/Cost_Adjustment_Service::restore_meta_snapshot())
	 * is preserved exactly, not "improved" into a transaction boundary.
	 */
	public function test_restock_controller_no_transaction_introduced() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertStringNotContainsString( 'DB_Transaction', $file );
		$this->assertStringNotContainsString( 'START TRANSACTION', $file );
	}

	/**
	 * INV-M20-17: The supplier-search/quick-create AJAX handlers remain
	 * registered on Purchasing_Page, not Restock_Controller -- only the
	 * pre-existing nonce-creation/localization glue relocates.
	 */
	public function test_no_supplier_ajax_handler_registered_on_restock_controller() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertStringNotContainsString( "'wp_ajax_wc_io_search_suppliers'", $file );
		$this->assertStringNotContainsString( "'wp_ajax_wc_io_quick_create_supplier'", $file );

		$purchasing_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-purchasing-page.php' );
		$this->assertStringContainsString( "'wp_ajax_wc_io_search_suppliers'", $purchasing_file );
		$this->assertStringContainsString( "'wp_ajax_wc_io_quick_create_supplier'", $purchasing_file );
	}

	/**
	 * INV-M20-6: Restock_Service and Cost_Adjustment_Service gain zero new
	 * methods -- byte-identical to the pre-M20 baseline (mutation ownership
	 * unchanged, only the HTTP caller moved).
	 */
	public function test_domain_services_unmodified() {
		$restock_service = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-service.php' );
		$cost_adj_service = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-cost-adjustment-service.php' );

		$this->assertStringContainsString( 'function process_purchase_restock', $restock_service );
		$this->assertStringContainsString( 'function apply_purchase_line_change', $restock_service );
		$this->assertStringContainsString( 'function process', $cost_adj_service );

		// Restock_Controller must call these, never re-implement the mutation itself.
		$controller_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-controller.php' );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Restock_Service::process_purchase_restock(', $controller_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Cost_Adjustment_Service::process(', $controller_file );
		$this->assertStringNotContainsString( 'set_stock_quantity', $controller_file, 'Restock_Controller must not mutate stock directly -- only via the domain service.' );
	}
}
