<?php
/**
 * Pure expected-delivery selection algorithm (M7).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Picks the one customer-safe expected receipt out of a set of open PO lines.
 *
 * @internal Not public API. Callers must go through WC_Inventory_Overview_Expected_Delivery_Service.
 *
 * Pure, deterministic, total: no WordPress functions, no I/O, no clock lookup.
 * "Now" is always a caller-supplied parameter (the same pattern
 * WC_Inventory_Overview_PO_Delay already establishes), which keeps this class
 * unit-testable at any simulated date and keeps date comparisons
 * site-timezone-consistent from the Service's single current_time() call.
 */
final class WC_Inventory_Overview_Expected_Delivery_Resolver {

	/**
	 * @param bool   $available_now WooCommerce's is_in_stock() answer for this item.
	 * @param array  $incoming_lines Raw open PO-line rows (as returned inside
	 *                               Inventory_Position_Service::get_positions_bulk()'s
	 *                               `incoming_lines`). Every value is a string except
	 *                               `expected_date`, which is `Y-m-d` or null.
	 * @param string $today          Today's date as `Y-m-d`. Never looked up here.
	 * @return array{state: string, expected_date: string|null, confidence: string|null}
	 */
	public static function resolve( bool $available_now, array $incoming_lines, string $today ): array {
		if ( $available_now ) {
			return array(
				'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK,
				'expected_date' => null,
				'confidence'    => null,
			);
		}

		$customer_safe_lines = array();
		$has_open_supply     = false;

		foreach ( $incoming_lines as $line ) {
			$outstanding = isset( $line['outstanding'] ) ? (float) $line['outstanding'] : 0.0;

			if ( $outstanding > 0 ) {
				$has_open_supply = true;
			}

			if ( self::is_customer_safe( $line, $outstanding, $today ) ) {
				$customer_safe_lines[] = $line;
			}
		}

		if ( empty( $customer_safe_lines ) ) {
			return array(
				'state'         => $has_open_supply
					? WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON
					: WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE,
				'expected_date' => null,
				'confidence'    => null,
			);
		}

		usort( $customer_safe_lines, array( self::class, 'compare_lines' ) );

		$winner = $customer_safe_lines[0];

		return array(
			'state'         => WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE,
			'expected_date' => (string) $winner['expected_date'],
			'confidence'    => (string) $winner['expected_confidence'],
		);
	}

	/**
	 * customer_safe(line) predicate. Named after the governance test it applies,
	 * not "credible" — see the plan's terminology note. A line fails not because
	 * it is improbable but because it is delayed, undated, unknown-confidence,
	 * or already in the past (Invariant M7-1).
	 *
	 * @param array  $line        Raw PO-line row.
	 * @param float  $outstanding Pre-computed `(float) $line['outstanding']`.
	 * @param string $today       `Y-m-d`.
	 * @return bool
	 */
	private static function is_customer_safe( array $line, float $outstanding, string $today ): bool {
		if ( $outstanding <= 0 ) {
			return false;
		}

		if ( ! empty( $line['is_delayed'] ) ) {
			return false;
		}

		$confidence = isset( $line['expected_confidence'] ) ? (string) $line['expected_confidence'] : '';
		if ( 'unknown' === $confidence ) {
			return false;
		}

		$expected_date = isset( $line['expected_date'] ) ? $line['expected_date'] : null;
		if ( ! self::is_valid_date( $expected_date ) ) {
			return false;
		}

		// Invariant M7-1: a customer-safe line's date is never in the past.
		return (string) $expected_date >= $today;
	}

	/**
	 * Y-m-d validity check. Deliberately reimplemented rather than delegating
	 * to WC_Inventory_Overview_PO_Validation::validate_date(), which returns a
	 * WP_Error — a WordPress class this Resolver must never touch.
	 *
	 * @param mixed $date Candidate date value.
	 * @return bool
	 */
	private static function is_valid_date( $date ): bool {
		if ( ! is_string( $date ) || '' === $date ) {
			return false;
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return false;
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] );
	}

	/**
	 * Deterministic sort: expected_date ASC, confidence_rank ASC, line_id ASC.
	 *
	 * @param array $a Left line.
	 * @param array $b Right line.
	 * @return int
	 */
	private static function compare_lines( array $a, array $b ): int {
		$date_cmp = strcmp( (string) $a['expected_date'], (string) $b['expected_date'] );
		if ( 0 !== $date_cmp ) {
			return $date_cmp;
		}

		$confidence_cmp = self::confidence_rank( (string) $a['expected_confidence'] ) <=> self::confidence_rank( (string) $b['expected_confidence'] );
		if ( 0 !== $confidence_cmp ) {
			return $confidence_cmp;
		}

		return (int) $a['line_id'] <=> (int) $b['line_id'];
	}

	/**
	 * @param string $confidence 'exact'|'estimated'|other.
	 * @return int
	 */
	private static function confidence_rank( string $confidence ): int {
		if ( 'exact' === $confidence ) {
			return 0;
		}

		if ( 'estimated' === $confidence ) {
			return 1;
		}

		return 2;
	}
}
