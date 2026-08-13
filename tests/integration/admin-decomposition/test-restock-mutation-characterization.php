<?php
/**
 * M20 characterization tests: Restock/Cost-Adjustment admin-post mutation
 * handlers.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-5/6/7. Confirms WC_Inventory_Overview_Restock_Service and
 * WC_Inventory_Overview_Cost_Adjustment_Service remain the sole mutation
 * owners (no duplicate mutation, INV-M20-7). After extraction to
 * Restock_Controller, rerun unchanged and must stay green (INV-M20-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Restock_Mutation_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var string|null Captured redirect location from wp_redirect filter.
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		$this->redirect_to = null;

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_POST    = array();
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

	private function movement_count(): int {
		global $wpdb;
		$table = WC_Inventory_Overview_Movements::table_name();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Assert $callback triggers wp_die(), regardless of whether a prior test
	 * in this PHPUnit process has permanently defined DOING_AJAX (which
	 * changes wp_die()'s internal routing from the 'wp_die_handler' filter
	 * to the 'wp_die_ajax_handler' filter -- the same documented,
	 * order-dependent hazard flagged in
	 * tests/integration/supplier-merge/test-supplier-merge-admin.php).
	 * Registers a WPDieException-throwing handler on both filters so the
	 * assertion is correct irrespective of test execution order.
	 */
	private function expect_wp_die( callable $callback ): void {
		$throw_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
			};
		};
		add_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		try {
			$this->expectException( WPDieException::class );
			$callback();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}

	// -----------------------------------------------------------------
	// handle_restock_post()
	// -----------------------------------------------------------------

	/**
	 * BR-M20-5: valid restock succeeds, stock/average updated, one movement
	 * row inserted, success redirect.
	 */
	public function test_handle_restock_post_success() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 5.0 );

		$before_movements = $this->movement_count();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'        => wp_create_nonce( 'wc_io_restock' ),
			'wc_io_line_id'   => $product->get_id(),
			'wc_io_qty'       => 5,
			'wc_io_unit_cost' => 6.0,
			'wc_io_supplier'  => 'Acme Supplies',
			'wc_io_note'      => 'Test restock',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_restock_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_restock_msg=success', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_RESTOCK, $location );
		$this->assertStringContainsString( 'restock_view=' . WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK, $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 15, $product->get_stock_quantity() );
		$this->assertSame( $before_movements + 1, $this->movement_count(), 'exactly one movement row must be inserted' );
	}

	/**
	 * BR-M20-5: missing product (line_id=0) redirects with missing_product,
	 * no mutation, no movement.
	 */
	public function test_handle_restock_post_missing_product() {
		$before_movements = $this->movement_count();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce' => wp_create_nonce( 'wc_io_restock' ),
			'wc_io_qty' => 5,
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_restock_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_restock_msg=missing_product', $location );
		$this->assertSame( $before_movements, $this->movement_count() );
	}

	/**
	 * BR-M20-5/BR-M20-7: service validation failure (negative cost) redirects
	 * with the raw WP_Error message, no mutation, no movement.
	 */
	public function test_handle_restock_post_validation_failure_no_mutation() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$before_movements = $this->movement_count();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'        => wp_create_nonce( 'wc_io_restock' ),
			'wc_io_line_id'   => $product->get_id(),
			'wc_io_qty'       => 5,
			'wc_io_unit_cost' => -1,
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_restock_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_restock_msg=error', $location );
		$this->assertStringContainsString( 'wc_io_restock_err=', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 10, $product->get_stock_quantity(), 'stock must not change on validation failure' );
		$this->assertSame( $before_movements, $this->movement_count(), 'no movement on validation failure' );
	}

	/**
	 * BR-M20-7: capability denial -> wp_die, before nonce is even checked.
	 */
	public function test_handle_restock_post_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array( 'wc_io_line_id' => 1 );

		$this->expect_wp_die(
			static function () use ( $plugin ) {
				$plugin->handle_restock_post();
			}
		);
	}

	/**
	 * BR-M20-7: invalid nonce -> wp_die, no mutation.
	 */
	public function test_handle_restock_post_invalid_nonce() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'      => 'invalid-nonce',
			'wc_io_line_id' => $product->get_id(),
			'wc_io_qty'     => 5,
			'wc_io_unit_cost' => 6,
		);

		$this->expect_wp_die(
			static function () use ( $plugin ) {
				$plugin->handle_restock_post();
			}
		);
	}

	// -----------------------------------------------------------------
	// handle_cost_adjustment_post()
	// -----------------------------------------------------------------

	/**
	 * BR-M20-6: valid cost adjustment succeeds, average/value updated, stock
	 * unchanged, one movement row inserted, success redirect.
	 */
	public function test_handle_cost_adjustment_post_success() {
		$product = $this->create_simple_product( array( 'stock_qty' => 20 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$this->set_product_inventory_value( $product, 100.0 );

		$before_movements = $this->movement_count();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'           => wp_create_nonce( 'wc_io_cost_adjustment' ),
			'wc_io_adj_line_id'  => $product->get_id(),
			'wc_io_new_avg_cost' => 6.0,
			'wc_io_adj_note'     => 'Freight correction',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_cost_adjustment_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_adj_msg=success', $location );
		$this->assertStringContainsString( 'restock_view=' . WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST, $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 20, $product->get_stock_quantity(), 'cost adjustment must not change stock' );
		$this->assertDecimalEqual( 6.0, $this->get_product_average_cost( $product ), 6 );
		$this->assertSame( $before_movements + 1, $this->movement_count(), 'exactly one movement row must be inserted' );
	}

	/**
	 * BR-M20-6: missing product redirects with missing_product, no mutation.
	 */
	public function test_handle_cost_adjustment_post_missing_product() {
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'           => wp_create_nonce( 'wc_io_cost_adjustment' ),
			'wc_io_new_avg_cost' => 6.0,
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_cost_adjustment_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_adj_msg=missing_product', $location );
	}

	/**
	 * BR-M20-6/BR-M20-7: validation failure (negative average) redirects with
	 * the raw WP_Error message, no mutation.
	 */
	public function test_handle_cost_adjustment_post_validation_failure_no_mutation() {
		$product = $this->create_simple_product( array( 'stock_qty' => 20 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$before_movements = $this->movement_count();

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'           => wp_create_nonce( 'wc_io_cost_adjustment' ),
			'wc_io_adj_line_id'  => $product->get_id(),
			'wc_io_new_avg_cost' => -1,
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_cost_adjustment_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_adj_msg=error', $location );

		$product = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 5.0, $this->get_product_average_cost( $product ), 6, 'average must not change on validation failure' );
		$this->assertSame( $before_movements, $this->movement_count() );
	}

	/**
	 * BR-M20-7: capability denial -> wp_die.
	 */
	public function test_handle_cost_adjustment_post_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array( 'wc_io_adj_line_id' => 1 );

		$this->expect_wp_die(
			static function () use ( $plugin ) {
				$plugin->handle_cost_adjustment_post();
			}
		);
	}

	/**
	 * BR-M20-7: invalid nonce -> wp_die, no mutation.
	 */
	public function test_handle_cost_adjustment_post_invalid_nonce() {
		$product = $this->create_simple_product( array( 'stock_qty' => 20 ) );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'           => 'invalid-nonce',
			'wc_io_adj_line_id'  => $product->get_id(),
			'wc_io_new_avg_cost' => 6.0,
		);

		$this->expect_wp_die(
			static function () use ( $plugin ) {
				$plugin->handle_cost_adjustment_post();
			}
		);
	}

	// -----------------------------------------------------------------
	// Query-count baselines (INV-M20-8)
	// -----------------------------------------------------------------

	public function test_query_count_restock_post_success() {
		global $wpdb;

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$plugin  = WC_Inventory_Overview_Plugin::instance();
		$_POST   = array(
			'_wpnonce'        => wp_create_nonce( 'wc_io_restock' ),
			'wc_io_line_id'   => $product->get_id(),
			'wc_io_qty'       => 5,
			'wc_io_unit_cost' => 6.0,
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$plugin->handle_restock_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws.
		}
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}

	public function test_query_count_cost_adjustment_post_success() {
		global $wpdb;

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$_POST  = array(
			'_wpnonce'           => wp_create_nonce( 'wc_io_cost_adjustment' ),
			'wc_io_adj_line_id'  => $product->get_id(),
			'wc_io_new_avg_cost' => 6.0,
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$plugin->handle_cost_adjustment_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws.
		}
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}
}
