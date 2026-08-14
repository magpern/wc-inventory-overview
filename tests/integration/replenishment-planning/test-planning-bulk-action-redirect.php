<?php
/**
 * M24 WP-M24-6: "Plan replenishment" bulk-action redirect (§10/§10.1/§10.2),
 * reusing Test_WC_IO_Overview_Bulk_Action_Characterization's exact harness
 * pattern (capture wp_redirect via a throwing filter, call on_load_screen()).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Planning_Bulk_Action_Redirect extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var string|null
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->redirect_to = null;

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

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
		return wp_create_nonce( 'bulk-wc-inventory-items' );
	}

	/**
	 * BR-M24-12: a simple-product selection redirects (stateless GET, PRG)
	 * to the Planning tab with item_ids, zero mutation.
	 */
	public function test_redirect_to_planning_tab_with_item_ids() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'page=' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG, $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING, $location );
		$this->assertStringContainsString( 'item_ids=' . $product->get_id(), $location );
	}

	/**
	 * Multiple selected ids are comma-joined in item_ids. Order is derived
	 * from Repository::query_products()'s own default ordering (not
	 * necessarily the input selection order) -- harmless, since both the
	 * scoped discovery pass and the Planning tab's own §10.3 display sort
	 * re-derive everything fresh regardless of item_ids order; this test
	 * asserts set-equality, not sequence.
	 */
	public function test_multiple_ids_comma_joined() {
		$p1 = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$p2 = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $p1->get_id(), $p2->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$this->assertMatchesRegularExpression( '/item_ids=[0-9]+,[0-9]+/', $location, 'item_ids must be comma-joined.' );
		$ids = array();
		if ( preg_match( '/item_ids=([0-9,]+)/', $location, $m ) ) {
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
		}
		$this->assertEqualsCanonicalizing( array( $p1->get_id(), $p2->get_id() ), $ids );
	}

	/**
	 * §10: zero mutation -- the bulk action performs no product write.
	 */
	public function test_zero_mutation() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1, 'status' => 'publish' ) );
		$before_status = $product->get_status();
		$before_stock  = $product->get_stock_quantity();

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$after = wc_get_product( $product->get_id() );
		$this->assertSame( $before_status, $after->get_status() );
		$this->assertEqualsWithDelta( (float) $before_stock, (float) $after->get_stock_quantity(), 0.0001 );
	}

	/**
	 * BR-M24-15: reuses the existing bulk-wc-inventory-items nonce -- no new
	 * nonce action, missing nonce is a silent no-op.
	 */
	public function test_missing_nonce_silent_no_op() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action' => 'wc_io_plan_replenishment',
			'post'   => array( $product->get_id() ),
		);

		ob_start();
		$controller->on_load_screen();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertNull( $this->redirect_to );
	}

	/**
	 * BR-M24-13/15: an invalid nonce dies (via the shared check_admin_referer()
	 * call already guarding every bulk action, not a new mechanism).
	 */
	public function test_invalid_nonce_dies() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
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
			$controller->on_load_screen();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}

	/**
	 * §17 mandatory server-side re-gate: an edit_products-only user who
	 * somehow submits this action anyway (e.g. crafted POST) is denied,
	 * independent of the List_Table visibility gating (which is UX-only).
	 */
	public function test_edit_products_only_user_denied_independent_of_visibility() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $product->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
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
			$controller->on_load_screen();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}
}
