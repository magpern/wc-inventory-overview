<?php
/**
 * Purchase Order status vocabulary (M2).
 *
 * Four states only. Receiving states (partially_received, received) arrive in M5.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO status constants and helpers.
 */
class WC_Inventory_Overview_PO_Statuses {

	const DRAFT        = 'draft';
	const PLACED       = 'placed';
	const CANCELLED    = 'cancelled';
	const CLOSED_SHORT = 'closed_short';

	/**
	 * Complete M2 status vocabulary. Exactly four values.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::DRAFT,
			self::PLACED,
			self::CANCELLED,
			self::CLOSED_SHORT,
		);
	}

	/**
	 * Terminal (absorbing) statuses: no outgoing transitions, no further edits.
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
}
