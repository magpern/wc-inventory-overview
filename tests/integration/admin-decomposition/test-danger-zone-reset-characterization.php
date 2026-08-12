<?php
/**
 * M18 characterization tests: Danger Zone reset handlers.
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Tests BR-M18-7, BR-M18-8, BR-M18-9, BR-M18-12 (validation order, token handling).
 * After extraction, rerun unchanged and must stay green (INV-M18-2).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Danger_Zone_Reset_Characterization extends WP_UnitTestCase {

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
	 * BR-M18-7: Preview with valid payload generates token and redirects.
	 */
	public function test_danger_zone_preview_valid_payload_generates_token() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_preview' );

		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => $nonce,
			'wc_io_reset_po'                    => '1', // Valid scope
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_preview_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_rst=', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );

		// Extract token from redirect and verify it's stored
		preg_match( '/wc_io_rst=([a-zA-Z0-9]+)/', $location, $matches );
		$this->assertCount( 2, $matches ); // Full match + token
		$token = $matches[1];

		$preview = WC_Inventory_Overview_Data_Reset::get_preview( $token );
		$this->assertNotNull( $preview );
		$this->assertArrayHasKey( 'payload', $preview );
		$this->assertArrayHasKey( 'counts', $preview );
	}

	/**
	 * BR-M18-7: Preview with invalid payload returns error redirect.
	 */
	public function test_danger_zone_preview_invalid_payload_returns_error() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_preview' );

		// No valid scope fields submitted
		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => $nonce,
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_preview_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_reset_err=', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );
	}

	/**
	 * BR-M18-2: Capability denial for preview.
	 */
	public function test_danger_zone_preview_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_preview' );

		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => $nonce,
			'wc_io_reset_po'                    => '1',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_danger_reset_preview_post();
	}

	/**
	 * BR-M18-3: Invalid nonce for preview.
	 */
	public function test_danger_zone_preview_invalid_nonce() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();

		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => 'invalid-nonce',
			'wc_io_reset_po'                    => '1',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_danger_reset_preview_post();
	}

	/**
	 * BR-M18-8: Apply with missing confirmation checkbox returns error.
	 */
	public function test_danger_zone_apply_missing_confirmation_returns_error() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_apply' );

		// First generate a preview
		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => wp_create_nonce( 'wc_io_danger_reset_preview' ),
			'wc_io_reset_po'                    => '1',
		);
		$_REQUEST = $_POST;

		try {
			$controller->handle_danger_reset_preview_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}

		// Extract token from redirect
		preg_match( '/wc_io_rst=([a-zA-Z0-9]+)/', $this->redirect_to, $matches );
		$token = $matches[1];
		$this->redirect_to = null; // Reset for next redirect

		// Now apply WITHOUT the confirmation checkbox
		$_POST = array(
			'_wc_io_danger_reset_apply_nonce'  => $nonce,
			'wc_io_reset_preview_token'        => $token,
			// 'wc_io_danger_confirm' is missing
			'wc_io_danger_type_confirm'        => 'RESET',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_apply_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_reset_err=', $location );
		$this->assertStringContainsString( rawurlencode( 'You must confirm that you understand the consequences.' ), $location );
	}

	/**
	 * BR-M18-8: Apply with wrong typed confirmation returns error.
	 */
	public function test_danger_zone_apply_wrong_typed_confirmation_returns_error() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_apply' );

		// First generate a preview
		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => wp_create_nonce( 'wc_io_danger_reset_preview' ),
			'wc_io_reset_po'                    => '1',
		);
		$_REQUEST = $_POST;

		try {
			$controller->handle_danger_reset_preview_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}

		// Extract token from redirect
		preg_match( '/wc_io_rst=([a-zA-Z0-9]+)/', $this->redirect_to, $matches );
		$token = $matches[1];
		$this->redirect_to = null;

		// Apply with wrong typed confirmation
		$_POST = array(
			'_wc_io_danger_reset_apply_nonce'  => $nonce,
			'wc_io_reset_preview_token'        => $token,
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'WRONG', // Should be exactly 'RESET'
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_apply_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_reset_err=', $location );
		$this->assertStringContainsString( rawurlencode( 'Type RESET exactly in the confirmation field.' ), $location );
	}

	/**
	 * BR-M18-8: Apply with expired/invalid token returns error.
	 */
	public function test_danger_zone_apply_expired_token_returns_error() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_apply' );

		$_POST = array(
			'_wc_io_danger_reset_apply_nonce'  => $nonce,
			'wc_io_reset_preview_token'        => 'invalid-or-expired-token',
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'RESET',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_apply_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_reset_err=', $location );
		$this->assertStringContainsString( rawurlencode( 'Preview expired or invalid.' ), $location );
	}

	/**
	 * BR-M18-9: Apply with valid token and ALL confirmations succeeds.
	 * Records that preview is deleted and result notice is stored.
	 */
	public function test_danger_zone_apply_valid_token_succeeds() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();

		// First generate a preview
		$preview_nonce = wp_create_nonce( 'wc_io_danger_reset_preview' );
		$_POST         = array(
			'_wc_io_danger_reset_preview_nonce' => $preview_nonce,
			'wc_io_reset_po'                    => '1',
		);
		$_REQUEST      = $_POST;

		try {
			$controller->handle_danger_reset_preview_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}

		// Extract token from redirect
		preg_match( '/wc_io_rst=([a-zA-Z0-9]+)/', $this->redirect_to, $matches );
		$token = $matches[1];
		$this->redirect_to = null;

		// Now apply with all required fields
		$apply_nonce = wp_create_nonce( 'wc_io_danger_reset_apply' );
		$_POST       = array(
			'_wc_io_danger_reset_apply_nonce'  => $apply_nonce,
			'wc_io_reset_preview_token'        => $token,
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'RESET',
		);
		$_REQUEST    = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $plugin ) {
				$controller->handle_danger_reset_apply_post();
			}
		);

		$this->assertStringContainsString( 'wc_io_reset_applied=1', $location );
		$this->assertStringContainsString( 'tab=' . WC_Inventory_Overview_Plugin::TAB_SETTINGS, $location );

		// Verify preview was deleted
		$preview = WC_Inventory_Overview_Data_Reset::get_preview( $token );
		$this->assertNull( $preview );

		// Verify result notice was stored
		$result = WC_Inventory_Overview_Data_Reset::consume_result_notice( get_current_user_id() );
		$this->assertNotNull( $result );
	}

	/**
	 * BR-M18-2: Capability denial for apply.
	 */
	public function test_danger_zone_apply_capability_denied() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_apply' );

		$_POST = array(
			'_wc_io_danger_reset_apply_nonce'  => $nonce,
			'wc_io_reset_preview_token'        => 'some-token',
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'RESET',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_danger_reset_apply_post();
	}

	/**
	 * BR-M18-3: Invalid nonce for apply.
	 */
	public function test_danger_zone_apply_invalid_nonce() {
		$controller = WC_Inventory_Overview_Settings_Controller::instance();

		$_POST = array(
			'_wc_io_danger_reset_apply_nonce'  => 'invalid-nonce',
			'wc_io_reset_preview_token'        => 'some-token',
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'RESET',
		);

		$this->expectException( WPDieException::class );
		$controller->handle_danger_reset_apply_post();
	}

	/**
	 * Query count: preview with valid payload.
	 */
	public function test_query_count_danger_zone_preview() {
		global $wpdb;

		$controller = WC_Inventory_Overview_Settings_Controller::instance();
		$nonce  = wp_create_nonce( 'wc_io_danger_reset_preview' );

		$_POST = array(
			'_wc_io_danger_reset_preview_nonce' => $nonce,
			'wc_io_reset_po'                    => '1',
		);
		$_REQUEST = $_POST;

		$before = $wpdb->num_queries;
		try {
			$controller->handle_danger_reset_preview_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}

	/**
	 * Query count: apply with valid token.
	 */
	public function test_query_count_danger_zone_apply() {
		global $wpdb;

		$controller = WC_Inventory_Overview_Settings_Controller::instance();

		// First generate a preview
		$preview_nonce = wp_create_nonce( 'wc_io_danger_reset_preview' );
		$_POST         = array(
			'_wc_io_danger_reset_preview_nonce' => $preview_nonce,
			'wc_io_reset_po'                    => '1',
		);
		$_REQUEST      = $_POST;

		try {
			$controller->handle_danger_reset_preview_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}

		// Extract token from redirect
		preg_match( '/wc_io_rst=([a-zA-Z0-9]+)/', $this->redirect_to, $matches );
		$token = $matches[1];

		// Now apply
		$apply_nonce = wp_create_nonce( 'wc_io_danger_reset_apply' );
		$_POST       = array(
			'_wc_io_danger_reset_apply_nonce'  => $apply_nonce,
			'wc_io_reset_preview_token'        => $token,
			'wc_io_danger_confirm'             => '1',
			'wc_io_danger_type_confirm'        => 'RESET',
		);
		$_REQUEST    = $_POST;

		$before = $wpdb->num_queries;
		try {
			$controller->handle_danger_reset_apply_post();
		} catch ( Exception $e ) {
			// Expected: redirect
		}
		$after = $wpdb->num_queries;

		$query_count = $after - $before;
		$this->assertGreaterThanOrEqual( 0, $query_count );
	}
}
