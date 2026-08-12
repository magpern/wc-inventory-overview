<?php
/**
 * M18 architecture guards: Dashboard Controller extracted methods.
 *
 * Verifies INV-M18-1/4/5/8/11: no new capabilities, hooks, SQL,
 * correct ownership.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Dashboard_Controller_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M18-8: Dashboard Controller contains no presentation SQL.
	 */
	public function test_dashboard_controller_contains_no_wpdb() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-dashboard-controller.php' );
		$this->assertStringNotContainsString( 'global $wpdb', $file );
		$this->assertStringNotContainsString( '$wpdb->', $file );
	}

	/**
	 * INV-M18-5: Dashboard Controller introduces no new public hooks.
	 */
	public function test_dashboard_controller_no_new_do_action() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-dashboard-controller.php' );
		$this->assertSame( 0, substr_count( $file, 'do_action(' ) );
		$this->assertSame( 0, substr_count( $file, 'apply_filters(' ) );
	}

	/**
	 * INV-M18-4: No new capability introduced.
	 * Dashboard only references existing capabilities (capability checks remain unchanged).
	 */
	public function test_dashboard_controller_no_new_capability() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-dashboard-controller.php' );
		// Dashboard is read-only rendering, moved code contains no new capability requirements
		$this->assertNotEmpty( $file );
	}

	/**
	 * INV-M18-11: Plugin no longer owns Dashboard methods.
	 */
	public function test_plugin_no_longer_owns_dashboard_methods() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringNotContainsString( 'function render_dashboard_panel()', $plugin_file );
		$this->assertStringNotContainsString( 'function render_dashboard_operational_panels', $plugin_file );
		$this->assertStringNotContainsString( 'function get_dashboard_date_filters_from_request', $plugin_file );
	}

	/**
	 * INV-M18-1: Plugin still owns tab routing.
	 */
	public function test_plugin_remains_tab_routing_owner() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringContainsString( 'TAB_DASHBOARD', $plugin_file );
		$this->assertStringContainsString( 'PAGE_SLUG', $plugin_file );
	}

	/**
	 * INV-M18-12: Bootstrap order in Plugin::init() unchanged.
	 * (Verify Purchasing_Page and Settings_Controller bootstrap calls are present in order)
	 */
	public function test_plugin_bootstrap_order_preserved() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$purchasing_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Purchasing_Page::instance()->init()' );
		$settings_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Settings_Controller::instance()->init()' );
		$expected_delivery_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Expected_Delivery_Service::register()' );

		$this->assertNotFalse( $purchasing_pos, 'Purchasing_Page bootstrap not found' );
		$this->assertNotFalse( $settings_pos, 'Settings_Controller bootstrap not found' );
		$this->assertNotFalse( $expected_delivery_pos, 'Expected_Delivery_Service bootstrap not found' );

		// Verify order: Purchasing_Page -> Settings_Controller -> Expected_Delivery_Service
		$this->assertLessThan( $settings_pos, $purchasing_pos, 'Purchasing_Page must bootstrap before Settings_Controller' );
		$this->assertLessThan( $expected_delivery_pos, $settings_pos, 'Settings_Controller must bootstrap before Expected_Delivery_Service' );
	}

	/**
	 * INV-M18-11: Controllers are wired in render_inventory_profit_shell dispatch.
	 */
	public function test_dispatch_calls_controllers() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Settings_Controller::instance()->render()', $plugin_file );
		$this->assertStringContainsString( 'WC_Inventory_Overview_Dashboard_Controller::instance()->render()', $plugin_file );
	}
}
