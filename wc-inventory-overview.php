<?php
/**
 * Plugin Name:       WC Inventory Overview
 * Description:       Operational inventory dashboard for WooCommerce products and variations (HPOS-compatible).
 * Version:           1.18.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WC Inventory Overview
 * Text Domain:       wc-inventory-overview
 * Domain Path:       /languages
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WC_INVENTORY_OVERVIEW_VERSION' ) ) {
	define( 'WC_INVENTORY_OVERVIEW_VERSION', '1.18.1' );
}

if ( ! defined( 'WC_INVENTORY_OVERVIEW_FILE' ) ) {
	define( 'WC_INVENTORY_OVERVIEW_FILE', __FILE__ );
}

if ( ! defined( 'WC_INVENTORY_OVERVIEW_PATH' ) ) {
	define( 'WC_INVENTORY_OVERVIEW_PATH', plugin_dir_path( __FILE__ ) );
}

/**
 * WooCommerce dependency check.
 */
function wc_inventory_overview_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="error"><p>';
	echo esc_html__( 'WC Inventory Overview requires WooCommerce to be installed and active.', 'wc-inventory-overview' );
	echo '</p></div>';
}

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WC_INVENTORY_OVERVIEW_FILE, true );
		}
	}
);

register_activation_hook(
	WC_INVENTORY_OVERVIEW_FILE,
	static function () {
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-install.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-suppliers.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-suppliers-migration.php';
		WC_Inventory_Overview_Install::activate();
	}
);

/**
 * GitHub Release updater (admin / cron only).
 */
function wc_inventory_overview_init_github_updater() {
	if ( ! is_admin() && ! ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
		return;
	}

	require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-github-updater.php';
	WC_Inventory_Overview_Github_Updater::maybe_init();
}
add_action( 'plugins_loaded', 'wc_inventory_overview_init_github_updater', 9 );

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'wc_inventory_overview_missing_wc_notice' );
			return;
		}

		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-repository.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-install.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-costing.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-settings.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-exchange-rates.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-suppliers.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-suppliers-migration.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-data-reset.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-summary.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-dashboard-inventory-metrics.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-movements.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-restock-service.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-cost-adjustment-service.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-batch-intake-service.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-batch-intake-ui.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-order-item-snapshots.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-order-shipping-admin.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-list-table.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/trait-wc-inventory-overview-hub-list-table-column-info.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-movements-list-table.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-order-profit-query.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-dashboard-charts-data.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-order-profit-list-table.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-product-profitability-query.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-product-profitability-list-table.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-suppliers-list-table.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-purchasing-page.php';
		require_once WC_INVENTORY_OVERVIEW_PATH . 'includes/class-wc-inventory-overview-plugin.php';

		WC_Inventory_Overview_Install::maybe_upgrade();
		WC_Inventory_Overview_Plugin::instance()->init();
	},
	11
);
