<?php
/**
 * M20 characterization tests: Overview CSV export guards
 * (maybe_export_csv()).
 *
 * Written and verified green against CURRENT (pre-extraction) Plugin code.
 * Covers BR-M20-13. After extraction to Overview_Controller, rerun
 * unchanged and must stay green (INV-M20-2).
 *
 * The successful CSV-export streaming path (maybe_export_csv()'s exit()
 * after writing to php://output) cannot be safely characterized by an
 * in-process PHPUnit run -- WP_UnitTestCase has no exit()-interception
 * convention in this repository (confirmed: no runInSeparateProcess/
 * preserveGlobalState usage anywhere in tests/, matching the exact
 * precedent already established in
 * tests/integration/admin-decomposition/test-movements-rendering-characterization.php).
 * That path is covered by manual acceptance only. This file characterizes
 * every reachable guard/early-return branch instead.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Overview_Csv_Export_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
	}

	public function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/**
	 * BR-M20-13: without wc_io_export=csv, on_load_screen() returns
	 * normally (no export attempted, no exit()).
	 */
	public function test_csv_export_not_triggered_without_param() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET     = array( 'page' => WC_Inventory_Overview_Plugin::PAGE_SLUG, 'tab' => WC_Inventory_Overview_Plugin::TAB_OVERVIEW );
		$_REQUEST = $_GET;

		$plugin->on_load_screen();
		$this->assertTrue( true, 'on_load_screen() returned without exporting.' );
	}

	/**
	 * BR-M20-13: wc_io_export present but not 'csv' -- guarded off.
	 */
	public function test_csv_export_requires_csv_value() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET     = array( 'wc_io_export' => 'xlsx' );
		$_REQUEST = $_GET;

		$plugin->on_load_screen();
		$this->assertTrue( true, 'Export guarded off for a non-csv value.' );
	}

	/**
	 * BR-M20-13/INV-M20-9/INV-M20-10: export triggered but invalid/missing
	 * nonce -> check_admin_referer() -> WPDieException, proving the nonce
	 * action string 'wc_io_export_csv' / field '_wc_io_export_nonce' gate
	 * the export before any CSV streaming begins. Robust to DOING_AJAX
	 * pollution from other test files in the same process (see
	 * test-restock-mutation-characterization.php's identical note).
	 */
	public function test_csv_export_invalid_nonce_dies() {
		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET     = array( 'wc_io_export' => 'csv' );
		$_REQUEST = $_GET;

		$throw_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		try {
			$this->expectException( WPDieException::class );
			$plugin->on_load_screen();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}

	/**
	 * BR-M20-13/INV-M20-9: page-level capability checked before the export
	 * condition -- without edit_products, on_load_screen() dies via wp_die()
	 * before ever reaching maybe_export_csv(), so no nonce check fires even
	 * with export params present.
	 */
	public function test_on_load_denies_without_capability_before_export_check() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$plugin = WC_Inventory_Overview_Plugin::instance();

		$_GET     = array( 'wc_io_export' => 'csv' );
		$_REQUEST = $_GET;

		$throw_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			};
		};
		add_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		try {
			$this->expectException( WPDieException::class );
			$plugin->on_load_screen();
		} finally {
			remove_filter( 'wp_die_handler', $throw_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $throw_handler, PHP_INT_MAX );
		}
	}

	/**
	 * BR-M20-13: get_query_params_from_request() builds the exact param
	 * shape maybe_export_csv() feeds to
	 * Repository::iterate_products_for_export() -- category term-taxonomy
	 * resolution, stock-status whitelist, exclude-private default.
	 */
	public function test_get_query_params_from_request_shape() {
		$term = wp_insert_term( 'Widgets', 'product_cat' );
		$this->assertIsArray( $term );
		$term_obj = get_term( $term['term_id'], 'product_cat' );

		$_REQUEST = array(
			's'                  => 'search text',
			'wc_io_product_cat'  => $term['term_id'],
			'wc_io_stock_status' => 'instock',
		);

		$ref = new ReflectionMethod( 'WC_Inventory_Overview_Plugin', 'get_query_params_from_request' );
		$ref->setAccessible( true );
		$params = $ref->invoke( WC_Inventory_Overview_Plugin::instance() );

		$this->assertSame( 'search text', $params['search'] );
		$this->assertSame( (int) $term_obj->term_taxonomy_id, $params['category_tt_id'] );
		$this->assertSame( array( 'instock' ), $params['stock_status'] );
		$this->assertArrayHasKey( 'exclude_private', $params );
	}

	/**
	 * BR-M20-13: an unrecognized stock-status value is dropped (empty
	 * filter, not passed through verbatim).
	 */
	public function test_get_query_params_from_request_rejects_invalid_stock_status() {
		$_REQUEST = array( 'wc_io_stock_status' => 'not-a-real-status' );

		$ref = new ReflectionMethod( 'WC_Inventory_Overview_Plugin', 'get_query_params_from_request' );
		$ref->setAccessible( true );
		$params = $ref->invoke( WC_Inventory_Overview_Plugin::instance() );

		$this->assertSame( array(), $params['stock_status'] );
	}
}
