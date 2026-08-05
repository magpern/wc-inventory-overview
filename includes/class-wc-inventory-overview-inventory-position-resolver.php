<?php
/**
 * Inventory Position calculation (D11, M3).
 *
 * Position = On Hand + Incoming. Stateless and read-only: no $wpdb, no
 * WooCommerce product loading, no PO repository access. Callers supply On
 * Hand and the aggregated Incoming figures; this class only combines them.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure Inventory Position calculator.
 */
class WC_Inventory_Overview_Inventory_Position_Resolver {

	/**
	 * Resolve the Inventory Position result for one purchasable item.
	 *
	 * @param float $on_hand          Caller-supplied On Hand quantity.
	 * @param float $incoming         Aggregated outstanding quantity across open PO lines.
	 * @param bool  $incoming_delayed Whether at least one contributing line is delayed.
	 * @return array{on_hand: float, incoming: float, position: float, incoming_delayed: bool}
	 */
	public static function resolve( float $on_hand, float $incoming, bool $incoming_delayed ): array {
		return array(
			'on_hand'          => $on_hand,
			'incoming'         => $incoming,
			'position'         => $on_hand + $incoming,
			'incoming_delayed' => $incoming_delayed,
		);
	}
}
