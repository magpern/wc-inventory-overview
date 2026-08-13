<?php
/**
 * Reorder Signal classification (M21).
 *
 * Distinguishes, for a purchasable item already classified "Low stock" by
 * the plugin's pre-existing on-hand-vs-threshold rule, whether it still
 * needs reordering once already-open incoming supply is taken into account.
 * Stateless and read-only: no $wpdb, no WooCommerce product loading, no
 * repository access. Callers supply Position (On Hand + Incoming, from
 * {@see WC_Inventory_Overview_Inventory_Position_Service}) and the item's
 * effective low-stock threshold (from
 * {@see WC_Inventory_Overview_Settings::get_effective_low_stock_amount()});
 * this class only compares them (BR-M21-2, BR-M21-11).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure Reorder Signal classifier — the sole owner of this comparison (INV-M21-2).
 */
class WC_Inventory_Overview_Reorder_Signal_Resolver {

	/**
	 * Classify an already-low-stock item's Reorder Signal.
	 *
	 * @param float $position  Inventory Position (On Hand + Incoming).
	 * @param float $threshold Effective low-stock threshold for the same item.
	 * @return array{needs_reorder: bool, covered_by_incoming: bool}
	 */
	public static function resolve( float $position, float $threshold ): array {
		$needs_reorder = $position <= $threshold;

		return array(
			'needs_reorder'       => $needs_reorder,
			'covered_by_incoming' => ! $needs_reorder,
		);
	}
}
