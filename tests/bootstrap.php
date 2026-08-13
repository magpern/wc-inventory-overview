<?php
/**
 * PHPUnit bootstrap for WC Inventory Overview
 *
 * Boots WordPress core's PHPUnit test scaffolding, then loads WooCommerce and
 * the plugin under test via the standard `muplugins_loaded` pattern. Runs
 * only in the test container environment.
 *
 * Fix note: an earlier version of this file pre-loaded WordPress via
 * `wp-load.php` (an already-installed site) before requiring WP core's own
 * test bootstrap. That test bootstrap performs a full fresh install/reset of
 * the test database, which wipes the active-plugins state the pre-load
 * relied on -- so by the time WP core's bootstrap fired its own
 * `plugins_loaded`, this plugin's file was no longer included and its
 * `plugins_loaded` callback never (re)registered, leaving every plugin class
 * undefined. Discovered running the M2 test suite for the first time against
 * a real WP-core test bootstrap. Fixed by using WP core's own sanctioned
 * `tests_add_filter( 'muplugins_loaded', ... )` hook, which fires at the
 * correct point in its bootstrap sequence, after the fresh install.
 *
 * @package WC_Inventory_Overview_Tests
 */

// Ensure we're in a test environment.
if ( ! defined( 'WP_TESTS_DIR' ) ) {
	define( 'WP_TESTS_DIR', '/tmp/wordpress-tests-lib' );
}

define( 'WP_TESTS_MULTISITE', false );

// M17: Mark that we're running under PHPUnit so test-only injection seams can activate.
if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
	define( 'WC_IO_PHPUNIT_RUNNING', true );
}

if ( ! file_exists( WP_TESTS_DIR . '/includes/functions.php' ) ) {
	echo 'ERROR: WordPress test library not found at ' . WP_TESTS_DIR . '. Ensure the test stack is running and seeded.' . PHP_EOL;
	exit( 1 );
}

require_once WP_TESTS_DIR . '/includes/functions.php';

// If a PHPUnit-Polyfills checkout is provided out-of-band via env var (not a
// project dependency -- composer.json deliberately excludes it, M0), point
// WP core's own test bootstrap at it. No-op when the env var is unset, so
// normal CI/M0 operation is completely unaffected.
$wc_io_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( $wc_io_polyfills_path && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $wc_io_polyfills_path );
}

/**
 * Load WooCommerce, then the plugin under test, at the correct point in WP
 * core's own bootstrap sequence (after its fresh install/reset, before the
 * final `plugins_loaded` firing this plugin's own hooks depend on).
 */
function wc_io_tests_manually_load_plugins() {
	$woocommerce_file = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
	if ( ! file_exists( $woocommerce_file ) ) {
		echo 'ERROR: WooCommerce not found at ' . $woocommerce_file . PHP_EOL;
		exit( 1 );
	}
	require $woocommerce_file;

	$plugin_file = ABSPATH . 'wp-content/plugins/wc-inventory-overview/wc-inventory-overview.php';
	if ( ! file_exists( $plugin_file ) ) {
		echo 'ERROR: Plugin not found at ' . $plugin_file . PHP_EOL;
		exit( 1 );
	}
	require $plugin_file;

	// WooCommerce is `require`d directly above rather than activated through
	// WordPress's normal activate_plugin() flow, so its own activation
	// routine (WC_Install::install(), which grants the `manage_woocommerce`
	// capability to the administrator role) never runs. Without this, every
	// PO-admin capability check fails for the test suite's own administrator
	// user. Standard, widely-used workaround in third-party WooCommerce
	// extension test suites; not a plugin or test-content change.
	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::create_roles();
	}
}
tests_add_filter( 'muplugins_loaded', 'wc_io_tests_manually_load_plugins' );

require WP_TESTS_DIR . '/includes/bootstrap.php';

// M20: global wp_die_ajax_handler safety net.
//
// WP_UnitTestCase_Base::set_up()/tear_down() (abstract-testcase.php:138/201)
// register/deregister a per-test handler on the 'wp_die_handler' filter
// (get_wp_die_handler() -> wp_die_handler(), abstract-testcase.php:506-534)
// that throws WPDieException -- the mechanism every wp_die()-expecting test
// in this suite relies on. wp_die() uses a *different* filter,
// 'wp_die_ajax_handler', whenever wp_doing_ajax() is true, and
// WP_UnitTestCase_Base never registers anything on that one (only
// WP_Ajax_UnitTestCase does, a different base class nothing in this repo
// extends). DOING_AJAX is a PHP constant and cannot be unset once defined by
// any test in this process (an existing, documented convention in
// tests/integration/supplier-merge/test-supplier-merge-admin.php and
// tests/integration/supplier-order-history/test-supplier-order-history-admin.php,
// both pre-dating M20) -- so once any test anywhere in a full-suite run
// defines it, every wp_die() call in every later-run test, even one
// completely unrelated to AJAX, routes through 'wp_die_ajax_handler'
// instead, which falls through to a real `die` and silently kills the
// entire PHPUnit process with no test report -- reproduced running the full
// M1-M20 suite after M20 added two more legitimate DOING_AJAX-defining AJAX
// tests, which shifted execution order enough to expose this pre-existing
// gap for the first time. Fixed by registering the exact same
// throw-WPDieException logic globally on 'wp_die_ajax_handler', mirroring
// WP_UnitTestCase_Base::wp_die_handler()'s own body line-for-line (WP_Error
// unwrap, non-scalar-message guard, $args['response'] -> exception code) --
// not a change to any individual test's behavior/assertions, and not a
// per-test filter (a global one is the only option available from outside
// WP_UnitTestCase_Base itself, which is vendored WP-core test scaffolding,
// not part of this repo).
add_filter(
	'wp_die_ajax_handler',
	static function () {
		return static function ( $message, $title = '', $args = array() ) {
			if ( is_wp_error( $message ) ) {
				$message = $message->get_error_message();
			}
			if ( ! is_scalar( $message ) ) {
				$message = '0';
			}
			$code = isset( $args['response'] ) ? $args['response'] : 0;
			throw new WPDieException( $message, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- re-thrown wp_die() message, not output.
		};
	}
);

// By this point WP core's bootstrap has completed its own install and fired
// plugins_loaded, so both WooCommerce and this plugin's classes must exist.
if ( ! class_exists( 'WooCommerce' ) ) {
	echo 'ERROR: WooCommerce failed to load.' . PHP_EOL;
	exit( 1 );
}
if ( ! defined( 'WC_INVENTORY_OVERVIEW_VERSION' ) ) {
	echo 'ERROR: Plugin failed to load or define constants.' . PHP_EOL;
	exit( 1 );
}

// Load test helpers and base classes.
require_once __DIR__ . '/includes/test-case.php';
require_once __DIR__ . '/includes/fixtures.php';
require_once __DIR__ . '/includes/assertions.php';
