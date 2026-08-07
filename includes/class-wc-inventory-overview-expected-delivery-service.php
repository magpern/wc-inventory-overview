<?php
/**
 * Expected Delivery Service — the sole public API for storefront expected delivery (M7, API v1).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public API v1. Consumes WC_Inventory_Overview_Inventory_Position_Service (D12)
 * as the sole source of incoming-supply data — never re-queries Purchase Orders,
 * receipt tables, or receiving repositories directly.
 *
 * Any future REST route, Store API extension, GraphQL resolver, Blocks
 * integration, or headless endpoint must delegate to this Service.
 * WC_Inventory_Overview_Expected_Delivery_Resolver must never be called
 * directly from outside this class.
 */
final class WC_Inventory_Overview_Expected_Delivery_Service {

	/**
	 * Independent of the plugin version. See docs/api-expected-delivery.md.
	 */
	const API_VERSION = 1;

	/**
	 * Request-scoped memoization only (never persistent — see plan §Caching).
	 * Product and variation IDs share the WordPress post ID space, so a flat
	 * map keyed by item ID is unambiguous.
	 *
	 * @var array<int,WC_Inventory_Overview_Expected_Delivery_Result_Interface>
	 */
	private static $memo = array();

	/**
	 * @param WC_Product|int $product Product/variation instance or ID.
	 * @return WC_Inventory_Overview_Expected_Delivery_Result_Interface
	 */
	public static function get_for_product( $product ): WC_Inventory_Overview_Expected_Delivery_Result_Interface {
		$results = self::get_for_products_bulk( array( $product ) );

		return reset( $results );
	}

	/**
	 * @param array<int,WC_Product|int> $products Product/variation instances or IDs.
	 * @return array<int,WC_Inventory_Overview_Expected_Delivery_Result_Interface> Keyed by item ID.
	 */
	public static function get_for_products_bulk( array $products ): array {
		$normalized = array();
		foreach ( $products as $product ) {
			list( $id, $wc_product ) = self::normalize_product( $product );
			if ( $id <= 0 ) {
				continue;
			}
			$normalized[ $id ] = $wc_product;
		}

		$results    = array();
		$to_resolve = array();

		foreach ( $normalized as $id => $wc_product ) {
			if ( isset( self::$memo[ $id ] ) ) {
				$results[ $id ] = self::$memo[ $id ];
				continue;
			}
			$to_resolve[ $id ] = $wc_product;
		}

		if ( empty( $to_resolve ) ) {
			return $results;
		}

		$today = current_time( 'Y-m-d' );

		$product_on_hand   = array();
		$variation_on_hand = array();
		$parent_children   = array();

		foreach ( $to_resolve as $id => $wc_product ) {
			if ( null === $wc_product ) {
				continue;
			}

			if ( $wc_product instanceof WC_Product_Variation ) {
				$variation_on_hand[ $id ] = 0.0;
			} elseif ( $wc_product->is_type( 'variable' ) ) {
				$children             = array_map( 'absint', $wc_product->get_children() );
				$parent_children[ $id ] = $children;
				foreach ( $children as $child_id ) {
					$variation_on_hand[ $child_id ] = 0.0;
				}
			} else {
				$product_on_hand[ $id ] = 0.0;
			}
		}

		$positions = empty( $product_on_hand ) && empty( $variation_on_hand )
			? array()
			: WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( $product_on_hand, $variation_on_hand );

		foreach ( $to_resolve as $id => $wc_product ) {
			if ( null === $wc_product ) {
				$resolved      = array(
					'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE,
					'expected_date' => null,
					'confidence'    => null,
				);
				$available_now = false;
			} elseif ( isset( $parent_children[ $id ] ) ) {
				$available_now = $wc_product->is_in_stock();
				$resolved      = self::resolve_variable_parent( $available_now, $parent_children[ $id ], $positions );
			} else {
				$available_now  = $wc_product->is_in_stock();
				$incoming_lines = $positions[ $id ]['incoming_lines'] ?? array();
				$resolved       = WC_Inventory_Overview_Expected_Delivery_Resolver::resolve( $available_now, $incoming_lines, $today );
			}

			$result = WC_Inventory_Overview_Expected_Delivery_Result::create(
				self::API_VERSION,
				$available_now,
				$resolved['state'],
				$resolved['expected_date'],
				$resolved['confidence']
			);

			self::$memo[ $id ] = $result;
			$results[ $id ]    = $result;
		}

		return $results;
	}

	/**
	 * Invariant M7-2: a variable parent may present STATE_EXPECTED_SOON, never
	 * STATE_EXPECTED_DATE, and its expected_date() is always null. Structurally
	 * free from fabrication — this rolls up "does any variation have open
	 * supply" (Position's own `incoming` total), never a per-variation date.
	 *
	 * @param bool              $available_now Parent's own is_in_stock() answer.
	 * @param array<int,int>    $child_ids     Variation IDs.
	 * @param array<int,array>  $positions     Bulk positions keyed by item ID.
	 * @return array{state: string, expected_date: string|null, confidence: string|null}
	 */
	private static function resolve_variable_parent( bool $available_now, array $child_ids, array $positions ): array {
		if ( $available_now ) {
			return array(
				'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK,
				'expected_date' => null,
				'confidence'    => null,
			);
		}

		foreach ( $child_ids as $child_id ) {
			$incoming = isset( $positions[ $child_id ] ) ? (float) $positions[ $child_id ]['incoming'] : 0.0;
			if ( $incoming > 0 ) {
				return array(
					'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON,
					'expected_date' => null,
					'confidence'    => null,
				);
			}
		}

		return array(
			'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE,
			'expected_date' => null,
			'confidence'    => null,
		);
	}

	/**
	 * Normalizes a WC_Product instance or an ID into [ id, WC_Product|null ].
	 * `on_hand` is deliberately never sourced from the product here: the
	 * Result exposes no quantity, the Resolver reads only `incoming_lines`,
	 * and `available_now` comes from is_in_stock() — so on_hand is unused,
	 * and passing 0.0 sidesteps get_manage_stock() === 'parent' leaking the
	 * parent's quantity onto every variation.
	 *
	 * @param WC_Product|int $product Product/variation instance or ID.
	 * @return array{0:int,1:WC_Product|null}
	 */
	private static function normalize_product( $product ): array {
		if ( $product instanceof WC_Product ) {
			return array( $product->get_id(), $product );
		}

		$id = absint( $product );
		if ( $id <= 0 ) {
			return array( 0, null );
		}

		$wc_product = wc_get_product( $id );

		return array( $id, $wc_product instanceof WC_Product ? $wc_product : null );
	}

	/**
	 * Registers memo-flush listeners on the plugin's four existing
	 * wc_io_purchase_order_* hooks. Belt-and-braces: storefront requests
	 * never post receipts, and the memo dies at end of request regardless.
	 */
	public static function register() {
		add_action( 'wc_io_purchase_order_created', array( __CLASS__, 'flush_memo' ) );
		add_action( 'wc_io_purchase_order_placed', array( __CLASS__, 'flush_memo' ) );
		add_action( 'wc_io_purchase_order_cancelled', array( __CLASS__, 'flush_memo' ) );
		add_action( 'wc_io_purchase_order_closed_short', array( __CLASS__, 'flush_memo' ) );
	}

	public static function flush_memo(): void {
		self::$memo = array();
	}
}
