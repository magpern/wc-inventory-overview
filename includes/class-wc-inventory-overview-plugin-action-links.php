<?php
/**
 * Plugins screen action links.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds Overview and Settings links beside Deactivate on the Plugins screen.
 */
class WC_Inventory_Overview_Plugin_Action_Links {

	/**
	 * Register filter.
	 */
	public static function register() {
		add_filter(
			'plugin_action_links_' . plugin_basename( WC_INVENTORY_OVERVIEW_FILE ),
			array( __CLASS__, 'links' )
		);
	}

	/**
	 * Append capability-gated action links.
	 *
	 * @param array<string, string> $links Existing links.
	 * @return array<string, string>
	 */
	public static function links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}

		$plugin = WC_Inventory_Overview_Plugin::instance();
		$extra  = array();

		if ( current_user_can( 'edit_products' ) ) {
			$extra['overview'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $plugin->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_OVERVIEW ) ),
				esc_html__( 'Overview', 'wc-inventory-overview' )
			);
		}

		if ( current_user_can( 'manage_woocommerce' ) ) {
			$extra['settings'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $plugin->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_SETTINGS ) ),
				esc_html__( 'Settings', 'wc-inventory-overview' )
			);
		}

		if ( empty( $extra ) ) {
			return $links;
		}

		return array_merge( $extra, $links );
	}
}
