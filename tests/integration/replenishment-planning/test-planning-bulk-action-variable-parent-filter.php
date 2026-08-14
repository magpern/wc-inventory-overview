<?php
/**
 * M24 WP-M24-6: variable-parent bulk-action filter (§10.2, BR-M24-18/23,
 * INV-M24-15) -- the bounded `include`-based lookup, never a per-selected-id
 * wc_get_product() loop.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Planning_Bulk_Action_Variable_Parent_Filter extends WC_Inventory_Overview_Test_Case {

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
	 * BR-M24-18: a variable-parent selection alongside a simple product is
	 * dropped, counted in wc_io_plan_skipped, and the simple product still
	 * proceeds.
	 */
	public function test_variable_parent_dropped_simple_product_survives() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		$simple   = $this->create_simple_product( array( 'stock_qty' => 1 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $variable->get_id(), $simple->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'item_ids=' . $simple->get_id(), $location );
		$this->assertStringNotContainsString( 'item_ids=' . $variable->get_id(), $location );
		$this->assertStringContainsString( 'wc_io_plan_skipped=1', $location );
	}

	/**
	 * Concrete variations survive the filter (only the parent's OWN row id
	 * is ever rejected).
	 */
	public function test_variation_survives_filter() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh     = wc_get_product( $variable->get_id() );
		$children  = $fresh->get_children();
		$variation_id = (int) $children[0];

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $variation_id ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$this->assertStringContainsString( 'item_ids=' . $variation_id, $location );
		$this->assertStringNotContainsString( 'wc_io_plan_skipped', $location );
	}

	/**
	 * BR-M24-18: if EVERY selected id is a variable parent, redirect back
	 * to Inventory Overview with a failure notice instead of proceeding to
	 * an empty Planning tab.
	 */
	public function test_all_variable_parents_redirects_to_overview_with_failure() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => array( $variable->get_id() ),
			'_wpnonce' => $this->bulk_nonce(),
		);

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->on_load_screen();
			}
		);

		$this->assertStringNotContainsString( 'tab=' . WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING, $location, 'Must not proceed to an empty Planning tab.' );
		$this->assertStringContainsString( 'wc_io_plan_error=all_filtered', $location );
	}

	/**
	 * INV-M24-15/BR-M24-23: the filter mechanism is the bounded `include`
	 * lookup, not a per-selected-id wc_get_product() loop -- proven by
	 * observing the SQL query pattern issued during the handler (a bounded
	 * Repository call, never N individual single-post lookups).
	 */
	public function test_filter_uses_bounded_query_not_per_id_loop() {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->create_simple_product( array( 'stock_qty' => 1 ) )->get_id();
		}
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		$ids[]    = $variable->get_id();

		global $wpdb;
		$post_queries = array();
		$counter      = static function ( $query ) use ( $wpdb, &$post_queries ) {
			if ( false !== stripos( $query, 'SELECT' ) && false !== strpos( $query, $wpdb->posts ) ) {
				$post_queries[] = $query;
			}
			return $query;
		};

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_REQUEST   = array(
			'action'   => 'wc_io_plan_replenishment',
			'post'     => $ids,
			'_wpnonce' => $this->bulk_nonce(),
		);

		add_filter( 'query', $counter );
		try {
			$controller->on_load_screen();
			$this->fail( 'Expected redirect.' );
		} catch ( Exception $e ) {
			// expected redirect throw.
		}
		remove_filter( 'query', $counter );

		// 6 selected ids: a per-ID loop would issue >= 6 separate post
		// lookups. The bounded include mechanism issues a small, fixed
		// number regardless of selection size.
		$this->assertLessThan( 6, count( $post_queries ), 'Must not issue one post-table query per selected id (no per-ID loop).' );
	}
}
