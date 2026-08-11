<?php
/**
 * Supplier Spend Service (M15).
 *
 * Sole owner of the supplier-spend-summary read projection: a per-currency
 * total of ordered/received value across a supplier's *committed* Purchase
 * Orders (BR-M15-1) -- placed, partially_received, received, closed_short
 * only; draft and cancelled never contribute (INV-M15-1). Currencies are
 * never blended or FX-converted -- one row per currency actually present
 * in the supplier's committed lines (INV-M15-2). Read-only, derived,
 * Internal -- not a public API (D16), same classification as
 * WC_Inventory_Overview_Supplier_Order_History_Service and
 * WC_Inventory_Overview_Supplier_Lead_Time_Service. Never persists a
 * result; every call recomputes from current Purchase Order data.
 *
 * Composes exclusively through
 * WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier()
 * (INV-M15-3 -- mirrors INV-M14-3) -- never $wpdb directly, never
 * Purchase_Order_Lines, Goods_Receipts, Receipt_Lines, Receipt_Costs, or
 * Suppliers directly.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Composes a supplier's per-currency committed-spend summary.
 */
class WC_Inventory_Overview_Supplier_Spend_Service {

	/**
	 * Committed statuses (BR-M15-1): a PO must be actually placed to count
	 * as spend. draft (not yet a commitment) and cancelled (never fulfilled)
	 * are always excluded.
	 *
	 * @return string[]
	 */
	public static function committed_statuses(): array {
		return array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);
	}

	/**
	 * Resolve a supplier's committed-spend summary, one row per currency.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return array<int,array{currency:string,ordered_total:float,received_total:float,po_count:int}>
	 *         Empty array if the supplier has no committed-status Purchase Orders.
	 */
	public static function get_summary( int $supplier_id ): array {
		return WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( $supplier_id, self::committed_statuses() );
	}
}
