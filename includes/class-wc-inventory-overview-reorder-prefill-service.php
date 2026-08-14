<?php
/**
 * Reorder → Draft PO Quick Action prefill resolution (M22; supplier
 * precedence + quantity default extension in M23).
 *
 * Read-only orchestrator for the New PO screen's reorder-prefill GET
 * parameters. Composes existing, unmodified owners only — never
 * reimplements product/variation identity validation (delegates to
 * WC_Inventory_Overview_PO_Product_Validator), Position calculation
 * (delegates to WC_Inventory_Overview_Inventory_Position_Service), the
 * needs_reorder comparison (delegates to
 * WC_Inventory_Overview_Reorder_Signal_Resolver), or replenishment-default
 * reads (delegates to WC_Inventory_Overview_Replenishment_Defaults).
 * Performs zero mutation and issues no admin_post/AJAX handler of its own.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the five-state reorder-prefill contract for the New PO screen
 * (M22 §8): 'malformed' | 'invalid' | 'stale' | 'prefilled'.
 *
 * INV-M22-1: never reimplements the needs_reorder comparison — always
 * delegates to WC_Inventory_Overview_Reorder_Signal_Resolver.
 * INV-M22-2: never sums On Hand + Incoming itself — always delegates to
 * WC_Inventory_Overview_Inventory_Position_Service.
 * INV-M22-10: no SQL here — supplier history/bulk fetch are repository
 * calls (Purchase_Order_Lines, Suppliers).
 */
class WC_Inventory_Overview_Reorder_Prefill_Service {

	/**
	 * Resolve reorder-prefill state for one product/variation.
	 *
	 * Always re-derives identity and reorder-currency from scratch (TOCTOU,
	 * §4) — never trusts that the caller's GET params still describe a
	 * genuinely needs-reorder item. Supplier resolution (§5) only runs for
	 * the 'prefilled' outcome; a stale/invalid/malformed identity never
	 * issues a supplier query.
	 *
	 * @param int $product_id   Product id (parent id for a variation, own id for a simple product), or <=0 for a malformed request.
	 * @param int $variation_id Variation id, or 0 for a simple product.
	 * @return array{
	 *   status: 'prefilled'|'stale'|'invalid'|'malformed',
	 *   line: array{product_id:int, variation_id:int, name_snapshot:string, sku_snapshot:string, qty_ordered?:string}|null,
	 *   supplier_id: int,
	 *   notices: array<int, array{type:'info'|'warning', message:string}>,
	 * }
	 */
	public static function resolve( int $product_id, int $variation_id = 0 ): array {
		if ( $product_id <= 0 ) {
			return self::result( 'malformed', null, 0, array( self::notice_malformed() ) );
		}

		$resolved = WC_Inventory_Overview_PO_Product_Validator::validate( $product_id, $variation_id );
		if ( is_wp_error( $resolved ) ) {
			return self::result( 'invalid', null, 0, array( self::notice_invalid() ) );
		}

		// $resolved['product'] is always a WC_Product per PO_Product_Validator's contract.
		$product = $resolved['product'];
		$item_id = (int) ( $resolved['variation_id'] > 0 ? $resolved['variation_id'] : $resolved['product_id'] );
		$type    = $resolved['variation_id'] > 0
			? WC_Inventory_Overview_Inventory_Position_Service::TYPE_VARIATION
			: WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT;

		$on_hand   = (float) $product->get_stock_quantity();
		$threshold = (float) WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $product );
		$position  = WC_Inventory_Overview_Inventory_Position_Service::get_position( $type, $item_id, $on_hand );
		$signal    = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( (float) $position['position'], $threshold );

		if ( ! $signal['needs_reorder'] ) {
			return self::result( 'stale', null, 0, array( self::notice_stale() ) );
		}

		$line = array(
			'product_id'    => (int) $resolved['product_id'],
			'variation_id'  => (int) $resolved['variation_id'],
			'name_snapshot' => (string) $product->get_name(),
			'sku_snapshot'  => (string) $product->get_sku(),
		);

		list( $supplier_id, $supplier_notices ) = self::resolve_supplier(
			(int) $resolved['product_id'],
			(int) $resolved['variation_id'],
			$item_id
		);

