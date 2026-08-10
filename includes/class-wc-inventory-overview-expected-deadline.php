<?php
/**
 * Expected-deadline policy primitive (M11).
 *
 * Sole owner of the atomic rule "given an expected date, confidence, and
 * grace-day count, what is the deadline -- or is there no knowable deadline
 * at all" (docs/milestones/m11-implementation-plan.md §7, INV-M11-2).
 * Deliberately closed to exactly the four methods below -- it must never
 * grow into a general SQL-expression provider. It knows nothing about PO
 * status, outstanding quantities, "today," comparison direction, line/header
 * inheritance, or WordPress options; every caller (WC_Inventory_Overview_PO_Delay,
 * WC_Inventory_Overview_Supplier_Lead_Time_Service) composes these atoms into
 * its own, differently-shaped query or predicate.
 *
 * Pure and stateless: no $wpdb usage, no writes, no WordPress option access.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Deadline arithmetic and known-date eligibility, as pure PHP and as
 * minimal SQL micro-fragments.
 */
class WC_Inventory_Overview_Expected_Deadline {

	/**
	 * Whether a deadline is knowable at all for the given date/confidence.
	 *
	 * @param string|null $expected_date       Y-m-d, or null/empty/'0000-00-00'.
	 * @param string      $expected_confidence One of WC_Inventory_Overview_PO_Confidence's values.
	 * @return bool
	 */
	public static function has_known_date( $expected_date, string $expected_confidence ): bool {
		if ( WC_Inventory_Overview_PO_Confidence::UNKNOWN === $expected_confidence ) {
			return false;
		}
		if ( null === $expected_date || '' === $expected_date || '0000-00-00' === $expected_date ) {
			return false;
		}
		return true;
	}

	/**
	 * The deadline (expected_date + grace_days), or null if none is knowable.
	 *
	 * @param string|null $expected_date       Y-m-d, or null/empty/'0000-00-00'.
	 * @param string      $expected_confidence One of WC_Inventory_Overview_PO_Confidence's values.
	 * @param int         $grace_days          Grace days (>= 0).
	 * @return string|null Y-m-d, or null.
	 */
	public static function deadline( $expected_date, string $expected_confidence, int $grace_days ): ?string {
		if ( ! self::has_known_date( $expected_date, $expected_confidence ) ) {
			return null;
		}
		return self::add_days( (string) $expected_date, max( 0, $grace_days ) );
	}

	/**
	 * SQL micro-fragment: the deadline expression alone (no comparison, no
	 * eligibility check). Callers compose those in their own query shape.
	 *
	 * @param string $date_sql_expr SQL expression evaluating to a Y-m-d date.
	 * @param int    $grace_days    Grace days (>= 0).
	 * @return string SQL expression.
	 */
	public static function sql_deadline_expression( string $date_sql_expr, int $grace_days ): string {
		$grace_days = max( 0, $grace_days );
		return sprintf( 'DATE_ADD(%s, INTERVAL %d DAY)', $date_sql_expr, $grace_days );
	}

	/**
	 * SQL micro-fragment: the known-date eligibility condition alone.
	 *
	 * @param string $date_sql_expr       SQL expression evaluating to a Y-m-d date (or NULL/'0000-00-00').
	 * @param string $confidence_sql_expr SQL expression evaluating to a confidence string.
	 * @return string SQL boolean expression (no leading AND).
	 */
	public static function sql_has_known_date_expression( string $date_sql_expr, string $confidence_sql_expr ): string {
		return sprintf(
			"(%s) IS NOT NULL AND NULLIF(%s, '0000-00-00') IS NOT NULL AND (%s) <> 'unknown'",
			$date_sql_expr,
			$date_sql_expr,
			$confidence_sql_expr
		);
	}

	/**
	 * Add days to a Y-m-d date.
	 *
	 * @param string $date Y-m-d.
	 * @param int    $days Days to add (>= 0).
	 * @return string|null Y-m-d, or null if $date could not be parsed.
	 */
	private static function add_days( string $date, int $days ): ?string {
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
