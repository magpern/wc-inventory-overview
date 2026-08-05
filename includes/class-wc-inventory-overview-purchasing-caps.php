<?php
/**
 * Purchasing capability map (M2-D).
 *
 * Defaults every purchasing read/write to manage_woocommerce. Filterable for a
 * later capability split without registering custom roles on activation.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filterable purchasing capabilities.
 */
class WC_Inventory_Overview_Purchasing_Caps {

	const VIEW_PO          = 'view_po';
	const EDIT_PO          = 'edit_po';
	const PLACE_PO         = 'place_po';
	const CLOSE_PO         = 'close_po';
	const CANCEL_PO        = 'cancel_po';
	const DUPLICATE_PO     = 'duplicate_po';
	const DELETE_PO        = 'delete_po';
	const MANAGE_SUPPLIERS = 'manage_suppliers';

	/**
	 * Resolve the WordPress capability for a purchasing action key.
	 *
	 * @param string $action Action key (view_po, edit_po, …).
	 */
	public static function capability( string $action ): string {
		$map = array(
			self::VIEW_PO          => 'manage_woocommerce',
			self::EDIT_PO          => 'manage_woocommerce',
			self::PLACE_PO         => 'manage_woocommerce',
			self::CLOSE_PO         => 'manage_woocommerce',
			self::CANCEL_PO        => 'manage_woocommerce',
			self::DUPLICATE_PO     => 'manage_woocommerce',
			self::DELETE_PO        => 'manage_woocommerce',
			self::MANAGE_SUPPLIERS => 'manage_woocommerce',
		);

		/**
		 * Filter the purchasing capability map.
		 *
		 * @param array<string,string> $map Action key => WP capability.
		 */
		$map = apply_filters( 'wc_io_purchasing_capability_map', $map );

		$cap = isset( $map[ $action ] ) ? $map[ $action ] : 'manage_woocommerce';

		/**
		 * Filter a single purchasing capability.
		 *
		 * @param string $cap    WordPress capability.
		 * @param string $action Action key.
		 */
		return (string) apply_filters( 'wc_io_purchasing_capability', $cap, $action );
	}

	/**
	 * Whether the current user can perform a purchasing action.
	 *
	 * @param string $action Action key.
	 */
	public static function current_user_can( string $action ): bool {
		return current_user_can( self::capability( $action ) );
	}
}
