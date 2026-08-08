<?php
/**
 * Computed PO delay detection (INV-5) — M2-B.
 *
 * Delayed is never stored. Pure calculator accepts grace days as an argument;
 * WordPress option lookup belongs to callers.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Delay calculator and SQL fragment builder.
 */
class WC_Inventory_Overview_PO_Delay {

	const OPTION_GRACE_DAYS  = 'wc_io_po_delay_grace_days';
	const DEFAULT_GRACE_DAYS = 0;

	/**
	 * Read grace days from the WordPress option (default 0).
	 */
	public static function grace_days_from_option(): int {
		$raw = get_option( self::OPTION_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );
		return max( 0, (int) $raw );
	}

	/**
	 * Whether a single line is delayed.
	 *
	 * @param string      $po_status            PO status.
	 * @param float       $outstanding          Outstanding qty.
	 * @param string|null $effective_date       Y-m-d or null.
	 * @param string      $effective_confidence Confidence.
	 * @param int         $grace_days           Grace days (>= 0).
	 * @param string|null $today                Y-m-d; defaults to current site date.
	 */
	public static function is_line_delayed(
		string $po_status,
		float $outstanding,
		$effective_date,
		string $effective_confidence,
		int $grace_days = 0,
		$today = null
	): bool {
		// M8/WP2: extends the placed-only gate to partially_received -- a PO
		// that has received some, but not all, of a line's ordered quantity
		// can still be genuinely overdue on its remaining outstanding. The
		// $outstanding <= 0 check just below already excludes a partially
		// received line with nothing left outstanding, and a fully received
		// PO (status = received) always has outstanding = 0 by INV-4, so
		// this gate is deliberately not widened to "received" as well.
		$delayable_statuses = array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
		);
		if ( ! in_array( $po_status, $delayable_statuses, true ) ) {
			return false;
		}
		if ( $outstanding <= 0 ) {
			return false;
		}
		if ( WC_Inventory_Overview_PO_Confidence::UNKNOWN === $effective_confidence ) {
			return false;
		}
		if ( null === $effective_date || '' === $effective_date ) {
			return false;
		}

		$today      = null === $today ? self::today() : (string) $today;
		$grace_days = max( 0, $grace_days );

		$deadline = self::add_days( (string) $effective_date, $grace_days );
		if ( null === $deadline ) {
			return false;
		}

		return $deadline < $today;
	}

	/**
	 * Whether a PO is delayed given its status and lines.
	 *
	 * @param string                         $po_status            PO status.
	 * @param string|null                    $header_date          Header expected date.
	 * @param string                         $header_confidence    Header confidence.
	 * @param array<int,array<string,mixed>> $lines            Line rows.
	 * @param int                            $grace_days           Grace days.
	 * @param string|null                    $today                Y-m-d.
	 */
	public static function is_po_delayed(
		string $po_status,
		$header_date,
		string $header_confidence,
		array $lines,
		int $grace_days = 0,
		$today = null
	): bool {
		foreach ( $lines as $line ) {
			$effective_date       = WC_Inventory_Overview_PO_Expected::effective_date(
				$line['expected_date'] ?? null,
				$header_date
			);
			$effective_confidence = WC_Inventory_Overview_PO_Expected::effective_confidence(
				$line['expected_confidence'] ?? null,
				$header_confidence
			);
			$outstanding          = WC_Inventory_Overview_PO_Quantities::outstanding(
				$line['qty_ordered'] ?? 0,
				$line['qty_received'] ?? 0,
				$line['qty_cancelled'] ?? 0
			);
			if ( self::is_line_delayed( $po_status, $outstanding, $effective_date, $effective_confidence, $grace_days, $today ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SQL predicate (boolean expression) that is true when a PO has at least one delayed line.
	 *
	 * Aliases:
	 * - po  = purchase_orders header
	 * - pol = purchase_order_lines
	 *
	 * Callers wrap this in EXISTS ( SELECT 1 FROM ... pol WHERE pol.po_id = po.id AND {fragment} ).
	 *
	 * @param int         $grace_days Grace days (>= 0).
	 * @param string|null $today      Y-m-d literal; defaults to CURDATE()-equivalent site date.
	 * @return string SQL boolean expression (no leading AND).
	 */
	public static function sql_line_delayed_predicate( int $grace_days = 0, $today = null ): string {
		$grace_days = max( 0, $grace_days );
		$today_sql  = null === $today
			? 'CURDATE()'
			: "'" . esc_sql( (string) $today ) . "'";

		$effective_date = 'COALESCE(NULLIF(pol.expected_date, \'0000-00-00\'), NULLIF(po.expected_date, \'0000-00-00\'))';
		$effective_conf = "COALESCE(NULLIF(pol.expected_confidence, ''), NULLIF(po.expected_confidence, ''), 'unknown')";
		$outstanding    = 'GREATEST(0, pol.qty_ordered - pol.qty_received - pol.qty_cancelled)';

		// M8/WP2: 'placed' and 'partially_received' only -- mirrors
		// is_line_delayed()'s PHP-side gate above and the same M5 precedent
		// query_open_lines() already established for Incoming purposes.
		// 'received' is deliberately excluded: a fully received line always
		// has outstanding = 0 (INV-4), so it can never satisfy the
		// "(%s) > 0" clause below regardless.
		return sprintf(
			"po.status IN ('placed', 'partially_received')
			AND (%s) > 0
			AND (%s) <> 'unknown'
			AND (%s) IS NOT NULL
			AND DATE_ADD((%s), INTERVAL %d DAY) < %s",
			$outstanding,
			$effective_conf,
			$effective_date,
			$effective_date,
			$grace_days,
			$today_sql
		);
	}

	/**
	 * EXISTS subquery usable as a WHERE/SELECT delayed flag.
	 *
	 * @param string      $po_alias   Header table alias (default po).
	 * @param int         $grace_days Grace days.
	 * @param string|null $today      Y-m-d.
	 */
	public static function sql_po_is_delayed_exists( string $po_alias = 'po', int $grace_days = 0, $today = null ): string {
		global $wpdb;
		$lines = $wpdb->prefix . 'wc_io_purchase_order_lines';
		$pred  = self::sql_line_delayed_predicate( $grace_days, $today );
		// Rewrite aliases if header alias is not "po".
		if ( 'po' !== $po_alias ) {
			$pred = str_replace( 'po.', $po_alias . '.', $pred );
		}
		return "EXISTS (
			SELECT 1 FROM {$lines} pol
			WHERE pol.po_id = {$po_alias}.id
			AND ({$pred})
		)";
	}

	/**
	 * Site-local today as Y-m-d.
	 */
	public static function today(): string {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Add days to a Y-m-d date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add.
	 * @return string|null
	 */
	public static function add_days( string $date, int $days ) {
		try {
			$dt = new DateTimeImmutable( $date, new DateTimeZone( 'UTC' ) );
		} catch ( Exception $e ) {
			return null;
		}
		if ( $days > 0 ) {
			$dt = $dt->modify( '+' . $days . ' days' );
		}
		return $dt->format( 'Y-m-d' );
	}
}