		$default_qty = WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $item_id );
		if ( $default_qty > 0 ) {
			$line['qty_ordered'] = (string) $default_qty;
		}

		return self::result( 'prefilled', $line, $supplier_id, $supplier_notices );
	}

	/**
	 * Supplier resolution (M23 §10): a configured, currently eligible
	 * preferred supplier (WC_Inventory_Overview_Replenishment_Defaults) is
	 * used directly and the committed-history heuristic is not run at all.
	 * A configured but ineligible (archived/merged/deleted) preferred
	 * supplier falls back to the unchanged M22 history algorithm, plus a
	 * distinct notice (BR-M23-2/3/4). No preference configured -> byte-for-
	 * byte M22 behavior (BR-M23-3, INV-M23-7/18), including its fixed
	 * 2-query bound (INV-M22-16, unchanged).
	 *
	 * @param int $product_id    Parent id for a variation, own id for a simple product.
	 * @param int $variation_id  Variation id, or 0.
	 * @param int $item_post_id  Concrete purchasable item's own post id (§7): the variation's own id, or the simple product's own id.
	 * @return array{0:int,1:array<int,array{type:string,message:string}>}
	 */
	private static function resolve_supplier( int $product_id, int $variation_id, int $item_post_id ): array {
		$preferred_supplier_id = WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $item_post_id );

		if ( $preferred_supplier_id > 0 ) {
			$supplier_row = WC_Inventory_Overview_Suppliers::get( $preferred_supplier_id );
			if ( ! is_wp_error( $supplier_row ) && WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_row ) ) {
				// Happy path: no notice, matching M22's existing pattern of
				// only surfacing notices for imperfect/ambiguous states.
				return array( $preferred_supplier_id, array() );
			}

			list( $supplier_id, $history_notices ) = self::resolve_supplier_from_history( $product_id, $variation_id );
			return array( $supplier_id, array_merge( array( self::notice_preferred_supplier_stale() ), $history_notices ) );
		}

		return self::resolve_supplier_from_history( $product_id, $variation_id );
	}

	/**
	 * M22's original committed-history heuristic (§5), byte-for-byte
	 * unchanged: exactly 2 queries regardless of how many distinct
	 * suppliers have ever sold this item (INV-M22-16) — 1 history query +
	 * 1 bulk fetch, never a per-supplier Suppliers::get() loop.
	 *
	 * @param int $product_id   Parent id for a variation, own id for a simple product.
	 * @param int $variation_id Variation id, or 0.
	 * @return array{0:int,1:array<int,array{type:string,message:string}>}
	 */
	private static function resolve_supplier_from_history( int $product_id, int $variation_id ): array {
		$supplier_ids = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product_id, $variation_id );
		if ( empty( $supplier_ids ) ) {
			return array( 0, array( self::notice_no_supplier() ) );
		}

		$supplier_rows = WC_Inventory_Overview_Suppliers::list_by_ids( $supplier_ids );

		$eligible = array();
		foreach ( $supplier_ids as $supplier_id ) {
			if ( isset( $supplier_rows[ $supplier_id ] ) && WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_rows[ $supplier_id ] ) ) {
				$eligible[] = $supplier_id;
			}
		}

		if ( empty( $eligible ) ) {
			return array( 0, array( self::notice_no_supplier() ) );
		}
		if ( 1 === count( $eligible ) ) {
			return array( $eligible[0], array() );
		}
		return array( 0, array( self::notice_multiple_suppliers() ) );
	}

	/**
	 * Assemble the resolve() return shape.
	 *
	 * @param string     $status      Five-state contract value.
	 * @param array|null $line        Prefilled line, or null.
	 * @param int        $supplier_id Preselected supplier id, or 0.
	 * @param array      $notices     Informational/warning notices.
	 * @return array{status:string,line:array|null,supplier_id:int,notices:array}
	 */
	private static function result( string $status, ?array $line, int $supplier_id, array $notices ): array {
		return array(
			'status'      => $status,
			'line'        => $line,
			'supplier_id' => $supplier_id,
			'notices'     => $notices,
		);
	}

	/**
	 * Notice for the 'malformed' status (§8 case 2).
	 */
	private static function notice_malformed(): array {
		return array(
			'type'    => 'warning',
			'message' => __( 'The reorder link was invalid — showing a blank purchase order.', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Notice for the 'invalid' status (§8 case 3).
	 */
	private static function notice_invalid(): array {
		return array(
			'type'    => 'warning',
			'message' => __( 'This item could not be prefilled — showing a blank purchase order.', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Notice for the 'stale' status (§8 case 4).
	 */
	private static function notice_stale(): array {
		return array(
			'type'    => 'info',
			'message' => __( 'This item no longer appears to need reordering — showing a blank purchase order. You can still create one manually.', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Notice for a 'prefilled' outcome with zero eligible suppliers.
	 */
	private static function notice_no_supplier(): array {
		return array(
			'type'    => 'info',
			'message' => __( "No eligible supplier found in this item's purchase history — choose one below.", 'wc-inventory-overview' ),
		);
	}

	/**
	 * Notice for a 'prefilled' outcome with more than one eligible supplier.
	 */
	private static function notice_multiple_suppliers(): array {
		return array(
			'type'    => 'info',
			'message' => __( 'This item has purchase history with more than one supplier — choose one below.', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Notice (M23 BR-M23-4) for a configured preferred supplier that is no
	 * longer eligible (archived, merged, or deleted) — falls back to the
	 * committed-history heuristic.
	 */
	private static function notice_preferred_supplier_stale(): array {
		return array(
			'type'    => 'warning',
			'message' => __( "This item's preferred supplier is no longer available — showing a suggestion based on purchase history instead.", 'wc-inventory-overview' ),
		);
	}
}
