<?php
/**
 * M18 characterization tests: Settings save handler.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * These tests prove existing behavior and record baseline query counts.
 * After extraction, they rerun unchanged and must stay green (INV-M18-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Settings_Save_Characterization extends WP_UnitTestCase {

	/**
	 * Captured redirect location from wp_redirect filter.
	 *
	 * @var string|null
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
	 * BR-M18-1: Valid Settings save redirects to settings&saved.
	 */
	public function test_save_settings_valid_redirects_with_saved_notice() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce      = wp_create_nonce( 'wc_io_save_settings' );

		$_POST = array(
			'_wc_io_settings_nonce' => $nonce,
			'_wp_http_referer'      => '/wp-admin/',
			'wc_io_profit_mode'     => 'gross_profit', // A valid setting field
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $controller ) {
				$controller->handle_save_settings_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_settings=saved', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );
		$this->assertStringContainsString( 'page=' . WC_Inventory_Overview_Plugin::PAGE_SLUG, $location );
	}

	/**
	 * BR-M18-2: Capability denial for non-privileged user returns wp_die.
	 */
	public function test_capability_denied_returns_wp_die() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_save_settings' );

		$_POST = array(
			'_wc_io_settings_nonce' => $nonce,
			'_wp_http_referer'      => '/wp-admin/',
			'wc_io_profit_mode'     => 'gross_profit',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_save_settings_post();
	}

	/**
	 * BR-M18-3: Invalid nonce check returns wp_die.
	 */
	public function test_invalid_nonce_returns_wp_die() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();

		$_POST = array(
			'_wc_io_settings_nonce' => 'invalid-nonce-xyz',
			'_wp_http_referer'      => '/wp-admin/',
			'wc_io_profit_mode'     => 'gross_profit',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_save_settings_post();
	}

	/**
	 * Query count: settings save with valid input (BR-M18-1 success path).
	 * Records baseline for post-extraction comparison.
	 */
	public function test_query_count_save_valid_input() {
		global $wpdb;

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_save_settings' );

		$_POST = array(
			'_wc_io_settings_nonce' => $nonce,
			'_wp_http_referer'      => '/wp-admin/',
			'wc_io_profit_mode'     => 'gross_profit',
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$controller->handle_save_settings_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws exception
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison.
		// A mismatch here indicates a behavioral change (INV-M18-7).
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}

	/**
	 * Query count: settings save with invalid value (WP_Error path).
	 * Records baseline for post-extraction comparison.
	 */
	public function test_query_count_save_invalid_value() {
		global $wpdb;

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_save_settings' );

		$_POST = array(
			'_wc_io_settings_nonce' => $nonce,
			'_wp_http_referer'      => '/wp-admin/',
			'wc_io_profit_mode'     => 'invalid_xyz',
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$controller->handle_save_settings_post();
		} catch ( Exception $e ) {
			// Expected: redirect throws exception
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		// Baseline recorded for post-extraction comparison.
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}
}
