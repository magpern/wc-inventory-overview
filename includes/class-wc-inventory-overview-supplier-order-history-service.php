<?php
/**
 * Supplier Order History Service (M14).
 *
 * Sole owner of the supplier-order-history read projection: a paginated,
 * status-inclusive list of a supplier's Purchase Orders, each row carrying
 * its own PO-cost-only ordered/received value (INV-M14-2 -- never summed
 * across POs, never conflated with landed cost or inventory valuation).
 * Read-only, derived, Internal -- not a public API (D16), same
 * classification as WC_Inventory_Overview_Supplier_Lead_Time_Service and
 * WC_Inventory_Overview_Expected_Date_Suggestion_Service. Never persists a
 * result; every call recomputes from current Purchase Order data.
 *
 * Composes exclusively through WC_Inventory_Overview_Purchase_Orders's own
 * read methods (INV-M14-3) -- never $wpdb directly, never
 * Purchase_Order_Lines or Goods_Receipts directly.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Composes a supplier's paginated Purchase Order history.
 */
class WC_Inventory_Overview_Supplier_Order_History_Service {

	/**
	 * Default page size (plan §13). A named constant, not a magic number,
	 * matching Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY's
	 * convention.
	 */
	const DEFAULT_PER_PAGE = 20;

	/**
	 * Resolve one page of a supplier's order history.
	 *
	 * Every PO status is included (INV-M14-4) -- draft, placed,
	 * partially_received, received, cancelled, closed_short all appear.
	 * Sort is fixed at order_date DESC (plan §7); a mechanical secondary
	 * id-DESC tie-break is not enforced here because the underlying
	 * Purchase_Orders::list() read owner accepts only a single ORDER BY
	 * column (its existing, unmodified contract, shared by every other
	 * screen that sorts POs) -- see docs/checklists/m14-release-readiness.md
	 * for the accepted-limitation record.
	 *
	 * Query cost (plan §17): exactly 1 query when the supplier has zero POs
	 * (count() only); exactly 3 when the page has rows (count() + list() +
	 * values_bulk()); exactly 2 for a count()>0 but out-of-range page
	 * (list() returns no rows, so values_bulk() is skipped) -- in every
	 * case, independent of total history size.
	 *
	 * @param int $supplier_id Supplier id.
	 * @param int $page        1-based page number (clamped to >= 1).
	 * @param int $per_page    Page size (falls back to DEFAULT_PER_PAGE if <= 0).
	 * @return array{rows:array<int,array<string,mixed>>,count:int,page:int,per_page:int}
	 */
	public static function get_page( int $supplier_id, int $page = 1, int $per_page = self::DEFAULT_PER_PAGE ): array {
		$page     = max( 1, $page );
		$per_page = $per_page > 0 ? $per_page : self::DEFAULT_PER_PAGE;

		$count = WC_Inventory_Overview_Purchase_Orders::count( array( 'supplier_id' => $supplier_id ) );

		if ( 0 === $count ) {
			return array(
				'rows'     => array(),
				'count'    => 0,
				'page'     => $page,
				'per_page' => $per_page,
			);
		}

		$offset = ( $page - 1 ) * $per_page;

		$purchase_orders = WC_Inventory_Overview_Purchase_Orders::list(
			array(
				'supplier_id' => $supplier_id,
				'orderby'     => 'order_date',
				'order'       => 'DESC',
				'per_page'    => $per_page,
				'offset'      => $offset,
			)
		);

		$po_ids = array();
		foreach ( $purchase_orders as $po ) {
			$po_ids[] = (int) $po['id'];
		}

		$values = empty( $po_ids ) ? array() : WC_Inventory_Overview_Purchase_Orders::values_bulk( $po_ids );

		$rows = array();
		foreach ( $purchase_orders as $po ) {
			$po_id     = (int) $po['id'];
			$po_values = $values[ $po_id ] ?? array(
				'ordered'  => 0.0,
				'received' => 0.0,
			);

			$rows[] = array(
				'po_id'               => $po_id,
				'po_number'           => (string) $po['po_number'],
				'order_date'          => $po['order_date'],
				'status'              => (string) $po['status'],
				'expected_date'       => $po['expected_date'],
				'expected_confidence' => (string) $po['expected_confidence'],
				'currency'            => (string) $po['currency'],
				'ordered_value'       => (float) $po_values['ordered'],
				'received_value'      => (float) $po_values['received'],
			);
		}

		return array(
			'rows'     => $rows,
			'count'    => $count,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}
}
