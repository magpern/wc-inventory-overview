<?php
/**
 * Supplier Observed Lead-Time and On-Time Delivery Statistics Service
 * (M9 + M11).
 *
 * Sole owner of observed supplier lead-time computation, and (M11) of the
 * historical on-time delivery rate derived from the same qualifying-order
 * dataset. Read-only, derived, Internal (not a public API — see
 * docs/milestones/m9-implementation-plan.md §8 / m11-implementation-plan.md
 * §7: no concrete external consumer exists yet; a future milestone may
 * promote this to a versioned public API without changing the computation
 * owned here). Never persists a result; every call recomputes from current
 * operational history (Purchase Orders, Goods Receipts, Receipt Lines —
 * the plan's §6.1 "Source of Truth"). The on-time judgment reuses
 * WC_Inventory_Overview_Expected_Deadline for the deadline formula and
 * known-date eligibility rule (INV-M11-2) -- this service never duplicates
 * that arithmetic itself.
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
	 * Resolves observed lead-time and on-time delivery statistics for one
	 * supplier.
	 *
	 * Defined as get_stats_bulk() returning the single element, so single
	 * and bulk can never disagree (same discipline as Inventory Position /
	 * Expected Delivery).
	 *
	 * @param int $supplier_id Supplier ID.
	 * @param int $grace_days  Grace days (>= 0) applied to the on-time deadline (plan §9) -- callers source this from WC_Inventory_Overview_PO_Delay::grace_days_from_option(); this service never reads the option itself.
	 * @return array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int}
	 */
	public static function get_stats_for_supplier( int $supplier_id, int $grace_days = 0 ): array {
		$results = self::get_stats_bulk( array( $supplier_id ), $grace_days );

		return reset( $results );
	}

	/**
	 * Resolves observed lead-time and on-time delivery statistics for many
	 * suppliers in one pass.
	 *
	 * Exactly one SQL query regardless of how many supplier IDs are passed
	 * (plan §15/§17 performance criteria) -- no N+1, and M11 adds zero
	 * additional queries (the existing single query is widened, not
	 * duplicated).
	 *
	 * @param array<int,int> $supplier_ids Supplier IDs.
	 * @param int            $grace_days   Grace days (>= 0) applied to the on-time deadline.
	 * @return array<int,array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int}> Keyed by supplier ID.
	 */
	public static function get_stats_bulk( array $supplier_ids, int $grace_days = 0 ): array {
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

		$rows = self::query_observations( array_values( $ids ), max( 0, $grace_days ) );

		foreach ( $rows as $row ) {
			$supplier_id = (int) $row['supplier_id'];
			if ( ! isset( $results[ $supplier_id ] ) ) {
				continue;
			}

			$results[ $supplier_id ] = array(
				'has_data'          => true,
				'average_days'      => (float) $row['average_days'],
				'fastest_days'      => (int) $row['fastest_days'],
				'slowest_days'      => (int) $row['slowest_days'],
				'sample_count'      => (int) $row['sample_count'],
				'on_time_count'     => (int) $row['on_time_count'],
				'rated_order_count' => (int) $row['rated_order_count'],
			);
		}

		return $results;
	}

	/**
	 * Whether a get_stats_bulk()/get_stats_for_supplier() result carries
	 * enough history to be used, by any caller -- the single source of
	 * truth for "is this observed average good enough," so no caller
	 * (M10's Expected_Date_Suggestion_Service and any future consumer)
	 * ever needs to know or duplicate the MINIMUM_SAMPLE_COUNT_FOR_DISPLAY
	 * threshold itself.
	 *
	 * @param array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int} $stats One supplier's result from get_stats_bulk()/get_stats_for_supplier().
	 * @return bool
	 */
	public static function is_observed_value_usable( array $stats ): bool {
		return $stats['has_data'] && $stats['sample_count'] >= self::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY;
	}

	/**
	 * Whether a get_stats_bulk()/get_stats_for_supplier() result carries
	 * enough rated (known-expected-date) completed orders for the on-time
	 * delivery rate to be displayed (M11, plan §9). Independent of
	 * is_observed_value_usable(): rated_order_count can be smaller than
	 * sample_count, since some completed orders may have unknown confidence
	 * and are excluded from both (INV-M11-1).
	 *
	 * @param array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int} $stats One supplier's result from get_stats_bulk()/get_stats_for_supplier().
	 * @return bool
	 */
	public static function is_on_time_rate_usable( array $stats ): bool {
		return $stats['has_data'] && $stats['rated_order_count'] >= self::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY;
	}

	/**
	 * The "no data" result shape.
	 *
	 * @return array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int}
	 */
	private static function empty_stats(): array {
		return array(
			'has_data'          => false,
			'average_days'      => null,
			'fastest_days'      => null,
			'slowest_days'      => null,
			'sample_count'      => 0,
			'on_time_count'     => 0,
			'rated_order_count' => 0,
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
	 * On-time computation (M11, plan §9): reuses the header's expected_date/
	 * expected_confidence -- already frozen by the time a PO reaches
	 * 'received' (WC_Inventory_Overview_PO_Lifecycle::is_editable()) -- and
	 * WC_Inventory_Overview_Expected_Deadline's SQL micro-fragments for the
	 * known-date eligibility check and the deadline expression (INV-M11-2:
	 * the deadline formula is never duplicated here). Completion is compared
	 * at calendar-day granularity (DATE()), matching this same query's own
	 * DATEDIFF()-based observed_days figure and M10's calendar-days-only
	 * decision -- a receipt posted on the deadline's own calendar day still
	 * counts as on-time (inclusive boundary).
	 *
	 * @param array<int,int> $supplier_ids Supplier IDs (already validated, non-empty).
	 * @param int            $grace_days   Grace days (>= 0), already validated non-negative.
	 * @return array<int,array{supplier_id:int,average_days:float,fastest_days:int,slowest_days:int,sample_count:int,on_time_count:int,rated_order_count:int}>
	 */
	private static function query_observations( array $supplier_ids, int $grace_days = 0 ): array {
		global $wpdb;

		$po_table    = WC_Inventory_Overview_Purchase_Orders::table_name();
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$rl_table    = WC_Inventory_Overview_Receipt_Lines::table_name();
		$gr_table    = WC_Inventory_Overview_Goods_Receipts::table_name();

		$placeholders = implode( ',', array_fill( 0, count( $supplier_ids ), '%d' ) );

		$known_date_expr = WC_Inventory_Overview_Expected_Deadline::sql_has_known_date_expression( 'expected_date', 'expected_confidence' );
		$deadline_expr   = WC_Inventory_Overview_Expected_Deadline::sql_deadline_expression( 'expected_date', $grace_days );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are internal constants, never user input; %d placeholders cover every user-influenced value.
		$sql = "
			SELECT
				supplier_id,
				AVG(observed_days) AS average_days,
				MIN(observed_days) AS fastest_days,
				MAX(observed_days) AS slowest_days,
				COUNT(*) AS sample_count,
				SUM(CASE WHEN {$known_date_expr} THEN 1 ELSE 0 END) AS rated_order_count,
				SUM(CASE WHEN {$known_date_expr} AND DATE(completed_at) <= {$deadline_expr} THEN 1 ELSE 0 END) AS on_time_count
			FROM (
				SELECT
					po.id AS po_id,
					po.supplier_id AS supplier_id,
					DATEDIFF( MAX( gr.posted_at ), po.placed_at ) AS observed_days,
					MAX( gr.posted_at ) AS completed_at,
					po.expected_date AS expected_date,
					po.expected_confidence AS expected_confidence
				FROM {$po_table} po
				INNER JOIN {$lines_table} pol ON pol.po_id = po.id
				INNER JOIN {$rl_table} rl ON rl.po_line_id = pol.id
				INNER JOIN {$gr_table} gr ON gr.id = rl.receipt_id
				WHERE po.status = %s
				  AND po.placed_at IS NOT NULL
				  AND po.supplier_id IN ({$placeholders})
				  AND gr.status = %s
				  AND gr.source != %s
				GROUP BY po.id, po.supplier_id, po.placed_at, po.expected_date, po.expected_confidence
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
