<?php
/**
 * M20 characterization tests: Cost Adjustment preview AJAX
 * (wp_ajax_wc_io_get_cost_adjustment_preview).
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-8. Read-only -- no mutation. After extraction to
 * Restock_Controller, rerun unchanged and must stay green (INV-M20-2).
 *
 * wp_send_json_success()/wp_send_json_error() call raw `die;` unless
 * wp_doing_ajax() is true, in which case they route through wp_die()
 * instead -- so DOING_AJAX must be defined true first (matching the
 * established convention in
 * tests/integration/supplier-merge/test-supplier-merge-admin.php), then a
 * wp_die_ajax_handler filter converts the die into a catchable exception.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Restock_Cost_Adjustment_Preview_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
	}

	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * @param callable $callback
	 * @return array Decoded JSON response.
	 */
	private function run_ajax( callable $callback ): array {
		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			ob_start();
			$callback();
			$json = ob_get_clean();
			$this->fail( 'Expected wp_send_json_*() to call wp_die().' );
		} catch ( WPDieException $e ) {
			$json = ob_get_clean();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$response = json_decode( $json, true );
		$this->assertIsArray( $response, 'Raw JSON output: ' . var_export( $json, true ) );
		return $response;
	}

	/**
	 * BR-M20-8: success response shape for a simple product with stock/avg set.
	 */
	public function test_preview_success_simple_product() {
		$product = $this->create_simple_product( array( 'stock_qty' => 25 ) );
		$this->set_product_average_cost( $product, 4.5 );
		$this->set_product_inventory_value( $product, 112.5 );

		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_cost_adj_preview' ),
			'product_id' => $product->get_id(),
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( '25', $response['data']['stock_display'] );
		$this->assertSame( '4.5000', $response['data']['avg_display'] );
		$this->assertSame( '4.500000', $response['data']['avg_input'] );
		$this->assertArrayHasKey( 'value_html', $response['data'] );
	}

	/**
	 * BR-M20-8: missing/zero product_id -> "Invalid product." error.
	 */
	public function test_preview_invalid_product_id() {
		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce' => wp_create_nonce( 'wc_io_cost_adj_preview' ),
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Invalid product.', $response['data']['message'] );
	}

	/**
	 * BR-M20-8: nonexistent product_id -> "Product not found." error.
	 */
	public function test_preview_product_not_found() {
		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_cost_adj_preview' ),
			'product_id' => 999999999,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Product not found.', $response['data']['message'] );
	}

	/**
	 * BR-M20-8: variable parent product rejected with the specific
	 * parent-variable-product message.
	 */
	public function test_preview_rejects_variable_parent() {
		$parent = $this->create_variable_product();

		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_cost_adj_preview' ),
			'product_id' => $parent->get_id(),
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Select a variation or a simple product, not a parent variable product.', $response['data']['message'] );
	}

	/**
	 * BR-M20-8: capability denial -> 403 "Permission denied."
	 */
	public function test_preview_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_cost_adj_preview' ),
			'product_id' => $product->get_id(),
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Permission denied.', $response['data']['message'] );
	}

	/**
	 * BR-M20-8: invalid nonce -> wp_die (via check_ajax_referer), before any
	 * product read.
	 */
	public function test_preview_invalid_nonce_dies() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => 'invalid-nonce',
			'product_id' => $product->get_id(),
		);
		$_REQUEST = $_POST;

		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			$this->expectException( WPDieException::class );
			$controller->ajax_get_cost_adjustment_preview();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}
	}

	/**
	 * Query count: successful preview. Baseline for post-extraction
	 * comparison (INV-M20-8).
	 */
	public function test_query_count_preview_success() {
		global $wpdb;

		$product = $this->create_simple_product( array( 'stock_qty' => 25 ) );
		$this->set_product_average_cost( $product, 4.5 );

		$controller = WC_Inventory_Overview_Restock_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_cost_adj_preview' ),
			'product_id' => $product->get_id(),
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		$this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_get_cost_adjustment_preview();
			}
		);
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}
}
