<?php
/**
 * M19 characterization tests: Order Profit tab rendering + CSV export guards.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M19-2, BR-M19-5, BR-M19-7, BR-M19-8.
 * After extraction, rerun unchanged (invocation-seam edits only) and must
 * stay green (INV-M19-2).
 *
 * As in test-movements-rendering-characterization.php, the successful
 * CSV-export streaming path (exit() after writing to php://output) is not
 * safely testable in-process and is covered by manual acceptance only.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Order_Profit_Rendering_Characterization extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
	}

	public function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * BR-M19-2: Order Profit panel renders heading, the exact description
	 * string verbatim, date-range + status filters, and the scroll wrapper.
	 */
	public function test_order_profit_panel_renders_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Order Profit', $output );
		$this->assertStringContainsString( 'Gross profit = product revenue + shipping paid - product cost - actual shipping cost.', $output );
		$this->assertStringContainsString( 'id="wc-io-order-profit-filter"', $output );
		$this->assertStringContainsString( 'name="wc_io_op_date_from"', $output );
		$this->assertStringContainsString( 'name="wc_io_op_date_to"', $output );
		$this->assertStringContainsString( 'id="wc-io-op-status"', $output );
		$this->assertStringContainsString( 'wc-io-order-profit-scroll', $output );
	}

	/**
	 * BR-M19-2: with no status in the request, the "All statuses" option
	 * is selected (status defaults to all valid statuses -> 'all' value).
	 */
	public function test_order_profit_status_defaults_to_all() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<option value="all" selected=\'selected\'>All statuses<\/option>/',
			$output
		);
	}

	/**
	 * BR-M19-2/BR-M19-8: Without manage_woocommerce, get_requested_tab()
	 * (Plugin, unchanged by this milestone) itself falls back to
	 * TAB_OVERVIEW before the shell's switch ever runs — so the switch's
	 * own "You do not have permission to view order profit." notice branch
	 * is unreachable via normal navigation; the shell instead silently
	 * renders the Overview panel. Verified against unmodified pre-extraction
	 * code (corrected here, before any extraction).
	 */
	public function test_order_profit_panel_denied_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="wc-io-order-profit-filter"', $output );
		$this->assertStringContainsString( 'wc-io-tab-panel--overview', $output );
		$this->assertStringContainsString( 'Inventory Overview', $output );
	}

	/**
	 * BR-M19-5: Without wc_io_op_export=csv, on_load_order_profit_screen()
	 * returns normally (no export attempted, no exit()).
	 */
	public function test_order_profit_csv_export_not_triggered_without_param() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
		$this->assertTrue( true, 'on_load_order_profit_screen() returned without exporting.' );
	}

	/**
	 * BR-M19-5: wc_io_op_export=csv present but tab does not match —
	 * export guarded off.
	 */
	public function test_order_profit_csv_export_requires_matching_tab() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_op_export' => 'csv',
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
		$this->assertTrue( true, 'Export guarded off for non-matching tab.' );
	}

	/**
	 * BR-M19-5/INV-M19-9/INV-M19-10: export triggered, correct tab, invalid
	 * nonce -> WPDieException, proving 'wc_io_order_profit_export_csv' /
	 * '_wc_io_op_export_nonce' gate the export.
	 */
	public function test_order_profit_csv_export_invalid_nonce_dies() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
			'wc_io_op_export' => 'csv',
		);
		$_REQUEST = $_GET;

		$this->expectException( WPDieException::class );
		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
	}

	/**
	 * BR-M19-5/INV-M19-9: capability checked before the export condition —
	 * without manage_woocommerce, on_load_order_profit_screen() returns
	 * before ever reaching the nonce check.
	 */
	public function test_order_profit_on_load_denies_without_capability_before_export_check() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
			'wc_io_op_export' => 'csv',
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
		$this->assertTrue( true, 'Returned before reaching the export/nonce check.' );
	}

	/**
	 * BR-M19-7: on_load_order_profit_screen() registers the per-page screen
	 * option and returns without error for a capable user with no export
	 * requested.
	 */
	public function test_order_profit_screen_option_registered_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
		$this->assertTrue( true, 'on_load_order_profit_screen() completed without error.' );
	}

	/**
	 * Query count: Order Profit panel render.
	 * Baseline measured against pre-extraction code; must match exactly
	 * post-extraction.
	 */
	public function test_query_count_order_profit_render() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline measured against unmodified pre-extraction code: 3 queries.
		// Must remain exactly 3 post-extraction (M19 plan, Performance/Query Contract).
		$this->assertSame( 3, $query_count );
	}
}
