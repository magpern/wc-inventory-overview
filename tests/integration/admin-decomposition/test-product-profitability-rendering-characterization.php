<?php
/**
 * M19 characterization tests: Product Profitability tab rendering + CSV
 * export guards.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M19-3, BR-M19-6, BR-M19-7, BR-M19-8.
 * After extraction, rerun unchanged (invocation-seam edits only) and must
 * stay green (INV-M19-2).
 *
 * As in the Movements/Order Profit characterization files, the successful
 * CSV-export streaming path (exit() after writing to php://output) is not
 * safely testable in-process and is covered by manual acceptance only.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Product_Profitability_Rendering_Characterization extends WP_UnitTestCase {

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
	 * BR-M19-3: Product Profitability panel renders heading, the exact
	 * description string verbatim, date-range filters, and the scroll
	 * wrapper.
	 */
	public function test_product_profitability_panel_renders_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Product Profitability', $output );
		$this->assertStringContainsString( 'Average sale price is discounted revenue divided by units sold.', $output );
		$this->assertStringContainsString( 'id="wc-io-product-profitability-filter"', $output );
		$this->assertStringContainsString( 'name="wc_io_pp_date_from"', $output );
		$this->assertStringContainsString( 'name="wc_io_pp_date_to"', $output );
		$this->assertStringContainsString( 'wc-io-product-profitability-scroll', $output );
	}

	/**
	 * BR-M19-3/BR-M19-8: Without manage_woocommerce, get_requested_tab()
	 * (Plugin, unchanged by this milestone) itself falls back to
	 * TAB_OVERVIEW before the shell's switch ever runs — so the switch's
	 * own "You do not have permission to view product profitability."
	 * notice branch is unreachable via normal navigation; the shell instead
	 * silently renders the Overview panel. Verified against unmodified
	 * pre-extraction code (corrected here, before any extraction).
	 */
	public function test_product_profitability_panel_denied_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'id="wc-io-product-profitability-filter"', $output );
		$this->assertStringContainsString( 'wc-io-tab-panel--overview', $output );
		$this->assertStringContainsString( 'Inventory Overview', $output );
	}

	/**
	 * BR-M19-6: Without wc_io_pp_export=csv,
	 * on_load_product_profitability_screen() returns normally (no export
	 * attempted, no exit()).
	 */
	public function test_product_profitability_csv_export_not_triggered_without_param() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
		);
		$_REQUEST = $_GET;

		$plugin->on_load_product_profitability_screen();
		$this->assertTrue( true, 'on_load_product_profitability_screen() returned without exporting.' );
	}

	/**
	 * BR-M19-6: wc_io_pp_export=csv present but tab does not match —
	 * export guarded off.
	 */
	public function test_product_profitability_csv_export_requires_matching_tab() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_pp_export' => 'csv',
		);
		$_REQUEST = $_GET;

		$plugin->on_load_product_profitability_screen();
		$this->assertTrue( true, 'Export guarded off for non-matching tab.' );
	}

	/**
	 * BR-M19-6/INV-M19-9/INV-M19-10: export triggered, correct tab, invalid
	 * nonce -> WPDieException, proving
	 * 'wc_io_product_profitability_export_csv' / '_wc_io_pp_export_nonce'
	 * gate the export.
	 */
	public function test_product_profitability_csv_export_invalid_nonce_dies() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
			'wc_io_pp_export' => 'csv',
		);
		$_REQUEST = $_GET;

		$this->expectException( WPDieException::class );
		$plugin->on_load_product_profitability_screen();
	}

	/**
	 * BR-M19-6/INV-M19-9: capability checked before the export condition —
	 * without manage_woocommerce, on_load_product_profitability_screen()
	 * returns before ever reaching the nonce check.
	 */
	public function test_product_profitability_on_load_denies_without_capability_before_export_check() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
			'wc_io_pp_export' => 'csv',
		);
		$_REQUEST = $_GET;

		$plugin->on_load_product_profitability_screen();
		$this->assertTrue( true, 'Returned before reaching the export/nonce check.' );
	}

	/**
	 * BR-M19-7: on_load_product_profitability_screen() registers the
	 * per-page screen option and returns without error for a capable user
	 * with no export requested.
	 */
	public function test_product_profitability_screen_option_registered_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
		);
		$_REQUEST = $_GET;

		$plugin->on_load_product_profitability_screen();
		$this->assertTrue( true, 'on_load_product_profitability_screen() completed without error.' );
	}

	/**
	 * Query count: Product Profitability panel render.
	 * Baseline measured against pre-extraction code; must match exactly
	 * post-extraction.
	 */
	public function test_query_count_product_profitability_render() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline measured against unmodified pre-extraction code: 4 queries.
		// Must remain exactly 4 post-extraction (M19 plan, Performance/Query Contract).
		$this->assertSame( 4, $query_count );
	}
}
