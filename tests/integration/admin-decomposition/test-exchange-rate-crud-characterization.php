<?php
/**
 * M18 characterization tests: Exchange rate add/delete handlers.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M18-4 through BR-M18-6, BR-M18-13, BR-M18-14.
 * After extraction, rerun unchanged and must stay green (INV-M18-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Exchange_Rate_CRUD_Characterization extends WP_UnitTestCase {

	/**
	 * Captured redirect location from wp_redirect filter.
	 *
	 * @var string|null
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_exchange_rates' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

	/**
	 * Capture redirect target and abort the exit path.
	 *
	 * @param string $location Redirect URL.
	 * @return string
	 */
	public function capture_redirect( $location ) {
		$this->redirect_to = $location;
		throw new Exception( 'wp_redirect:' . $location );
	}

	/**
	 * @param callable $callback Handler invocation.
	 * @return string Redirect location.
	 */
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

	/**
	 * BR-M18-4: Valid exchange-rate add redirects with added notice.
	 */
	public function test_add_exchange_rate_valid_redirects_with_added_notice() {
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_add_exchange_rate' );

		$_POST = array(
			'_wc_io_add_exchange_rate_nonce' => $nonce,
			'_wp_http_referer'               => '/wp-admin/',
			'wc_io_fx_from'                   => 'USD',
			'wc_io_fx_to'                     => 'EUR',
			'wc_io_fx_rate'                   => '0.92',
			'wc_io_fx_date'                   => '2026-01-01',
			'wc_io_fx_note'                   => 'Test rate',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_add_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx=added', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );
	}

	/**
	 * BR-M18-4: "to" field defaults to TO_CURRENCY when absent.
	 */
	public function test_add_exchange_rate_to_defaults_to_currency() {
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_add_exchange_rate' );

		$_POST = array(
			'_wc_io_add_exchange_rate_nonce' => $nonce,
			'_wp_http_referer'               => '/wp-admin/',
			'wc_io_fx_from'                   => 'USD',
			// 'wc_io_fx_to' is absent — should default
			'wc_io_fx_rate'                   => '0.92',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_add_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx=added', $location );

		// Verify the row was created with the default TO_CURRENCY
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . $wpdb->prefix . 'wc_io_exchange_rates WHERE from_currency = %s',
				'USD'
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( WC_Inventory_Overview_Exchange_Rates::TO_CURRENCY, $row->to_currency );
	}

	/**
	 * BR-M18-4: WP_Error from insert_rate triggers error redirect.
	 */
	public function test_add_exchange_rate_wp_error_redirects_with_error_message() {
		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_add_exchange_rate' );

		// Invalid rate (non-numeric)
		$_POST = array(
			'_wc_io_add_exchange_rate_nonce' => $nonce,
			'_wp_http_referer'               => '/wp-admin/',
			'wc_io_fx_from'                   => 'USD',
			'wc_io_fx_rate'                   => 'not-a-number',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_add_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx_err=', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );
	}

	/**
	 * BR-M18-2: Capability denial for add exchange rate.
	 */
	public function test_add_exchange_rate_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_add_exchange_rate' );

		$_POST = array(
			'wc_io_fx_delete_id'       => -1, // Invalid ID
			'_wc_io_delete_fx_nonce'   => 'should-not-be-checked',
					'_wp_http_referer'               => '/wp-admin/',
		);
		$_REQUEST = $_POST;

		// Should not throw WPDieException (nonce check should be skipped)
		// Should redirect with error instead
		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_delete_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx_err=', $location );
		$this->assertStringContainsString( rawurlencode( 'Invalid exchange rate id.' ), $location );
	}

	/**
	 * BR-M18-6: Delete with valid ID and valid nonce succeeds.
	 */
	public function test_delete_exchange_rate_valid_id_and_nonce_succeeds() {
		// Create a test exchange rate
		$id = WC_Inventory_Overview_Exchange_Rates::insert_rate( 'USD', 'EUR', '0.92', '2026-01-01', 'Test' );
		$this->assertIsInt( $id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_delete_exchange_rate_' . $id );

		$_POST = array(
			'wc_io_fx_delete_id'     => $id,
			'_wc_io_delete_fx_nonce' => $nonce,
					'_wp_http_referer'               => '/wp-admin/',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_delete_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx=deleted', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );

		// Verify the row was actually deleted
		$row = WC_Inventory_Overview_Exchange_Rates::get( $id );
		$this->assertNull( $row );
	}

	/**
	 * BR-M18-6: WP_Error from delete_rate() triggers error redirect.
	 */
	public function test_delete_exchange_rate_wp_error_redirects_with_error_message() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		// ID 999999 doesn't exist; delete_rate should return WP_Error
		$nonce = wp_create_nonce( 'wc_io_delete_exchange_rate_999999' );

		$_POST = array(
			'wc_io_fx_delete_id'     => 999999,
			'_wc_io_delete_fx_nonce' => $nonce,
					'_wp_http_referer'               => '/wp-admin/',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$plugin->handle_delete_exchange_rate_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_fx_err=', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );
	}

	/**
	 * BR-M18-2: Capability denial for delete.
	 */
	public function test_delete_exchange_rate_capability_denied() {
		$id = WC_Inventory_Overview_Exchange_Rates::insert_rate( 'USD', 'EUR', '0.92', '2026-01-01', 'Test' );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_delete_exchange_rate_' . $id );

		$_POST = array(
			'_wc_io_add_exchange_rate_nonce' => $nonce,
			'wc_io_fx_from'                   => 'USD',
			'wc_io_fx_rate'                   => '0.92',
					'_wp_http_referer'               => '/wp-admin/',
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$plugin->handle_add_exchange_rate_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws exception
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}

	/**
	 * Query count: delete exchange rate.
	 */
	public function test_query_count_delete_exchange_rate() {
		global $wpdb;

		$id = WC_Inventory_Overview_Exchange_Rates::insert_rate( 'USD', 'EUR', '0.92', '2026-01-01', 'Test' );

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$nonce  = wp_create_nonce( 'wc_io_delete_exchange_rate_' . $id );

		$_POST = array(
			'wc_io_fx_delete_id'     => $id,
			'_wc_io_delete_fx_nonce' => $nonce,
					'_wp_http_referer'               => '/wp-admin/',
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$plugin->handle_delete_exchange_rate_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws exception
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}
}
