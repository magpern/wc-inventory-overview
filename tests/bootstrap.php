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
}
tests_add_filter( 'muplugins_loaded', 'wc_io_tests_manually_load_plugins' );

require WP_TESTS_DIR . '/includes/bootstrap.php';

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
