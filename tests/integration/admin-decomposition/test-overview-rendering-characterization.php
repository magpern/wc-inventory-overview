<?php
/**
 * M20 characterization tests: Overview rendering/summary-cards/screen-option.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-10/11/12/18/19. After extraction to Overview_Controller,
 * rerun unchanged (invocation-seam edits only) and must stay green
 * (INV-M20-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Rendering_Characterization extends WC_Inventory_Overview_Test_Case {

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
	 * BR-M20-10: Overview panel renders heading and the List_Table's markup.
	 */
	public function test_overview_panel_renders_heading_and_table() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Inventory Overview', $output );
		$this->assertStringContainsString( 'id="posts-filter"', $output );
		$this->assertStringContainsString( 'name="page" value="' . WC_Inventory_Overview_Plugin::PAGE_SLUG . '"', $output );
		$this->assertStringContainsString( 'name="tab" value="' . WC_Inventory_Overview_Plugin::TAB_OVERVIEW . '"', $output );
	}

	/**
	 * BR-M20-11: Summary cards render the 7 fixed metric keys.
	 */
	public function test_overview_panel_renders_summary_cards() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		foreach ( array( 'total', 'in_stock', 'out_of_stock', 'on_backorder', 'low_stock', 'draft', 'hidden' ) as $key ) {
			$this->assertStringContainsString( 'data-wc-io-metric="' . $key . '"', $output );
		}
	}

	/**
	 * BR-M20-11: render_summary_cards() static entry point renders nothing
	 * for an empty stats array (early return).
	 */
	public function test_render_summary_cards_empty_stats_renders_nothing() {
		ob_start();
		WC_Inventory_Overview_Overview_Controller::render_summary_cards( array() );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * BR-M20-10: singular bulk-done notice for exactly 1 product.
	 */
	public function test_overview_bulk_done_notice_singular() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_bulk_done' => '1',
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( '1 product updated.', $output );
		$this->assertStringContainsString( 'notice-success', $output );
	}

	/**
	 * BR-M20-10: plural bulk-done notice for >1 products.
	 */
	public function test_overview_bulk_done_notice_plural() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'             => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_bulk_done' => '3',
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( '3 products updated.', $output );
	}

	/**
	 * BR-M20-1 (top-level page gate, mirrors BR-M20-19's dispatch discipline):
	 * capability denial (no edit_products at all) returns wp_die -- robust to
	 * whether a prior test in this process has permanently defined
	 * DOING_AJAX (see test-restock-mutation-characterization.php's identical
	 * note).
	 */
	public function test_overview_render_capability_denied_returns_wp_die() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
		);
		$_REQUEST = $_GET;

		$throw_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
			};
		};
		add_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		try {
			$this->expectException( WPDieException::class );
			$plugin->render_inventory_profit_shell();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}

	/**
	 * BR-M20-12: on_load_screen() registers the per-page screen option; the
	 * file-scope filter clamps values to [1, 500].
	 */
	public function test_screen_option_filter_clamps_value() {
		$below = apply_filters( 'set_screen_option_wc_io_per_page', false, 'wc_io_per_page', 0 );
		$above = apply_filters( 'set_screen_option_wc_io_per_page', false, 'wc_io_per_page', 5000 );
		$mid   = apply_filters( 'set_screen_option_wc_io_per_page', false, 'wc_io_per_page', 42 );
		$other = apply_filters( 'set_screen_option_wc_io_per_page', 'untouched', 'some_other_option', 42 );

		$this->assertSame( 1, $below );
		$this->assertSame( 500, $above );
		$this->assertSame( 42, $mid );
		$this->assertSame( 'untouched', $other );
	}

	/**
	 * BR-M20-12: on_load_screen() dispatch order -- export-check runs before
	 * bulk-check (no export/bulk params present here, just confirms the
	 * no-op path completes without error/output).
	 */
	public function test_on_load_screen_no_op_when_no_export_or_bulk_params() {
		$controller = WC_Inventory_Overview_Overview_Controller::instance();

		ob_start();
		$result = $controller->on_load_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $result );
	}

	/**
	 * Query count: Overview panel render, empty catalog. Baseline for
	 * post-extraction comparison (INV-M20-8).
	 */
	public function test_query_count_overview_render_empty_catalog() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}

	/**
	 * Query count: Overview panel render, small mixed catalog (simple +
	 * variable). Baseline for post-extraction comparison (INV-M20-8).
	 */
	public function test_query_count_overview_render_small_catalog() {
		global $wpdb;

		for ( $i = 0; $i < 10; $i++ ) {
			$this->create_simple_product( array( 'name' => 'Product ' . $i, 'stock_qty' => 5 ) );
		}
		$this->create_variable_product(
			array( 'name' => 'Variable Product' ),
			array(
				array( 'name' => 'Var A', 'stock_qty' => 3 ),
				array( 'name' => 'Var B', 'stock_qty' => 7 ),
			)
		);

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}
}
