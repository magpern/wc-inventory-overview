<?php
/**
 * Integration tests for Stock Adjustment admin HTTP layer (M27).
 *
 * PRG redirects, capability guards, nonce enforcement, preview AJAX.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Stock_Adjustment_Admin extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var string|null
	 */
	private $redirect_to = null;

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Stock_Adjustment_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Stock_Adjustments::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Stock_Adjustment_Numbering::OPTION_KEY );

		$this->redirect_to = null;
		$this->admin_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10, 1 );
	}

	public function tearDown(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 10 );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		if ( ! defined( 'DOING_AJAX' ) ) {
			// DOING_AJAX is a constant; leave as-is if another test set it.
		}
		parent::tearDown();
	}

	/**
	 * @param string $location Redirect URL.
	 * @return string
	 */
	public function capture_redirect( $location ) {
		$this->redirect_to = $location;
		throw new Exception( 'wp_redirect:' . $location );
	}

	/**
	 * @param callable $callback Handler.
	 * @return string Redirect location.
	 */
	private function run_expecting_redirect( callable $callback ): string {
		try {
			$callback();
			$this->fail( 'Expected wp_safe_redirect/exit' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'wp_redirect:', $e->getMessage() );
		}
		$this->assertNotEmpty( $this->redirect_to );
		return (string) $this->redirect_to;
	}

	/**
	 * @return int Draft id with one line and note.
	 */
	private function create_draft_with_line(): int {
		$product = $this->create_simple_product( array( 'stock_qty' => 12 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$this->set_product_inventory_value( $product, 60.0 );
		$product->save();

		$id = WC_Inventory_Overview_Stock_Adjustment_Service::create_draft();
		$this->assertIsInt( $id );
		WC_Inventory_Overview_Stock_Adjustment_Service::save_draft_from_post(
			$id,
			array(
				'note'  => 'Photography samples',
				'lines' => array(
					array(
						'product_id'   => $product->get_id(),
						'variation_id' => 0,
						'qty'          => 2,
					),
				),
			)
		);
		return $id;
	}

	/**
	 * Happy-path post admin-post: PRG redirect with posted notice.
	 */
	public function test_handle_post_prg_success_notice() {
		$id = $this->create_draft_with_line();

		$_POST = array(
			'action'                  => 'wc_io_sa_post',
			'sa_id'                   => $id,
			'wc_io_sa_confirm'        => '1',
			'wc_io_sa_request_token'  => WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' ),
			'wc_io_sa_post_nonce'     => wp_create_nonce( 'wc_io_sa_post_' . $id ),
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () {
				WC_Inventory_Overview_Stock_Adjustment_Admin::handle_post();
			}
		);

		$this->assertStringContainsString( WC_Inventory_Overview_Stock_Adjustment_Admin::NOTICE_QUERY . '=posted', $location );
		$this->assertStringContainsString( 'sa_action=view', $location );

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'posted', $header['status'] );
	}

	/**
	 * Capability-denied post handler dies with 403.
	 */
	public function test_handle_post_capability_denied() {
		$id = $this->create_draft_with_line();

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$_POST = array(
			'action'                 => 'wc_io_sa_post',
			'sa_id'                  => $id,
			'wc_io_sa_confirm'       => '1',
			'wc_io_sa_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' ),
			'wc_io_sa_post_nonce'    => wp_create_nonce( 'wc_io_sa_post_' . $id ),
		);

		$this->expectException( WPDieException::class );
		WC_Inventory_Overview_Stock_Adjustment_Admin::handle_post();
	}

	/**
	 * Bad nonce rejected before service runs.
	 */
	public function test_handle_post_bad_nonce_rejected() {
		$id = $this->create_draft_with_line();

		$_POST = array(
			'action'                 => 'wc_io_sa_post',
			'sa_id'                  => $id,
			'wc_io_sa_confirm'       => '1',
			'wc_io_sa_request_token' => WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' ),
			'wc_io_sa_post_nonce'    => 'invalid',
		);

		$this->expectException( WPDieException::class );
		WC_Inventory_Overview_Stock_Adjustment_Admin::handle_post();

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		$this->assertSame( 'draft', $header['status'] );
	}

	/**
	 * @param callable $callback AJAX handler.
	 * @return array Decoded JSON response.
	 */
	private function run_ajax( callable $callback ): array {
		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		add_filter( 'wp_die_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			ob_start();
			$callback();
			$json = ob_get_clean();
			$this->fail( 'Expected wp_send_json_*() to call wp_die().' );
		} catch ( WPDieException $e ) {
			$json = ob_get_clean();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$response = json_decode( $json, true );
		$this->assertIsArray( $response, 'Raw JSON output: ' . var_export( $json, true ) );
		return $response;
	}


	/**
	 * Preview AJAX: valid nonce + cap returns success JSON.
	 */
	public function test_preview_ajax_happy_path() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$product = $this->create_simple_product( array( 'stock_qty' => 8 ) );
		$this->set_product_average_cost( $product, 4.0 );
		$this->set_product_inventory_value( $product, 32.0 );
		$product->save();

		$_POST = array(
			'action'  => 'wc_io_stock_adjustment_preview',
			'nonce'   => wp_create_nonce( 'wc_io_sa_preview' ),
			'item_id' => $product->get_id(),
			'qty'     => '3',
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () {
				WC_Inventory_Overview_Stock_Adjustment_Controller::instance()->ajax_preview_line();
			}
		);

		$this->assertTrue( $response['success'] );
		$this->assertDecimalEqual( 8.0, (float) $response['data']['on_hand'], 4 );
		$this->assertDecimalEqual( 12.0, (float) $response['data']['value_removed'], 4 );
	}

	/**
	 * Preview AJAX: invalid nonce dies.
	 */
	public function test_preview_ajax_invalid_nonce_dies() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$product = $this->create_simple_product( array( 'stock_qty' => 5 ) );
		$this->set_product_average_cost( $product, 2.0 );
		$product->save();

		$_POST = array(
			'action'  => 'wc_io_stock_adjustment_preview',
			'nonce'   => 'bad-nonce',
			'item_id' => $product->get_id(),
			'qty'     => '1',
		);

		$this->expectException( WPDieException::class );
		WC_Inventory_Overview_Stock_Adjustment_Controller::instance()->ajax_preview_line();
	}

	/**
	 * Preview AJAX: subscriber denied.
	 */
	public function test_preview_ajax_capability_denied() {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$product = $this->create_simple_product( array( 'stock_qty' => 5 ) );

		$_POST = array(
			'action'  => 'wc_io_stock_adjustment_preview',
			'nonce'   => wp_create_nonce( 'wc_io_sa_preview' ),
			'item_id' => $product->get_id(),
			'qty'     => '1',
		);
		$_REQUEST = $_POST;

		$response = $this->run_ajax(
			static function () {
				WC_Inventory_Overview_Stock_Adjustment_Controller::instance()->ajax_preview_line();
			}
		);

		$this->assertFalse( $response['success'] );
	}

	/**
	 * UI exposes MAX_LINES to stock-adjustment.js (server-side gate for 101st line).
	 */
	public function test_enqueue_localizes_max_lines_for_ui_gate() {
		$controller = WC_Inventory_Overview_Stock_Adjustment_Controller::instance();
		$controller->init();

		$_GET = array(
			'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'  => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
		);
		$_REQUEST = $_GET;

		$controller->enqueue_assets( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG );

		$script = wp_scripts()->registered['wc-io-stock-adjustment'] ?? null;
		$this->assertNotNull( $script, 'wc-io-stock-adjustment script must be registered.' );

		global $wp_scripts;
		$data = $wp_scripts->get_data( 'wc-io-stock-adjustment', 'data' );
		$this->assertStringContainsString(
			'"maxLines":"' . WC_Inventory_Overview_Stock_Adjustment_Service::MAX_LINES . '"',
			(string) $data
		);
	}

	/**
	 * Service rejects 101 lines (UI mirrors MAX_LINES via localized script).
	 */
	public function test_save_rejects_more_than_max_lines() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1000 ) );
		$this->set_product_average_cost( $product, 1.0 );
		$product->save();

		$id = WC_Inventory_Overview_Stock_Adjustment_Service::create_draft();
		$this->assertIsInt( $id );

		$lines = array();
		for ( $i = 0; $i <= WC_Inventory_Overview_Stock_Adjustment_Service::MAX_LINES; $i++ ) {
			$lines[] = array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
				'qty'          => 0.0001,
			);
		}

		$result = WC_Inventory_Overview_Stock_Adjustment_Service::save_draft_from_post(
			$id,
			array(
				'note'  => 'Too many lines',
				'lines' => $lines,
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_sa_max_lines', $result->get_error_code() );
	}
}
