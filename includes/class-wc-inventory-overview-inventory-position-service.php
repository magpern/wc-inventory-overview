<?php
/**
 * Inventory Position Service (D12, M3).
 *
 * The sole authoritative calculator for Inventory Position, single and
 * bulk. Bulk calls perform at most one product-scoped and one
 * variation-scoped read against the open-PO-lines repository, then
 * aggregate in PHP and delegate the final {on_hand, incoming, position,
 * incoming_delayed} combination to the Resolver. Read-only: never writes,
 * never mutates stock/cost, never refetches WooCommerce products, never
 * caches.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk/single Inventory Position calculator.
 */
class WC_Inventory_Overview_Inventory_Position_Service {

	const TYPE_PRODUCT   = 'product';
	const TYPE_VARIATION = 'variation';

	/**
	 * Inventory Position for a single purchasable item.
	 *
	 * Delegates to get_positions_bulk() with a single-item map so single
	 * and bulk calls always share one calculation path (D12).
	 *
	 * @param string $item_type Self::TYPE_PRODUCT or self::TYPE_VARIATION.
	 * @param int    $item_id   Product or variation ID.
	 * @param float  $on_hand   Caller-supplied On Hand quantity.
	 * @return array{on_hand: float, incoming: float, position: float, incoming_delayed: bool, incoming_lines: array<int,array<string,mixed>>}
	 */
	public static function get_position( string $item_type, int $item_id, float $on_hand ): array {
		$bulk = self::TYPE_VARIATION === $item_type
			? self::get_positions_bulk( array(), array( $item_id => $on_hand ) )
			: self::get_positions_bulk( array( $item_id => $on_hand ), array() );

		return $bulk[ $item_id ];
	}

	/**
	 * Inventory Position for many purchasable items in one pass.
	 *
	 * Calls list_open_lines_for_product_ids() at most once and
	 * list_open_lines_for_variation_ids() at most once, regardless of how
	 * many items are requested (no N+1).
	 *
	 * @param array<int,float> $product_on_hand   Product ID => caller-supplied On Hand.
	 * @param array<int,float> $variation_on_hand Variation ID => caller-supplied On Hand.
	 * @return array<int,array{on_hand: float, incoming: float, position: float, incoming_delayed: bool, incoming_lines: array<int,array<string,mixed>>}>
	 *         Keyed by item ID (product or variation IDs share the WordPress
	 *         post ID space, so one flat map is unambiguous).
	 */
	public static function get_positions_bulk( array $product_on_hand, array $variation_on_hand ): array {
		$product_lines   = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array_keys( $product_on_hand ) );
		$variation_lines = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_variation_ids( array_keys( $variation_on_hand ) );

		$lines_by_item = array();
		foreach ( $product_lines as $line ) {
			$lines_by_item[ (int) $line['product_id'] ][] = $line;
		}
		foreach ( $variation_lines as $line ) {
			$lines_by_item[ (int) $line['variation_id'] ][] = $line;
		}

		$results = array();

		foreach ( $product_on_hand as $item_id => $on_hand ) {
			$item_id             = (int) $item_id;
			$results[ $item_id ] = self::build_result( (float) $on_hand, $lines_by_item[ $item_id ] ?? array() );
		}
		foreach ( $variation_on_hand as $item_id => $on_hand ) {
			$item_id             = (int) $item_id;
			$results[ $item_id ] = self::build_result( (float) $on_hand, $lines_by_item[ $item_id ] ?? array() );
		}

		return $results;
	}

	/**
	 * Aggregate one item's contributing lines into a Position result.
	 *
	 * @param float                          $on_hand On Hand quantity.
	 * @param array<int,array<string,mixed>> $lines   Independent contributing PO lines (INV-1).
	 * @return array{on_hand: float, incoming: float, position: float, incoming_delayed: bool, incoming_lines: array<int,array<string,mixed>>}
	 */
	private static function build_result( float $on_hand, array $lines ): array {
		$incoming = 0.0;
		$delayed  = false;

		foreach ( $lines as $line ) {
			$incoming += (float) $line['outstanding'];
			if ( ! empty( $line['is_delayed'] ) ) {
				$delayed = true;
			}
		}

		$result                   = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( $on_hand, $incoming, $delayed );
		$result['incoming_lines'] = $lines;

		return $result;
	}
}
