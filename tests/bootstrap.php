<?php
/**
 * PHPUnit bootstrap for WC Inventory Overview
 *
 * Boots WordPress core, WooCommerce, and the plugin under test in isolation.
 * Runs only in the test container environment.
 *
 * @package WC_Inventory_Overview_Tests
 */

// Ensure we're in a test environment.
if ( ! defined( 'WP_TESTS_DIR' ) ) {
	define( 'WP_TESTS_DIR', '/tmp/wordpress-tests-lib' );
}

// Prevent extraneous output during test run.
define( 'WP_TESTS_MULTISITE', false );

// Suppress WP_PLUGIN_DIR constant override; use the test container's structure.
// WordPress is installed at /var/www/html; plugins at wp-content/plugins.

// Start output buffering to suppress any premature output.
ob_start();

// Load WordPress test suite library (provided by wordpress:cli image).
// The test library is typically available after core installation.
if ( file_exists( '/var/www/html/wp-load.php' ) ) {
	require '/var/www/html/wp-load.php';
} else {
	echo 'ERROR: WordPress not found at /var/www/html. Ensure the test stack is running and seeded.' . PHP_EOL;
	exit( 1 );
}

// Ensure WordPress is loaded.
if ( ! function_exists( 'add_filter' ) ) {
	echo 'ERROR: WordPress bootstrap failed. Test environment not properly configured.' . PHP_EOL;
	exit( 1 );
}

// Load WordPress PHPUnit test case classes.
require_once '/tmp/wordpress-tests-lib/includes/functions.php';
require_once '/tmp/wordpress-tests-lib/includes/bootstrap.php';
require_once '/tmp/wordpress-tests-lib/includes/testcase.php';

// Activate WooCommerce if not already active.
if ( ! function_exists( 'wc_get_products' ) ) {
	// Try to activate WooCommerce.
	if ( file_exists( '/var/www/html/wp-content/plugins/woocommerce/woocommerce.php' ) ) {
		require_once '/var/www/html/wp-content/plugins/woocommerce/woocommerce.php';
	}
}

// Ensure WooCommerce loaded.
if ( ! class_exists( 'WooCommerce' ) ) {
	echo 'ERROR: WooCommerce not activated. Ensure it is installed and activated in the test container.' . PHP_EOL;
	exit( 1 );
}

// Ensure the plugin under test is loaded.
if ( ! file_exists( '/var/www/html/wp-content/plugins/wc-inventory-overview/wc-inventory-overview.php' ) ) {
	echo 'ERROR: Plugin not found at expected location.' . PHP_EOL;
	exit( 1 );
}

require_once '/var/www/html/wp-content/plugins/wc-inventory-overview/wc-inventory-overview.php';

// Verify plugin loaded.
if ( ! defined( 'WC_INVENTORY_OVERVIEW_VERSION' ) ) {
	echo 'ERROR: Plugin failed to load or define constants.' . PHP_EOL;
	exit( 1 );
}

// Stop output buffering and suppress any premature output.
ob_end_clean();

// Load test helpers and base classes.
require_once __DIR__ . '/includes/test-case.php';
require_once __DIR__ . '/includes/fixtures.php';
require_once __DIR__ . '/includes/assertions.php';
