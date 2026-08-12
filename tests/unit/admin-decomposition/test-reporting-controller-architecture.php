<?php
/**
 * M19 architecture guards: Reporting Controller extracted methods.
 *
 * Verifies INV-M19-1/5/8/9/10/11/12: no new capabilities, hooks, SQL,
 * correct ownership, capability-before-nonce ordering, nonce strings
 * preserved, bootstrap order preserved.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reporting_Controller_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M19-8: Reporting Controller contains no presentation SQL.
	 */
	public function test_reporting_controller_contains_no_wpdb() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );
		$this->assertStringNotContainsString( 'global $wpdb', $file );
		$this->assertStringNotContainsString( '$wpdb->', $file );
	}

	/**
	 * INV-M19-5: Reporting Controller introduces no new public hooks.
	 */
	public function test_reporting_controller_no_new_do_action() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );
		$this->assertSame( 0, substr_count( $file, 'do_action(' ) );
		$this->assertSame( 0, substr_count( $file, 'apply_filters(' ) );
	}

	/**
	 * INV-M19-4: No new capability introduced — only pre-existing
	 * manage_woocommerce is referenced.
	 */
	public function test_reporting_controller_no_new_capability() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );
		$this->assertStringContainsString( "current_user_can( 'manage_woocommerce' )", $file );

		preg_match_all( '/current_user_can\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $file, $matches );
		$caps = array_unique( $matches[1] );
		$this->assertSame( array( 'manage_woocommerce' ), $caps, 'Only manage_woocommerce may be referenced.' );
	}

	/**
	 * INV-M19-10: Nonce/action strings reused verbatim from the pre-extraction
	 * Plugin methods — none renamed, none added.
	 */
	public function test_reporting_controller_nonce_strings_unchanged() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_movements_export_csv', '_wc_io_mv_export_nonce' )", $file );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_order_profit_export_csv', '_wc_io_op_export_nonce' )", $file );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_product_profitability_export_csv', '_wc_io_pp_export_nonce' )", $file );
	}

	/**
	 * INV-M19-9: Every export method checks capability before the
	 * export-param condition and the nonce, in that fixed order (matches
	 * the pre-extraction Plugin methods verbatim).
	 */
	public function test_reporting_controller_capability_before_nonce() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );

		foreach (
			array(
				array( 'export_movements_csv', 'wc_io_mv_export', 'wc_io_movements_export_csv' ),
				array( 'export_order_profit_csv', 'wc_io_op_export', 'wc_io_order_profit_export_csv' ),
				array( 'export_product_profitability_csv', 'wc_io_pp_export', 'wc_io_product_profitability_export_csv' ),
			) as $spec
		) {
			list( $method, $param, $nonce_action ) = $spec;

			$method_pos = strpos( $file, 'function ' . $method . '(' );
			$this->assertNotFalse( $method_pos, "Method {$method} not found." );

			$cap_pos   = strpos( $file, "current_user_can( 'manage_woocommerce' )", $method_pos );
			$param_pos = strpos( $file, "\$_GET['{$param}']", $method_pos );
			$nonce_pos = strpos( $file, "check_admin_referer( '{$nonce_action}'", $method_pos );

			$this->assertNotFalse( $cap_pos, "Capability check not found for {$method}." );
			$this->assertNotFalse( $param_pos, "Export param check not found for {$method}." );
			$this->assertNotFalse( $nonce_pos, "Nonce check not found for {$method}." );

			$this->assertLessThan( $param_pos, $cap_pos, "{$method}: capability must be checked before export param." );
			$this->assertLessThan( $nonce_pos, $param_pos, "{$method}: export param must be checked before nonce." );
		}
	}

	/**
	 * INV-M19-11: Plugin no longer owns the nine moved methods or the
	 * three top-level screen-option filters.
	 */
	public function test_plugin_no_longer_owns_reporting_methods() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringNotContainsString( 'function on_load_movements_screen', $plugin_file );
		$this->assertStringNotContainsString( 'function on_load_order_profit_screen', $plugin_file );
		$this->assertStringNotContainsString( 'function on_load_product_profitability_screen', $plugin_file );
		$this->assertStringNotContainsString( 'function render_movements_panel', $plugin_file );
		$this->assertStringNotContainsString( 'function render_order_profit_panel', $plugin_file );
		$this->assertStringNotContainsString( 'function render_product_profitability_panel', $plugin_file );
		$this->assertStringNotContainsString( 'function maybe_export_movements_csv', $plugin_file );
		$this->assertStringNotContainsString( 'function maybe_export_order_profit_csv', $plugin_file );
		$this->assertStringNotContainsString( 'function maybe_export_product_profitability_csv', $plugin_file );
		$this->assertStringNotContainsString( 'set_screen_option_wc_io_movements_per_page', $plugin_file );
		$this->assertStringNotContainsString( 'set_screen_option_wc_io_order_profit_per_page', $plugin_file );
		$this->assertStringNotContainsString( 'set_screen_option_wc_io_product_profitability_per_page', $plugin_file );
	}

	/**
	 * INV-M19-13: Overview and Restock methods are untouched — still owned
	 * by Plugin, not moved or renamed.
	 */
	public function test_overview_and_restock_remain_on_plugin() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringContainsString( 'function render_inventory_overview_panel', $plugin_file );
		$this->assertStringContainsString( 'function maybe_export_csv', $plugin_file );
		$this->assertStringContainsString( 'function maybe_handle_bulk', $plugin_file );
		$this->assertStringContainsString( 'function ajax_save_inline_stock', $plugin_file );
		$this->assertStringContainsString( 'function render_summary_cards', $plugin_file );

		$this->assertStringContainsString( 'function render_restock_panel', $plugin_file );
		$this->assertStringContainsString( 'function handle_restock_post', $plugin_file );
		$this->assertStringContainsString( 'function handle_cost_adjustment_post', $plugin_file );
		$this->assertStringContainsString( 'function ajax_get_cost_adjustment_preview', $plugin_file );
		$this->assertStringContainsString( 'function enqueue_restock_assets', $plugin_file );
	}

	/**
	 * INV-M19-1: Plugin still owns tab routing.
	 */
	public function test_plugin_remains_tab_routing_owner() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringContainsString( 'TAB_MOVEMENTS', $plugin_file );
		$this->assertStringContainsString( 'TAB_ORDER_PROFIT', $plugin_file );
		$this->assertStringContainsString( 'TAB_PRODUCT_PROFITABILITY', $plugin_file );
		$this->assertStringContainsString( 'function get_requested_tab', $plugin_file );
		$this->assertStringContainsString( 'function on_load_inventory_profit_page', $plugin_file );
	}

	/**
	 * INV-M19-12: Bootstrap order in Plugin::init() unchanged — the new
	 * controller's init() call is appended after the existing bootstrap
	 * block, not interleaved.
	 */
	public function test_plugin_bootstrap_order_preserved() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$purchasing_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Purchasing_Page::instance()->init()' );
		$settings_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Settings_Controller::instance()->init()' );
		$reporting_pos  = strpos( $plugin_file, 'WC_Inventory_Overview_Reporting_Controller::instance()->init()' );
		$expected_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Expected_Delivery_Service::register()' );

		$this->assertNotFalse( $purchasing_pos, 'Purchasing_Page bootstrap not found' );
		$this->assertNotFalse( $settings_pos, 'Settings_Controller bootstrap not found' );
		$this->assertNotFalse( $reporting_pos, 'Reporting_Controller bootstrap not found' );
		$this->assertNotFalse( $expected_pos, 'Expected_Delivery_Service bootstrap not found' );

		$this->assertLessThan( $settings_pos, $purchasing_pos, 'Purchasing_Page must bootstrap before Settings_Controller' );
		$this->assertLessThan( $reporting_pos, $settings_pos, 'Settings_Controller must bootstrap before Reporting_Controller' );
		$this->assertLessThan( $expected_pos, $reporting_pos, 'Reporting_Controller must bootstrap before Expected_Delivery_Service' );
	}

	/**
	 * INV-M19-11: The dispatchers are wired to the new controller.
	 */
	public function test_dispatch_calls_controller() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->on_load_product_profitability()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->render_movements()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->render_order_profit()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Reporting_Controller::instance()->render_product_profitability()', $plugin_file );
	}

	/**
	 * INV-M19-1 (enqueue split, §8 of the plan): Plugin::enqueue_assets()
	 * no longer references TAB_MOVEMENTS or the movements-table script;
	 * that ownership moved to Reporting_Controller::enqueue_reporting_assets().
	 */
	public function test_enqueue_assets_no_longer_owns_movements_branch() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$enqueue_pos = strpos( $plugin_file, 'function enqueue_assets(' );
		$this->assertNotFalse( $enqueue_pos, 'enqueue_assets() not found.' );
		$next_method_pos = strpos( $plugin_file, "\n\t/**", $enqueue_pos + 10 );
		$enqueue_body     = substr( $plugin_file, $enqueue_pos, ( $next_method_pos ?: strlen( $plugin_file ) ) - $enqueue_pos );

		$this->assertStringNotContainsString( 'TAB_MOVEMENTS', $enqueue_body );
		$this->assertStringNotContainsString( 'wc-io-movements-table', $enqueue_body );

		$reporting_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-reporting-controller.php' );
		$this->assertStringContainsString( 'wc-io-movements-table', $reporting_file );
		$this->assertStringContainsString( 'function enqueue_reporting_assets', $reporting_file );
	}
}
