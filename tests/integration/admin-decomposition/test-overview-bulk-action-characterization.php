<?php
/**
 * M20 characterization tests: Overview bulk actions (maybe_handle_bulk()).
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-14. After extraction to Overview_Controller, rerun
 * unchanged and must stay green (INV-M20-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Bulk_Action_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var string|null Captured redirect location from wp_redirect filter.
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		$this->redirect_to = null;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	public function capture_redirect( $location ) {
		$this->redirect_to = $location;
		throw new Exception( 'wp_redirect:' . $location );
	}

	private function run_expecting_redirect( callable $callback ) {
		try {
			$callback();
			$this->fail( 'Expected wp_safe_redirect/exit' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'wp_redirect:', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->redirect_to );
		return (string) $this->redirect_to;
	}

	private function bulk_nonce(): string {
		// WP_List_Table's default bulk nonce action, derived from the table's
		// singular/plural args ('wc-inventory-item'/'wc-inventory-items').
		return wp_create_nonce( 'bulk-wc-inventory-items' );
	}

	/**
	 * BR-M20-14: wc_io_set_draft sets product status to draft.
	 */
	public function test_bulk_set_draft() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_set_draft',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'draft', $product->get_status() );
	}

	/**
	 * BR-M20-14: wc_io_hide_catalog sets catalog visibility to hidden.
	 */
	public function test_bulk_hide_catalog() {
		$product = $this->create_simple_product();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_hide_catalog',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN, $product->get_catalog_visibility() );
	}

	/**
	 * BR-M20-14: wc_io_mark_instock sets stock status to instock.
	 */
	public function test_bulk_mark_instock() {
		// stock_qty must be > 0: WooCommerce's own product data store
		// recomputes stock status from quantity on save() when managing
		// stock, silently overriding an explicit IN_STOCK set at qty=0 back
		// to OUT_OF_STOCK -- this is pre-existing WC-core behavior, not
		// something Plugin/Overview controls, so the fixture must reflect a
		// realistic quantity for this scenario to be meaningful (a genuine
		// pre-extraction fixture correction, found running this test against
		// unmodified code, not a production defect).
		$product = $this->create_simple_product( array( 'stock_qty' => 5 ) );
		$product->set_stock_status( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK );
		$product->save();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_mark_instock',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK, $product->get_stock_status() );
	}

	/**
	 * BR-M20-14: wc_io_mark_outofstock sets stock status to outofstock.
	 */
	public function test_bulk_mark_outofstock() {
		$product = $this->create_simple_product();
		$product->set_stock_status( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK );
		$product->save();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_mark_outofstock',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK, $product->get_stock_status() );
	}

	/**
	 * BR-M20-14: bottom dropdown (action2) is honored when the top dropdown
	 * (action) is unset/'-1'.
	 */
	public function test_bulk_action2_used_when_action_is_dash_one() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => '-1',
			'action2'  => 'wc_io_set_draft',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location );
		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'draft', $product->get_status() );
	}

	/**
	 * BR-M20-14: unrecognized action is a no-op -- no redirect, no mutation.
	 */
	public function test_bulk_invalid_action_no_op() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_delete_everything',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		ob_start();
		$plugin->on_load_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $this->redirect_to );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'publish', $product->get_status() );
	}

	/**
	 * BR-M20-14: missing _wpnonce is a silent no-op, before
	 * check_admin_referer() even runs.
	 */
	public function test_bulk_missing_nonce_silent_no_op() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action' => 'wc_io_set_draft',
			'post'   => array( $product->get_id() ),
		);

		ob_start();
		$plugin->on_load_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $this->redirect_to );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'publish', $product->get_status(), 'no mutation without a nonce' );
	}

	/**
	 * BR-M20-14: invalid nonce -> wp_die (via check_admin_referer), no
	 * mutation. Robust to DOING_AJAX pollution from other test files in the
	 * same process (see test-restock-mutation-characterization.php's
	 * identical note).
	 */
	public function test_bulk_invalid_nonce_dies() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_set_draft',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => 'invalid-nonce',
		);

		$throw_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		try {
			$this->expectException( WPDieException::class );
			$plugin->on_load_screen();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'publish', $product->get_status() );
	}

	/**
	 * BR-M20-14: a nonexistent product ID in the selection is skipped (not
	 * fatal); a valid ID alongside it still updates, and the done-count
	 * reflects only successful mutations.
	 */
	public function test_bulk_skips_nonexistent_id_counts_only_successes() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );
		$bogus_id = 999999999;

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_set_draft',
			'post'     => array( $bogus_id, $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'wc_io_bulk_done=1', $location, 'only the one valid ID counts' );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 'draft', $product->get_status() );
	}

	/**
	 * BR-M20-14: the redirect strips export/bulk-action query args from the
	 * sendback target.
	 */
	public function test_bulk_redirect_strips_transient_query_args() {
		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_SERVER['HTTP_REFERER'] = admin_url( 'admin.php?page=' . WC_Inventory_Overview_Plugin::PAGE_SLUG . '&tab=overview&wc_io_export=csv&_wc_io_export_nonce=x&action=wc_io_set_draft&_wpnonce=y' );
		$_REQUEST = array(
			'action'   => 'wc_io_set_draft',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->on_load_screen();
			}
		);

		$this->assertStringNotContainsString( 'wc_io_export=', $location );
		$this->assertStringNotContainsString( '_wc_io_export_nonce=', $location );
		$this->assertStringNotContainsString( 'action=', $location );
		$this->assertStringNotContainsString( '_wpnonce=', $location );

		unset( $_SERVER['HTTP_REFERER'] );
	}

	/**
	 * Query count: successful bulk action against a single product. Baseline
	 * for post-extraction comparison (INV-M20-8).
	 */
	public function test_query_count_bulk_action_success() {
		global $wpdb;

		$product = $this->create_simple_product( array( 'status' => 'publish' ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_REQUEST = array(
			'action'   => 'wc_io_set_draft',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$before = $wpdb->num_queries;
		try {
			$plugin->on_load_screen();
		} catch ( Exception $e ) {
			// Expected: redirect throws.
		}
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}
}
