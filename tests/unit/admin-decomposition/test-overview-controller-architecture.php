<?php
/**
 * M20 architecture guards: Overview Controller extracted methods.
 *
 * Verifies INV-M20-1/4/5/9/10/11/12/16/18: no new capabilities, hooks, SQL,
 * or transaction boundaries; correct ownership; capability-before-nonce
 * ordering; nonce strings preserved; bootstrap order preserved; the
 * enqueue_assets() Overview branch is verifiably gone from Plugin; the
 * List_Table domain class is untouched.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Controller_Architecture extends WP_UnitTestCase {

	/**
	 * INV-M20-6/INV-M20-18: Overview Controller contains no presentation SQL.
	 */
	public function test_overview_controller_contains_no_wpdb() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringNotContainsString( 'global $wpdb', $file );
		$this->assertStringNotContainsString( '$wpdb->', $file );
	}

	/**
	 * INV-M20-5: Overview Controller introduces no new public hooks.
	 */
	public function test_overview_controller_no_new_do_action() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertSame( 0, substr_count( $file, 'do_action(' ) );
		$this->assertSame( 0, substr_count( $file, 'apply_filters(' ) );
	}

	/**
	 * INV-M20-4: No new capability introduced — only the three pre-existing
	 * literals (edit_products, edit_product, manage_woocommerce) are
	 * referenced (strict set, not just non-empty).
	 */
	public function test_overview_controller_no_new_capability() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );

		preg_match_all( '/current_user_can\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/', $file, $matches );
		$caps = array_unique( $matches[1] );
		sort( $caps );
		$this->assertSame( array( 'edit_product', 'edit_products', 'manage_woocommerce' ), $caps, 'Only edit_products/edit_product/manage_woocommerce may be referenced.' );
	}

	/**
	 * INV-M20-10: Nonce/action strings reused verbatim from the
	 * pre-extraction Plugin methods — none renamed, none added.
	 */
	public function test_overview_controller_nonce_strings_unchanged() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringContainsString( "check_admin_referer( 'wc_io_export_csv', '_wc_io_export_nonce' )", $file );
		$this->assertStringContainsString( "check_admin_referer( 'bulk-wc-inventory-items' )", $file );
		$this->assertStringContainsString( "check_ajax_referer( 'wc_io_inventory', 'nonce' )", $file );
		$this->assertStringContainsString( "wp_create_nonce( 'wc_io_inventory' )", $file );
	}

	/**
	 * INV-M20-9: CSV export checks capability before the nonce, in that
	 * fixed order (matches the pre-extraction Plugin method verbatim).
	 */
	public function test_overview_controller_csv_export_capability_before_nonce() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );

		$method_pos = strpos( $file, 'function maybe_export_csv(' );
		$this->assertNotFalse( $method_pos, 'maybe_export_csv not found.' );

		$param_pos = strpos( $file, "\$_GET['wc_io_export']", $method_pos );
		$nonce_pos = strpos( $file, "check_admin_referer( 'wc_io_export_csv'", $method_pos );

		$this->assertNotFalse( $param_pos, 'Export param check not found.' );
		$this->assertNotFalse( $nonce_pos, 'Nonce check not found.' );
		$this->assertLessThan( $nonce_pos, $param_pos, 'Export param must be checked before nonce.' );

		// Page-level capability (edit_products) is checked in on_load_screen(),
		// strictly before maybe_export_csv() is ever called.
		$on_load_pos = strpos( $file, 'function on_load_screen(' );
		$cap_pos     = strpos( $file, "current_user_can( 'edit_products' )", $on_load_pos );
		$this->assertNotFalse( $cap_pos, 'Page-level capability check not found in on_load_screen().' );
		$this->assertLessThan( $method_pos, $cap_pos, 'Page-level capability must be checked before maybe_export_csv() is reached.' );
	}

	/**
	 * INV-M20-9: inline-stock AJAX checks capability before the nonce.
	 */
	public function test_overview_controller_inline_stock_capability_before_nonce() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );

		$method_pos = strpos( $file, 'function ajax_save_inline_stock(' );
		$this->assertNotFalse( $method_pos, 'ajax_save_inline_stock not found.' );

		$cap_pos   = strpos( $file, "current_user_can( 'edit_products' )", $method_pos );
		$nonce_pos = strpos( $file, "check_ajax_referer( 'wc_io_inventory', 'nonce' )", $method_pos );

		$this->assertNotFalse( $cap_pos, 'Capability check not found.' );
		$this->assertNotFalse( $nonce_pos, 'Nonce check not found.' );
		$this->assertLessThan( $nonce_pos, $cap_pos, 'Capability must be checked before nonce.' );
	}

	/**
	 * INV-M20-9: bulk-action handling checks the nonce (via
	 * check_admin_referer(), reached only after the empty-nonce guard)
	 * before any per-ID edit_product capability check or mutation.
	 */
	public function test_overview_controller_bulk_nonce_before_mutation() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );

		$method_pos = strpos( $file, 'function maybe_handle_bulk(' );
		$this->assertNotFalse( $method_pos, 'maybe_handle_bulk not found.' );

		$nonce_pos = strpos( $file, "check_admin_referer( 'bulk-wc-inventory-items' )", $method_pos );
		$cap_pos   = strpos( $file, "current_user_can( 'edit_product', \$id )", $method_pos );
		$mutate_pos = strpos( $file, "set_status( 'draft' )", $method_pos );

		$this->assertNotFalse( $nonce_pos, 'Nonce check not found.' );
		$this->assertNotFalse( $cap_pos, 'Per-ID capability check not found.' );
		$this->assertNotFalse( $mutate_pos, 'Mutation call not found.' );
		$this->assertLessThan( $cap_pos, $nonce_pos, 'Nonce must be checked before per-ID capability.' );
		$this->assertLessThan( $mutate_pos, $cap_pos, 'Per-ID capability must be checked before mutation.' );
	}

	/**
	 * INV-M20-11: Plugin no longer owns the eight moved methods or the
	 * top-level screen-option filter.
	 */
	public function test_plugin_no_longer_owns_overview_methods() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringNotContainsString( 'function on_load_screen', $plugin_file );
		$this->assertStringNotContainsString( 'function maybe_export_csv', $plugin_file );
		$this->assertStringNotContainsString( 'function get_query_params_from_request', $plugin_file );
		$this->assertStringNotContainsString( 'function maybe_handle_bulk', $plugin_file );
		$this->assertStringNotContainsString( 'function detect_bulk_action', $plugin_file );
		$this->assertStringNotContainsString( 'function ajax_save_inline_stock', $plugin_file );
		$this->assertStringNotContainsString( 'function render_summary_cards', $plugin_file );
		$this->assertStringNotContainsString( 'function render_inventory_overview_panel', $plugin_file );
		$this->assertStringNotContainsString( 'set_screen_option_wc_io_per_page', $plugin_file );
	}

	/**
	 * INV-M20-1: Plugin still owns tab routing.
	 */
	public function test_plugin_remains_tab_routing_owner() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );
		$this->assertStringContainsString( 'TAB_OVERVIEW', $plugin_file );
		$this->assertStringContainsString( 'PAGE_SLUG', $plugin_file );
		$this->assertStringContainsString( 'function get_requested_tab', $plugin_file );
		$this->assertStringContainsString( 'function admin_url_tab', $plugin_file );
		$this->assertStringContainsString( 'function on_load_inventory_profit_page', $plugin_file );
		$this->assertStringContainsString( 'function register_menu', $plugin_file );
		$this->assertStringContainsString( 'function redirect_legacy_inventory_admin_pages', $plugin_file );
	}

	/**
	 * INV-M20-12: Bootstrap order in Plugin::init() unchanged — the new
	 * controller's init() call is appended after Restock_Controller, before
	 * Expected_Delivery_Service, not interleaved (6-way chain).
	 */
	public function test_plugin_bootstrap_order_preserved() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$purchasing_pos = strpos( $plugin_file, 'WC_Inventory_Overview_Purchasing_Page::instance()->init()' );
		$settings_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Settings_Controller::instance()->init()' );
		$reporting_pos  = strpos( $plugin_file, 'WC_Inventory_Overview_Reporting_Controller::instance()->init()' );
		$restock_pos    = strpos( $plugin_file, 'WC_Inventory_Overview_Restock_Controller::instance()->init()' );
		$overview_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Overview_Controller::instance()->init()' );
		$expected_pos   = strpos( $plugin_file, 'WC_Inventory_Overview_Expected_Delivery_Service::register()' );

		$this->assertNotFalse( $purchasing_pos, 'Purchasing_Page bootstrap not found' );
		$this->assertNotFalse( $settings_pos, 'Settings_Controller bootstrap not found' );
		$this->assertNotFalse( $reporting_pos, 'Reporting_Controller bootstrap not found' );
		$this->assertNotFalse( $restock_pos, 'Restock_Controller bootstrap not found' );
		$this->assertNotFalse( $overview_pos, 'Overview_Controller bootstrap not found' );
		$this->assertNotFalse( $expected_pos, 'Expected_Delivery_Service bootstrap not found' );

		$this->assertLessThan( $settings_pos, $purchasing_pos );
		$this->assertLessThan( $reporting_pos, $settings_pos );
		$this->assertLessThan( $restock_pos, $reporting_pos );
		$this->assertLessThan( $overview_pos, $restock_pos, 'Restock_Controller must bootstrap before Overview_Controller' );
		$this->assertLessThan( $expected_pos, $overview_pos, 'Overview_Controller must bootstrap before Expected_Delivery_Service' );
	}

	/**
	 * INV-M20-1/INV-M20-11: The dispatchers are wired to the new controller
	 * — including BOTH the TAB_OVERVIEW case and the default: fallback case
	 * (BR-M20-18, the easy-to-miss second call site in
	 * render_inventory_profit_shell()).
	 */
	public function test_dispatch_calls_controller() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$this->assertStringContainsString( 'WC_Inventory_Overview_Overview_Controller::instance()->on_load_screen()', $plugin_file );

		$occurrences = substr_count( $plugin_file, 'WC_Inventory_Overview_Overview_Controller::instance()->render()' );
		$this->assertSame( 2, $occurrences, 'render() must be called from both the TAB_OVERVIEW case and the default: fallback case.' );
	}

	/**
	 * INV-M20-16: No DB transaction is introduced -- Overview's bulk-action
	 * loop and inline-stock edit remain non-transactional, exactly as
	 * before.
	 */
	public function test_overview_controller_no_transaction_introduced() {
		$file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringNotContainsString( 'DB_Transaction', $file );
		$this->assertStringNotContainsString( 'START TRANSACTION', $file );
	}

	/**
	 * INV-M20-11 (enqueue split, plan §9): Plugin::enqueue_assets() no
	 * longer references TAB_OVERVIEW or admin.js/wcIoInventory; that
	 * ownership moved to Overview_Controller::enqueue_overview_assets().
	 * Dashboard's own branch (chartjs/dashboard-charts.js) is unaffected.
	 */
	public function test_enqueue_assets_no_longer_owns_overview_branch() {
		$plugin_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php' );

		$enqueue_pos = strpos( $plugin_file, 'function enqueue_assets(' );
		$this->assertNotFalse( $enqueue_pos, 'enqueue_assets() not found.' );
		$next_method_pos = strpos( $plugin_file, "\n\t/**", $enqueue_pos + 10 );
		$next_prop_pos    = strpos( $plugin_file, "\n}", $enqueue_pos + 10 );
		$end              = $next_method_pos !== false ? $next_method_pos : $next_prop_pos;
		$enqueue_body     = substr( $plugin_file, $enqueue_pos, ( $end ?: strlen( $plugin_file ) ) - $enqueue_pos );

		$this->assertStringNotContainsString( 'TAB_OVERVIEW', $enqueue_body );
		$this->assertStringNotContainsString( 'wcIoInventory', $enqueue_body );
		$this->assertStringNotContainsString( "'assets/admin.js'", $enqueue_body );
		$this->assertStringContainsString( 'TAB_DASHBOARD', $enqueue_body );
		$this->assertStringContainsString( 'wc-io-chartjs', $enqueue_body );

		$overview_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringContainsString( 'wcIoInventory', $overview_file );
		$this->assertStringContainsString( 'function enqueue_overview_assets', $overview_file );
	}

	/**
	 * INV-M20-18: WC_Inventory_Overview_List_Table gains zero new methods —
	 * called identically by Overview_Controller as it was by Plugin.
	 */
	public function test_list_table_unmodified_and_called_by_controller() {
		$controller_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-overview-controller.php' );
		$this->assertStringContainsString( 'new WC_Inventory_Overview_List_Table()', $controller_file );
		$this->assertStringContainsString( '$list_table->prepare_items()', $controller_file );

		$list_table_file = file_get_contents( WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-list-table.php' );
		$this->assertStringNotContainsString( 'Overview_Controller', $list_table_file, 'List_Table must remain unaware of the controller -- called, not coupled.' );
	}
}
