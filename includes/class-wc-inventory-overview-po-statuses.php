<?php
/**
 * Purchase Order status vocabulary (M2, extended M5).
 *
 * Six states. `partially_received`/`received` are auto-transitioned only —
 * reachable exclusively through WC_Inventory_Overview_PO_Receiving_Sync, never
 * operator-selected (M5 plan §Receiving-status ownership).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO status constants and helpers.
 */
class WC_Inventory_Overview_PO_Statuses {

	const DRAFT              = 'draft';
	const PLACED             = 'placed';
	const PARTIALLY_RECEIVED = 'partially_received';
	const RECEIVED           = 'received';
	const CANCELLED          = 'cancelled';
	const CLOSED_SHORT       = 'closed_short';

	/**
	 * Complete status vocabulary.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::PLACED,
			self::PARTIALLY_RECEIVED,
			self::RECEIVED,
			self::CANCELLED,
			self::CLOSED_SHORT,
		);
	}

	/**
	 * Terminal (absorbing) statuses for operator actions: no outgoing manual
	 * transitions, no further edits. `received` is deliberately NOT terminal —
	 * a void can still walk it back down to `partially_received`/`placed`
	 * (M5 plan §Receiving-status ownership).
	 *
	 * @return array<int,string>
	 */
	public static function terminal(): array {
		return array(
			self::CANCELLED,
			self::CLOSED_SHORT,
		);
	}

	/**
	 * Recompute the header status purely from current receiving totals.
	 *
	 * Direction-agnostic (current-state-relative, not "was this a post or a
	 * void") — the same design principle M4 used for void correctness,
	 * applied here to status (M5 plan §Receiving-status ownership). Statuses
	 * outside {placed, partially_received, received} are never touched —
	 * draft/cancelled/closed_short pass through unchanged; receiving against
	 * those is rejected earlier, before this function is ever called.
	 *
	 * @param string $current_status   PO's current stored status.
	 * @param float  $total_outstanding Sum of GREATEST(0, ordered-received-cancelled) across all lines.
	 * @param float  $total_received    Sum of qty_received across all lines.
	 */
	public static function recompute_for_receiving( string $current_status, float $total_outstanding, float $total_received ): string {
		if ( ! in_array( $current_status, array( self::PLACED, self::PARTIALLY_RECEIVED, self::RECEIVED ), true ) ) {
			return $current_status;
		}
		if ( round( $total_outstanding, 4 ) <= 0.0 ) {
			return self::RECEIVED;
		}
		if ( round( $total_received, 4 ) > 0.0 ) {
			return self::PARTIALLY_RECEIVED;
		}
		return self::PLACED;
	}

	/**
	 * Whether a status is in the M2 vocabulary.
	 *
	 * @param string $status Status string.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Whether a status is terminal.
	 *
	 * @param string $status Status string.
	 */
	public static function is_terminal( string $status ): bool {
		return in_array( $status, self::terminal(), true );
	}

	/**
	 * Human-readable labels for admin UI.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::DRAFT              => __( 'Draft', 'wc-inventory-overview' ),
			self::PLACED             => __( 'Placed', 'wc-inventory-overview' ),
			self::PARTIALLY_RECEIVED => __( 'Partially Received', 'wc-inventory-overview' ),
			self::RECEIVED           => __( 'Received', 'wc-inventory-overview' ),
			self::CANCELLED          => __( 'Cancelled', 'wc-inventory-overview' ),
			self::CLOSED_SHORT       => __( 'Closed Short', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Label for a status key.
	 *
	 * @param string $status Status.
	 */
	public static function label( string $status ): string {
		$labels = self::labels();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
