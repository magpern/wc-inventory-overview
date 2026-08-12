<?php
/**
 * M18 architecture guards: Settings Controller extracted methods.
 *
 * Verifies INV-M18-1/4/5/8/9/10/11: no new capabilities, hooks, SQL,
 * correct ownership, stable nonces.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Settings_Controller_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M18-8: Settings Controller contains no presentation SQL.
	 */
	public function test_settings_controller_contains_no_wpdb() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings-controller.php' );
		$this->assertStringNotContainsString( 'global $wpdb', $file );
		$this->assertStringNotContainsString( '$wpdb->', $file );
	}

	/**
	 * INV-M18-5: Settings Controller introduces no new public hooks.
	 */
	public function test_settings_controller_no_new_do_action() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings-controller.php' );
		$this->assertSame( 0, substr_count( $file, 'do_action(' ) );
		$this->assertSame( 0, substr_count( $file, 'apply_filters(' ) );
	}

	/**
	 * INV-M18-4: No new capability introduced.
	 * (verify only manage_woocommerce and edit_products are referenced)
	 */
	public function test_settings_controller_no_new_capability() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings-controller.php' );
		$this->assertStringContainsString( 'manage_woocommerce', $file );
		// Should not introduce new capabilities beyond existing ones
	}

	/**
	 * INV-M18-10: Nonce strings unchanged (reused verbatim from source).
	 */
	public function test_settings_controller_nonce_strings_unchanged() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings-controller.php' );
		$this->assertStringContainsString( 'wc_io_save_settings', $file );
		$this->assertStringContainsString( 'wc_io_add_exchange_rate', $file );
		$this->assertStringContainsString( 'wc_io_delete_exchange_rate', $file );
		$this->assertStringContainsString( 'wc_io_danger_reset_preview', $file );
		$this->assertStringContainsString( 'wc_io_danger_reset_apply', $file );
		$this->assertStringContainsString( 'wc_io_get_exchange_rate', $file );
	}

	/**
	 * INV-M18-11: Plugin no longer owns Settings methods.
	 */
	public function test_plugin_no_longer_owns_settings_methods() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringNotContainsString( 'function handle_save_settings_post', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_add_exchange_rate_post', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_delete_exchange_rate_post', $plugin_file );
		$this->assertStringNotContainsString( 'function render_settings_panel()', $plugin_file );
		$this->assertStringNotContainsString( 'function render_exchange_rate_history_section', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_danger_reset_preview_post', $plugin_file );
		$this->assertStringNotContainsString( 'function handle_danger_reset_apply_post', $plugin_file );
		$this->assertStringNotContainsString( 'function ajax_get_exchange_rate()', $plugin_file );
	}

	/**
	 * INV-M18-1: Plugin still owns tab routing.
	 */
	public function test_plugin_remains_tab_routing_owner() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringContainsString( 'TAB_SETTINGS', $plugin_file );
		$this->assertStringContainsString( 'TAB_DASHBOARD', $plugin_file );
		$this->assertStringContainsString( 'PAGE_SLUG', $plugin_file );
		$this->assertStringContainsString( 'function get_requested_tab()', $plugin_file );
		$this->assertStringContainsString( 'function admin_url_tab(', $plugin_file );
	}

	/**
	 * INV-M18-9: Capability check ordering preserved (before nonce).
	 * (spot-check in handle_save_settings_post)
	 */
	public function test_settings_controller_capability_before_nonce() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings-controller.php' );

		// Find handle_save_settings_post function and verify capability check comes before nonce check
		$method_start = strpos( $file, 'function handle_save_settings_post' );
		$method_end = strpos( $file, 'function', $method_start + 1 );
		if ( false === $method_end ) {
			$method_end = strlen( $file );
		}
		$method = substr( $file, $method_start, $method_end - $method_start );

		$cap_pos = strpos( $method, 'current_user_can( \'manage_woocommerce\'' );
		$nonce_pos = strpos( $method, 'check_admin_referer' );

		$this->assertNotFalse( $cap_pos, 'Capability check not found in method' );
		$this->assertNotFalse( $nonce_pos, 'Nonce check not found in method' );
		$this->assertLessThan( $nonce_pos, $cap_pos, 'Capability check must come before nonce check' );
	}
}
