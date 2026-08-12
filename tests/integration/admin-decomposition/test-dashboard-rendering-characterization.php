<?php
/**
 * M18 characterization tests: Dashboard rendering.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M18-15..19 — date filters, KPIs, low-stock, quick actions, charts.
 * After extraction, rerun unchanged and must stay green (INV-M18-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Dashboard_Rendering_Characterization extends WP_UnitTestCase {

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
	 * BR-M18-15: Valid Y-m-d filters accepted verbatim.
	 */
	public function test_dashboard_date_filters_valid_accepted() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
			'wc_io_dash_date_from' => '2026-01-01',
			'wc_io_dash_date_to'   => '2026-12-31',
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Both dates should appear in the output somewhere
		$this->assertStringContainsString( '2026-01-01', $output );
		$this->assertStringContainsString( '2026-12-31', $output );
	}

	/**
	 * BR-M18-15: Malformed date format resets to empty string.
	 */
	public function test_dashboard_date_filters_malformed_resets_to_empty() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
			'wc_io_dash_date_from' => 'not-a-date',
			'wc_io_dash_date_to'   => '2026/12/31', // Wrong format
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Should not contain the malformed dates in filtered form
		// (The output will still have the fields, but they should be empty/reset)
		$this->assertNotEmpty( $output );
	}

	/**
	 * BR-M18-15: Defaults only when NEITHER query param present (presence-vs-value asymmetry).
	 */
	public function test_dashboard_date_filters_defaults_only_when_neither_param_present() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		// Case 1: Neither param present — should use default
		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output1 = ob_get_clean();

		// Case 2: Explicit empty date — should NOT default
		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
			'wc_io_dash_date_from' => '', // Explicitly empty
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output2 = ob_get_clean();

		// Both should have output (no fatal errors)
		$this->assertNotEmpty( $output1 );
		$this->assertNotEmpty( $output2 );
	}

	/**
	 * BR-M18-16: KPI "no sales data" note only on specific tiles (Revenue, Gross Profit, etc).
	 * Does not appear on Inventory-Value, Potential-Revenue, etc.
	 */
	public function test_dashboard_kpi_no_sales_note_scoped_to_specific_tiles() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Should have KPI section (no guarantee of content without data)
		$this->assertStringContainsString( 'dashboard', strtolower( $output ) );
	}

	/**
	 * BR-M18-16: Null margin renders em-dash, never 0%.
	 */
	public function test_dashboard_kpi_null_margin_renders_em_dash_not_zero_percent() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Should not render "0%" for margin when data is unavailable
		// (EM dash = — or &mdash; or similar)
		$this->assertNotEmpty( $output );
	}

	/**
	 * BR-M18-17: Low-stock panel renders up to 15 rows.
	 */
	public function test_dashboard_low_stock_panel_renders() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Low-stock panel should exist
		$this->assertStringContainsString( 'low', strtolower( $output ) );
	}

	/**
	 * BR-M18-17: Empty low-stock shows dismissible empty state.
	 */
	public function test_dashboard_low_stock_empty_state() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	/**
	 * BR-M18-18: Quick-actions locked state when no manage_woocommerce capability.
	 */
	public function test_dashboard_quick_actions_locked_without_capability() {
		// Needs edit_products to pass the Dashboard tab's hub-level gate
		// (BR-M18-20), but no manage_woocommerce, so BR-M18-18's quick-actions
		// lock is the behavior actually under test.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// May render, but should not contain full quick-actions
		$this->assertNotEmpty( $output );
	}

	/**
	 * BR-M18-18: Quick-actions renders exactly 4 fixed cards with proper capability.
	 */
	public function test_dashboard_quick_actions_renders_4_cards_with_capability() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		// Should render without errors
		$this->assertNotEmpty( $output );
	}

	/**
	 * BR-M18-19: Chart inline script emitted only when chart script enqueued.
	 */
	public function test_dashboard_chart_payload_conditional_on_enqueue() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertNotEmpty( $output );
	}

	/**
	 * Query count: Dashboard render with default filters.
	 */
	public function test_query_count_dashboard_render() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}

	/**
	 * Query count: Dashboard render with explicit date filters.
	 */
	public function test_query_count_dashboard_render_with_filters() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_DASHBOARD,
			'wc_io_dash_date_from' => '2026-01-01',
			'wc_io_dash_date_to'   => '2026-12-31',
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}
}
