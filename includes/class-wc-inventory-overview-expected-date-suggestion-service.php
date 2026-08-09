<?php
/**
 * Expected-Date Suggestion Service (M10).
 *
 * Sole owner of the observed/configured/none suggestion policy (recommendation
 * policy) -- see docs/milestones/m10-implementation-plan.md §5. Read-only,
 * Internal (not a public API -- D16: no concrete external consumer exists
 * yet). Never persists a result; a suggestion is a transient render-time
 * value only ever written through the existing Purchase Order
 * expected_date/expected_confidence form-submission path, exactly like a
 * manually-typed value.
 *
 * Purchase Order creation (WC_Inventory_Overview_PO_Admin) is this
 * service's first consumer, not an intrinsic part of its identity -- the
 * underlying algorithm is not PO-specific (plan §5).
 *
 * Ownership split (plan §5): WC_Inventory_Overview_Supplier_Lead_Time_Service
 * (M9) owns observed *statistics*; this service owns *recommendation
 * policy* built from those statistics plus the configured fallback. Never
 * duplicate the observed-lead-time computation or the "is this average
 * usable" threshold here -- both belong to Supplier_Lead_Time_Service.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Combines observed and configured supplier lead-time signals into an
 * advisory expected-date suggestion (days + confidence).
 */
class WC_Inventory_Overview_Expected_Date_Suggestion_Service {

	/**
	 * Resolves a suggestion for one supplier.
	 *
	 * Defined as get_suggestions_bulk() returning the single element, so
	 * single and bulk can never disagree (same discipline as Supplier
	 * Lead Time / Inventory Position / Expected Delivery).
	 *
	 * @param array<string,mixed> $supplier Supplier row (must include `id`; `default_lead_time_days` used if present).
	 * @return array{days:?int,confidence:?string,source:string}
	 */
	public static function get_suggestion_for_supplier( array $supplier ): array {
		$id      = isset( $supplier['id'] ) ? (int) $supplier['id'] : 0;
		$results = self::get_suggestions_bulk( array( $supplier ) );

		return $results[ $id ] ?? self::empty_suggestion();
	}

	/**
	 * Resolves suggestions for many suppliers in one pass.
	 *
	 * Exactly one additional query regardless of how many suppliers are
	 * passed (Supplier_Lead_Time_Service::get_stats_bulk() is itself a
	 * single grouped-aggregate query) -- no N+1. Takes already-loaded
	 * supplier rows (as the PO creation page already has for its dropdown)
	 * rather than bare IDs, so no redundant Suppliers lookup is issued.
	 *
	 * @param array<int,array<string,mixed>> $suppliers Supplier rows (each must include `id`).
	 * @return array<int,array{days:?int,confidence:?string,source:string}> Keyed by supplier ID.
	 */
	public static function get_suggestions_bulk( array $suppliers ): array {
		$by_id = array();
		foreach ( $suppliers as $supplier ) {
			$id = isset( $supplier['id'] ) ? (int) $supplier['id'] : 0;
			if ( $id > 0 ) {
				$by_id[ $id ] = $supplier;
			}
		}

		$results = array();
		foreach ( $by_id as $id => $supplier ) {
			$results[ $id ] = self::empty_suggestion();
		}

		if ( empty( $by_id ) ) {
			return $results;
		}

		$stats_by_id = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( array_keys( $by_id ) );

		foreach ( $by_id as $id => $supplier ) {
			$results[ $id ] = self::resolve_one( $supplier, $stats_by_id[ $id ] ?? null );
		}

		return $results;
	}

	/**
	 * Resolution rule (plan §5.2): usable observed average, else configured
	 * fallback, else no suggestion. Calendar days only -- no business-day,
	 * elapsed-hour, or holiday-aware arithmetic (plan §5.2).
	 *
	 * @param array<string,mixed>                                                                    $supplier Supplier row.
	 * @param array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int}|null $stats    This supplier's Supplier_Lead_Time_Service result, if any.
	 * @return array{days:?int,confidence:?string,source:string}
	 */
	private static function resolve_one( array $supplier, ?array $stats ): array {
		if ( null !== $stats && WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $stats ) ) {
			return array(
				'days'       => (int) round( $stats['average_days'] ),
				'confidence' => WC_Inventory_Overview_PO_Confidence::ESTIMATED,
				'source'     => 'observed',
			);
		}

		$configured = $supplier['default_lead_time_days'] ?? null;
		if ( null !== $configured && '' !== $configured && (int) $configured > 0 ) {
			return array(
				'days'       => (int) $configured,
				'confidence' => WC_Inventory_Overview_PO_Confidence::ESTIMATED,
				'source'     => 'configured',
			);
		}

		return self::empty_suggestion();
	}

	/**
	 * The "no suggestion" result shape -- never authoritative, never a
	 * fabricated 0-day guess (INV-M10-1).
	 *
	 * @return array{days:?int,confidence:?string,source:string}
	 */
	private static function empty_suggestion(): array {
		return array(
			'days'       => null,
			'confidence' => null,
			'source'     => 'none',
		);
	}
}
