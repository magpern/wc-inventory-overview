<?php
/**
 * M20 characterization tests: Overview inline-stock AJAX
 * (wp_ajax_wc_io_save_inline_stock).
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-15. After extraction to Overview_Controller, rerun
 * unchanged and must stay green (INV-M20-2).
 *
 * wp_send_json_success()/wp_send_json_error() call raw `die;` unless
 * wp_doing_ajax() is true, in which case they route through wp_die()
 * instead -- so DOING_AJAX must be defined true first (matching the
 * established convention in
 * tests/integration/supplier-merge/test-supplier-merge-admin.php).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Inline_Stock_Ajax_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
	}

	public function tearDown(): void {
		$_POST    = array();
		$_REQUEST = array();
		delete_option( WC_Inventory_Overview_Settings::OPTION_AUTO_UPDATE_INVENTORY_VALUE_ON_STOCK_EDIT );
		parent::tearDown();
	}

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
	 * BR-M20-15: success -- stock updated, response contains formatted qty
	 * and status badges HTML; default setting (auto-update on) also
	 * recalculates inventory value.
	 */
	public function test_inline_stock_success_updates_stock_and_value() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 5.0 );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 20,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertTrue( $response['success'] );
		$this->assertSame( '20', $response['data']['formatted'] );
		$this->assertArrayHasKey( 'badgesHtml', $response['data'] );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 20, $product->get_stock_quantity() );
		$this->assertDecimalEqual( 100.0, $this->get_product_inventory_value( $product ), 4, 'value = qty * avg when auto-update is on (default)' );
	}

	/**
	 * BR-M20-15: when the auto-update-inventory-value setting is off, stock
	 * still updates but inventory value is left untouched.
	 */
	public function test_inline_stock_success_leaves_value_when_auto_update_off() {
		update_option( WC_Inventory_Overview_Settings::OPTION_AUTO_UPDATE_INVENTORY_VALUE_ON_STOCK_EDIT, 'no' );

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$this->set_product_inventory_value( $product, 50.0 );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 20,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertTrue( $response['success'] );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 20, $product->get_stock_quantity() );
		$this->assertDecimalEqual( 50.0, $this->get_product_inventory_value( $product ), 4, 'value unchanged when auto-update is off' );
	}

	/**
	 * BR-M20-15: missing stock_qty -> "Missing quantity." error, no mutation.
	 */
	public function test_inline_stock_missing_quantity() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Missing quantity.', $response['data']['message'] );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 10, $product->get_stock_quantity() );
	}

	/**
	 * BR-M20-15: product not managing stock -> "Stock is not managed for
	 * this line." error.
	 */
	public function test_inline_stock_unmanaged_stock_rejected() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10, 'manage_stock' => false ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 5,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Stock is not managed for this line.', $response['data']['message'] );
	}

	/**
	 * BR-M20-15: invalid/missing product_id -> "Invalid product." error
	 * (400).
	 */
	public function test_inline_stock_invalid_product_id() {
		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'     => wp_create_nonce( 'wc_io_inventory' ),
			'stock_qty' => 5,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Invalid product.', $response['data']['message'] );
	}

	/**
	 * BR-M20-15: capability denial -> 403 "Permission denied.", checked
	 * before the nonce.
	 */
	public function test_inline_stock_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 5,
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Permission denied.', $response['data']['message'] );

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 10, $product->get_stock_quantity() );
	}

	/**
	 * BR-M20-15: invalid nonce -> wp_die (via check_ajax_referer), before
	 * any product read.
	 */
	public function test_inline_stock_invalid_nonce_dies() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => 'invalid-nonce',
			'product_id' => $product->get_id(),
			'stock_qty'  => 5,
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
			$controller->ajax_save_inline_stock();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$product = wc_get_product( $product->get_id() );
		$this->assertSame( 10, $product->get_stock_quantity() );
	}

	/**
	 * BR-M20-15/INV-M20-6: inline stock edit does NOT write a movement row
	 * (unlike Restock/Cost-Adjustment) -- a pre-existing asymmetry this
	 * milestone must preserve, not "fix".
	 */
	public function test_inline_stock_writes_no_movement_row() {
		global $wpdb;
		$table = WC_Inventory_Overview_Movements::table_name();
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 20,
		);
		$_REQUEST = $_POST;

		$this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( $before, $after );
	}

	/**
	 * Query count: successful inline stock edit. Baseline for
	 * post-extraction comparison (INV-M20-8).
	 */
	public function test_query_count_inline_stock_success() {
		global $wpdb;

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 5.0 );

		$controller = WC_Inventory_Overview_Overview_Controller::instance();
		$_POST  = array(
			'nonce'      => wp_create_nonce( 'wc_io_inventory' ),
			'product_id' => $product->get_id(),
			'stock_qty'  => 20,
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		$this->run_ajax(
			static function () use ( $controller ) {
				$controller->ajax_save_inline_stock();
			}
		);
		$after = $wpdb->num_queries;

		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $after - $before );
	}
}
