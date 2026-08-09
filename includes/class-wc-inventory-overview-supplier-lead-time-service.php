<?php
/**
 * Supplier Observed Lead-Time Service (M9).
 *
 * Sole owner of observed supplier lead-time computation. Read-only, derived,
 * Internal (not a public API — see docs/milestones/m9-implementation-plan.md
 * §8: no concrete external consumer exists yet; a future milestone may
 * promote this to a versioned public API without changing the computation
 * owned here). Never persists a result; every call recomputes from current
 * operational history (Purchase Orders, Goods Receipts, Receipt Lines —
 * the plan's §6.1 "Source of Truth").
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Computes, per supplier, observed delivery-time statistics from posted
 * Goods Receipts linked to that supplier's fully-received Purchase Orders.
 */
class WC_Inventory_Overview_Supplier_Lead_Time_Service {

	/**
	 * Presentation-layer threshold (plan §9): fewer than this many completed
	 * orders and the admin UI renders "not enough data yet" instead of the
	 * computed figures. The service still computes and returns the raw
	 * statistics below this threshold -- deciding not to *display* them is a
	 * UI policy, not a computation-scope cutoff. Kept here as the single
	 * source of truth for "2" so the UI never hardcodes its own copy.
	 */
	const MINIMUM_SAMPLE_COUNT_FOR_DISPLAY = 2;

	/**
	 * Resolves observed lead-time statistics for one supplier.
	 *
	 * Defined as get_stats_bulk() returning the single element, so single
	 * and bulk can never disagree (same discipline as Inventory Position /
	 * Expected Delivery).
	 *
	 * @param int $supplier_id Supplier ID.
	 * @return array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int}
	 */
	public static function get_stats_for_supplier( int $supplier_id ): array {
		$results = self::get_stats_bulk( array( $supplier_id ) );

		return reset( $results );
	}

	/**
	 * Resolves observed lead-time statistics for many suppliers in one pass.
	 *
	 * Exactly one SQL query regardless of how many supplier IDs are passed
	 * (plan §15 performance criteria) -- no N+1.
	 *
	 * @param array<int,int> $supplier_ids Supplier IDs.
	 * @return array<int,array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int}> Keyed by supplier ID.
	 */
	public static function get_stats_bulk( array $supplier_ids ): array {
		$ids = array();
		foreach ( $supplier_ids as $supplier_id ) {
			// Deliberately not absint(): absint() takes the absolute value,
			// so a negative ID (e.g. -1) would silently become a *different*
			// positive ID (1) instead of being rejected. A negative or zero
			// value is not a valid supplier ID and must be filtered out, not
			// reinterpreted as one.
			$id = (int) $supplier_id;
			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		$results = array();
		foreach ( $ids as $id ) {
			$results[ $id ] = self::empty_stats();
		}

		if ( empty( $ids ) ) {
			return $results;
		}

		$rows = self::query_observations( array_values( $ids ) );

		foreach ( $rows as $row ) {
			$supplier_id = (int) $row['supplier_id'];
			if ( ! isset( $results[ $supplier_id ] ) ) {
				continue;
			}

			$results[ $supplier_id ] = array(
				'has_data'     => true,
				'average_days' => (float) $row['average_days'],
				'fastest_days' => (int) $row['fastest_days'],
				'slowest_days' => (int) $row['slowest_days'],
				'sample_count' => (int) $row['sample_count'],
			);
		}

		return $results;
	}

	/**
	 * The "no data" result shape.
	 *
	 * @return array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int}
	 */
	private static function empty_stats(): array {
		return array(
			'has_data'     => false,
			'average_days' => null,
			'fastest_days' => null,
			'slowest_days' => null,
			'sample_count' => 0,
		);
	}

	/**
	 * One grouped aggregate query, per requested supplier IDs.
	 *
	 * Observation definition (plan §6): a Purchase Order qualifies only when
	 * status = 'received', placed_at is set, and at least one of its lines
	 * has at least one posted, non-migrated Goods Receipt line referencing
	 * it. Observed delivery days for that PO is DATEDIFF() between placed_at
	 * and the *latest* qualifying receipt's posted_at -- never the first
	 * receipt (a PO reaches 'received' only once every line is fully
	 * received; the last contributing receipt is what actually completed
	 * the order) and never MAX(id)/insertion order (posted_at is the only
	 * signal that may ever determine "latest").
	 *
	 * Migrated (M6) receipts are excluded both naturally (their lines never
	 * carry a po_line_id, so the INNER JOIN on po_line_id already excludes
	 * them) and explicitly (gr.source != 'migrated'), so a future edit to
	 * the join can never silently reintroduce pre-PO-era batch data with no
	 * real lead-time meaning.
	 *
	 * @param array<int,int> $supplier_ids Supplier IDs (already validated, non-empty).
	 * @return array<int,array{supplier_id:int,average_days:float,fastest_days:int,slowest_days:int,sample_count:int}>
	 */
	private static function query_observations( array $supplier_ids ): array {
		global $wpdb;

		$po_table    = WC_Inventory_Overview_Purchase_Orders::table_name();
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$rl_table    = WC_Inventory_Overview_Receipt_Lines::table_name();
		$gr_table    = WC_Inventory_Overview_Goods_Receipts::table_name();

		$placeholders = implode( ',', array_fill( 0, count( $supplier_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal constants, never user input; %d placeholders cover every user-influenced value.
		$sql = "
			SELECT
				supplier_id,
				AVG(observed_days) AS average_days,
				MIN(observed_days) AS fastest_days,
				MAX(observed_days) AS slowest_days,
				COUNT(*) AS sample_count
			FROM (
				SELECT
					po.id AS po_id,
					po.supplier_id AS supplier_id,
					DATEDIFF( MAX( gr.posted_at ), po.placed_at ) AS observed_days
				FROM {$po_table} po
				INNER JOIN {$lines_table} pol ON pol.po_id = po.id
				INNER JOIN {$rl_table} rl ON rl.po_line_id = pol.id
				INNER JOIN {$gr_table} gr ON gr.id = rl.receipt_id
				WHERE po.status = %s
				  AND po.placed_at IS NOT NULL
				  AND po.supplier_id IN ({$placeholders})
				  AND gr.status = %s
				  AND gr.source != %s
				GROUP BY po.id, po.supplier_id, po.placed_at
			) AS po_observations
			GROUP BY supplier_id
		";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$params = array_merge(
			array(
				WC_Inventory_Overview_PO_Statuses::RECEIVED,
			),
			$supplier_ids,
			array(
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
				WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED,
			)
		);

		$prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows     = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
