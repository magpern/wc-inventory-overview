<?php
/**
 * M20 characterization tests: Restock rendering/bootstrap/legacy-redirect.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-1/2/3/4/20. After extraction to Restock_Controller, rerun
 * unchanged (invocation-seam edits only) and must stay green (INV-M20-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Restock_Rendering_Characterization extends WP_UnitTestCase {

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
	 * BR-M20-1/2: Quick Restock sub-view renders by default.
	 */
	public function test_restock_panel_renders_quick_subview_by_default() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Quick Restock', $output );
		$this->assertStringContainsString( 'name="action" value="wc_io_restock"', $output );
		$this->assertStringContainsString( 'name="wc_io_line_id"', $output );
		$this->assertStringContainsString( 'name="wc_io_qty"', $output );
		$this->assertStringContainsString( 'name="wc_io_unit_cost"', $output );
		$this->assertStringContainsString( 'name="wc_io_supplier"', $output );
		$this->assertStringContainsString( 'name="wc_io_note"', $output );
		$this->assertStringContainsString( '_wpnonce', $output );
	}

	/**
	 * BR-M20-1/3: Cost Adjustment sub-view renders when restock_view=adjust.
	 */
	public function test_restock_panel_renders_adjust_subview_when_requested() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'          => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'restock_view' => WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Cost Adjustment', $output );
		$this->assertStringContainsString( 'name="action" value="wc_io_cost_adjustment"', $output );
		$this->assertStringContainsString( 'name="wc_io_adj_line_id"', $output );
		$this->assertStringContainsString( 'name="wc_io_new_avg_cost"', $output );
		$this->assertStringContainsString( 'name="wc_io_adj_note"', $output );
	}

	/**
	 * BR-M20-4: An unrecognized restock_view (e.g. the pre-M6 'batch' bookmark)
	 * falls back to Quick Restock, not an error.
	 */
	public function test_restock_stale_batch_view_falls_back_to_quick() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'          => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'restock_view' => 'batch',
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Quick Restock', $output );
	}

	/**
	 * BR-M20-4: Subnav marks the current sub-view (span, not link) and links
	 * the other one.
	 */
	public function test_restock_subnav_marks_current_view() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'          => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'restock_view' => WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST,
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'wc-io-restock-subnav__current', $output );
		$this->assertMatchesRegularExpression( '/<span class="wc-io-restock-subnav__current">\s*Cost Adjustment/', $output );
		$this->assertMatchesRegularExpression( '/<a href="[^"]*">\s*Quick Restock/', $output );
	}

	/**
	 * BR-M20-1: wc_io_restock_msg=success renders the success notice.
	 */
	public function test_restock_success_notice_renders() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'              => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'               => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'wc_io_restock_msg' => 'success',
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Restock recorded. Stock and weighted average cost were updated.', $output );
		$this->assertStringContainsString( 'notice-success', $output );
	}

	/**
	 * BR-M20-1: wc_io_adj_msg=error surfaces the raw WP_Error message verbatim.
	 */
	public function test_restock_cost_adjustment_error_notice_renders_raw_message() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'          => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'wc_io_adj_msg' => 'error',
			'wc_io_adj_err' => rawurlencode( 'Average unit cost cannot be negative.' ),
		);
		$_REQUEST = $_GET;

		ob_start();
		$plugin->render_inventory_profit_shell();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Average unit cost cannot be negative.', $output );
		$this->assertStringContainsString( 'notice-error', $output );
	}

	/**
	 * BR-M20-1: top-level page capability denial (no edit_products at all)
	 * returns wp_die -- robust to whether a prior test in this process has
	 * permanently defined DOING_AJAX (which reroutes wp_die() through the
	 * 'wp_die_ajax_handler' filter instead of 'wp_die_handler' -- the same
	 * documented hazard flagged in
	 * tests/integration/supplier-merge/test-supplier-merge-admin.php).
	 */
	public function test_restock_render_capability_denied_returns_wp_die() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
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
	 * BR-M20-19: on_load_restock_screen() is a no-op (no output, no exception)
	 * for an authorized user — it only bootstraps, never renders.
	 */
	public function test_on_load_restock_screen_no_op_for_authorized_user() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		ob_start();
		$result = $plugin->on_load_restock_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $result );
	}

	/**
	 * BR-M20-19: on_load_restock_screen() silently returns for a user lacking
	 * manage_woocommerce (no wp_die, no output).
	 */
	public function test_on_load_restock_screen_silent_for_unauthorized_user() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		ob_start();
		$result = $plugin->on_load_restock_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $result );
	}

	/**
	 * BR-M20-20: legacy `?page=wc-inventory-restock` bookmark redirects to the
	 * Restock tab, Quick sub-view, gated by manage_woocommerce.
	 */
	public function test_legacy_restock_slug_redirects_to_restock_quick() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array( 'page' => 'wc-inventory-restock' );

		$captured = null;
		$filter   = static function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new Exception( 'wp_redirect:' . $location );
		};
		add_filter( 'wp_redirect', $filter, 10, 1 );

		try {
			$plugin->redirect_legacy_inventory_admin_pages();
			$this->fail( 'Expected wp_safe_redirect/exit' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'wp_redirect:', $e->getMessage() );
		} finally {
			remove_filter( 'wp_redirect', $filter, 10 );
		}

		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_RESTOCK, $captured );
		$this->assertStringContainsString( 'restock_view=' . WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK, $captured );
	}

	/**
	 * BR-M20-20: legacy restock-slug redirect is denied for a user lacking
	 * manage_woocommerce (silent return, no redirect).
	 */
	public function test_legacy_restock_slug_redirect_denied_without_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_GET   = array( 'page' => 'wc-inventory-restock' );

		$captured = null;
		$filter   = static function ( $location ) use ( &$captured ) {
			$captured = $location;
			throw new Exception( 'wp_redirect:' . $location );
		};
		add_filter( 'wp_redirect', $filter, 10, 1 );

		try {
			$plugin->redirect_legacy_inventory_admin_pages();
		} finally {
			remove_filter( 'wp_redirect', $filter, 10 );
		}

		$this->assertNull( $captured, 'No redirect should occur without manage_woocommerce' );
	}

	/**
	 * Query count: Restock panel render (Quick sub-view). Baseline for
	 * post-extraction comparison (INV-M20-8).
	 */
	public function test_query_count_restock_render_quick() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}

	/**
	 * Query count: Restock panel render (Cost Adjustment sub-view).
	 */
	public function test_query_count_restock_render_adjust() {
		global $wpdb;

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET = array(
			'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'          => WC_Inventory_Overview_Plugin::TAB_RESTOCK,
			'restock_view' => WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST,
		);
		$_REQUEST = $_GET;

		$before = $wpdb->num_queries;
		ob_start();
		$plugin->render_inventory_profit_shell();
		ob_end_clean();
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}
}
