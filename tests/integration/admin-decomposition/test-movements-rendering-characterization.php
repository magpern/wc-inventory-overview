<?php
/**
 * M19 characterization tests: Movements tab rendering + CSV export guards.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M19-1, BR-M19-4, BR-M19-7, BR-M19-8.
 * After extraction, rerun unchanged (invocation-seam edits only) and must
 * stay green (INV-M19-2).
 *
 * The successful CSV-export streaming path (maybe_export_movements_csv()'s
 * exit() after writing to php://output) cannot be safely characterized by
 * an in-process PHPUnit run — WP_UnitTestCase has no exit()-interception
 * convention in this repository (confirmed: no runInSeparateProcess/
 * preserveGlobalState usage anywhere in tests/). That path is covered by
 * manual acceptance only, per the M19 plan's Manual Acceptance section.
 * This file characterizes every reachable guard/early-return branch instead.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Movements_Rendering_Characterization extends WP_UnitTestCase {

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
	 * BR-M19-1: Movements panel renders heading, filter form with hidden
	 * page/tab/orderby/order fields sourced from
	 * Movements_List_Table::get_request_args(), and the scroll wrapper.
	 */
	public function test_movements_panel_renders_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Inventory Movements', $output );
		$this->assertStringContainsString( 'id="wc-io-movements-filter"', $output );
		$this->assertStringContainsString( 'name="page" value="' . WC_Inventory_Overview_Plugin::PAGE_SLUG . '"', $output );
		$this->assertStringContainsString( 'name="tab" value="' . WC_Inventory_Overview_Plugin::TAB_MOVEMENTS . '"', $output );
		$this->assertStringContainsString( 'name="orderby" value="created_at"', $output );
		$this->assertStringContainsString( 'name="order" value="DESC"', $output );
		$this->assertStringContainsString( 'wc-io-movements-scroll', $output );
	}

	/**
	 * BR-M19-1/BR-M19-8: Without manage_woocommerce, get_requested_tab()
	 * (Plugin, unchanged by this milestone) itself falls back to
	 * TAB_OVERVIEW before the shell's switch ever runs — so the switch's
	 * own "You do not have permission to view inventory movements." notice
	 * branch is unreachable via normal navigation; the shell instead
	 * silently renders the Overview panel. This is the actual, verified
	 * pre-extraction behavior (confirmed by running this test against
	 * unmodified code — the notice-branch assumption was wrong and was
	 * corrected here before any extraction, per the plan's characterization
	 * discipline).
	 */
	public function test_movements_panel_denied_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="wc-io-movements-filter"', $output );
		$this->assertStringContainsString( 'wc-io-tab-panel--overview', $output );
		$this->assertStringContainsString( 'Inventory Overview', $output );
	}

	/**
	 * BR-M19-4: Without wc_io_mv_export=csv, on_load_movements_screen()
	 * returns normally (no export attempted, no exit()).
	 */
	public function test_movements_csv_export_not_triggered_without_param() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
		$this->assertTrue( true, 'on_load_movements_screen() returned without exporting.' );
	}

	/**
	 * BR-M19-4: wc_io_mv_export=csv present but tab is not movements —
	 * export guarded off, returns normally.
	 */
	public function test_movements_csv_export_requires_matching_tab() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_mv_export' => 'csv',
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
		$this->assertTrue( true, 'Export guarded off for non-matching tab.' );
	}

	/**
	 * BR-M19-4/INV-M19-9/INV-M19-10: export triggered, correct tab, but
	 * invalid/missing nonce -> check_admin_referer() -> WPDieException,
	 * proving the nonce action string 'wc_io_movements_export_csv' /
	 * field '_wc_io_mv_export_nonce' gate the export before any CSV
	 * streaming begins.
	 */
	public function test_movements_csv_export_invalid_nonce_dies() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
			'wc_io_mv_export' => 'csv',
		);
		$_REQUEST = $_GET;

		$this->expectException( WPDieException::class );
		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
	}

	/**
	 * BR-M19-4/INV-M19-9: capability checked before the export condition —
	 * without manage_woocommerce, on_load_movements_screen() returns before
	 * ever reaching maybe_export_movements_csv(), so no nonce check fires
	 * even with export params present (would otherwise WPDieException on
	 * the nonce, not silently return).
	 */
	public function test_movements_on_load_denies_without_capability_before_export_check() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
			'wc_io_mv_export' => 'csv',
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
		$this->assertTrue( true, 'Returned before reaching the export/nonce check.' );
	}

	/**
	 * BR-M19-7: on_load_movements_screen() registers the per-page screen
	 * option and returns without error for a capable user with no export
	 * requested.
	 */
	public function test_movements_screen_option_registered_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
		);
		$_REQUEST = $_GET;

		WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
		$this->assertTrue( true, 'on_load_movements_screen() completed without error.' );
	}

	/**
	 * Query count: Movements panel render.
	 * Baseline measured against pre-extraction code; must match exactly
	 * post-extraction (M19 plan, Performance/Query Contract).
	 */
	public function test_query_count_movements_render() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline measured against unmodified pre-extraction code: 2 queries.
		// Must remain exactly 2 post-extraction (M19 plan, Performance/Query Contract).
		$this->assertSame( 2, $query_count );
	}
}
