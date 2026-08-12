<?php
/**
 * WP-M17-6: HTTP-level admin handler tests for supplier merge.
 *
 * Tests capability guard, nonce, token consume, happy path, every error
 * code -> notice mapping, and a direct crafted-POST test proving
 * server-side confirmation enforcement independent of any JS.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Admin extends WP_UnitTestCase {

	/**
	 * Captured redirect location from wp_redirect filter.
	 *
	 * @var string|null
	 */
	private $redirect_to = null;

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

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

	private function create_supplier( string $name ): int {
		return (int) WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => 'EUR',
			)
		);
	}

	/**
	 * Capability-denied: non-privileged user gets a 403 wp_die.
	 */
	public function test_capability_denied() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$page = WC_Inventory_Overview_Purchasing_Page::instance();

		$_POST = array(
			'source_supplier_id' => $s1,
			'target_supplier_id' => $s2,
		);

		$this->expectException( WPDieException::class );
		$page->handle_supplier_merge();
	}

	/**
	 * Bad nonce: rejected before service is called.
	 */
	public function test_bad_nonce_rejected() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$page = WC_Inventory_Overview_Purchasing_Page::instance();

		$_POST = array(
			'source_supplier_id'       => $s1,
			'target_supplier_id'       => $s2,
			'wc_io_supplier_merge_nonce' => 'invalid-nonce',
		);

		$this->expectException( WPDieException::class );
		$page->handle_supplier_merge();

		// Verify source was NOT merged.
		$source = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertNull( $source['merged_into_supplier_id'] );
	}

	/**
	 * Happy path: correct redirect + notice, source/target state verified.
	 */
	public function test_happy_path_redirect_and_notice() {
		$s1 = $this->create_supplier( 'Source Supplier' );
		$s2 = $this->create_supplier( 'Target Supplier' );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_merge_' . $s1 );
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'supplier_merge' );

		$_POST = array(
			'source_supplier_id'                 => $s1,
			'target_supplier_id'                 => $s2,
			'wc_io_supplier_merge_nonce'          => $nonce,
			'wc_io_supplier_merge_request_token'  => $token,
			'supplier_merge_confirmation'         => 'Source Supplier',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_merge();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=merged', $location );
		$this->assertStringContainsString( 'supplier_id=' . $s2, $location );

		$source = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'archived', $source['status'] );
		$this->assertSame( $s2, (int) $source['merged_into_supplier_id'] );
	}

	/**
	 * Every error code -> notice mapping: same-supplier case.
	 */
	public function test_error_same_supplier_redirects_with_err_notice() {
		$s1 = $this->create_supplier( 'Solo Supplier' );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_merge_' . $s1 );
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'supplier_merge' );

		$_POST = array(
			'source_supplier_id'                => $s1,
			'target_supplier_id'                => $s1,
			'wc_io_supplier_merge_nonce'         => $nonce,
			'wc_io_supplier_merge_request_token' => $token,
			'supplier_merge_confirmation'        => 'Solo Supplier',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_merge();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=merge_err', $location );

		$error = get_transient( 'wc_io_supplier_merge_err_' . get_current_user_id() );
		$this->assertNotEmpty( $error );
	}

	/**
	 * Double-submit: second attempt with same token fails at token-consume.
	 */
	public function test_double_submit_token_rejected_on_second_attempt() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_merge_' . $s1 );
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'supplier_merge' );

		$_POST = array(
			'source_supplier_id'                 => $s1,
			'target_supplier_id'                 => $s2,
			'wc_io_supplier_merge_nonce'          => $nonce,
			'wc_io_supplier_merge_request_token'  => $token,
			'supplier_merge_confirmation'         => 'Source',
		);
		$_REQUEST = $_POST;

		// First attempt succeeds.
		$location1 = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_merge();
			}
		);
		$this->assertStringContainsString( 'wc_io_supplier=merged', $location1 );

		// Second attempt with the SAME token must fail at token-consume.
		$this->redirect_to = null;
		$nonce2             = wp_create_nonce( 'wc_io_supplier_merge_' . $s1 );
		$_POST['wc_io_supplier_merge_nonce'] = $nonce2;
		$_REQUEST                            = $_POST;

		$location2 = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_merge();
			}
		);
		$this->assertStringContainsString( 'wc_io_supplier=merge_err', $location2 );
	}

	/**
	 * Crafted POST with valid nonce/token but WRONG confirmation text ->
	 * wc_io_supplier_merge_confirmation_mismatch, proving server-side
	 * enforcement independent of any JS (BR-M17-16).
	 */
	public function test_crafted_post_wrong_confirmation_rejected_server_side() {
		$s1 = $this->create_supplier( 'Exact Name Supplier' );
		$s2 = $this->create_supplier( 'Target' );

		$page  = WC_Inventory_Overview_Purchasing_Page::instance();
		$nonce = wp_create_nonce( 'wc_io_supplier_merge_' . $s1 );
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'supplier_merge' );

		// Valid nonce and token, but WRONG confirmation text -- simulating a
		// crafted request that bypasses any client-side JS gating entirely.
		$_POST = array(
			'source_supplier_id'                 => $s1,
			'target_supplier_id'                 => $s2,
			'wc_io_supplier_merge_nonce'          => $nonce,
			'wc_io_supplier_merge_request_token'  => $token,
			'supplier_merge_confirmation'         => 'Totally Wrong Text',
		);
		$_REQUEST = $_POST;

		$location = $this->run_expecting_redirect(
			static function () use ( $page ) {
				$page->handle_supplier_merge();
			}
		);

		$this->assertStringContainsString( 'wc_io_supplier=merge_err', $location );

		$error = get_transient( 'wc_io_supplier_merge_err_' . get_current_user_id() );
		$this->assertStringContainsString( 'Confirmation text did not match', $error );

		// Verify NO mutation occurred.
		$source = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'active', $source['status'] );
		$this->assertNull( $source['merged_into_supplier_id'] );
	}

	/**
	 * AJAX target search excludes source/archived/merged suppliers.
	 *
	 * wp_send_json_success() calls raw `die;` (not wp_die()) unless
	 * wp_doing_ajax() is true, in which case it routes through wp_die()
	 * instead -- so DOING_AJAX must be defined true first (the standard
	 * WP_Ajax_UnitTestCase convention) for wp_die_handler/wp_die_ajax_handler
	 * interception to have any effect at all; without it the raw `die;`
	 * would silently kill the whole PHPUnit process. DOING_AJAX cannot be
	 * unset once defined, matching this codebase's own documented caveat in
	 * tests/integration/supplier-order-history/test-supplier-order-history-admin.php.
	 */
	public function test_ajax_target_search_excludes_ineligible_suppliers() {
		$active_target   = $this->create_supplier( 'Eligible Target' );
		$archived_target = $this->create_supplier( 'Archived Supplier' );
		WC_Inventory_Overview_Suppliers::archive( $archived_target );
		$source = $this->create_supplier( 'The Source' );

		$_POST    = array(
			'term'                => '',
			'exclude_supplier_id' => $source,
			'security'            => wp_create_nonce( 'wc_io_search_merge_targets' ),
		);
		$_REQUEST = $_POST;

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$page = WC_Inventory_Overview_Purchasing_Page::instance();

		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
			};
		};
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			ob_start();
			$page->ajax_search_merge_targets();
			$json = ob_get_clean();
			$this->fail( 'Expected wp_send_json_success() to call wp_die().' );
		} catch ( WPDieException $e ) {
			$json = ob_get_clean();
		} finally {
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}

		$response = json_decode( $json, true );
		$this->assertIsArray( $response, 'Raw JSON output: ' . var_export( $json, true ) );
		$this->assertTrue( $response['success'] );

		$ids = array_map( 'intval', wp_list_pluck( $response['data']['results'], 'id' ) );
		$this->assertContains( $active_target, $ids );
		$this->assertNotContains( $archived_target, $ids );
		$this->assertNotContains( $source, $ids );
	}
}
