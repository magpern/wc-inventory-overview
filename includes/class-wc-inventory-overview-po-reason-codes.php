<?php
/**
 * Purchase Order event reason codes (M2).
 *
 * Optional structured classification for future reporting. Not rendered in the timeline.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closed vocabulary of PO event reason codes.
 */
class WC_Inventory_Overview_PO_Reason_Codes {

	const SUPPLIER_CHANGE = 'supplier_change';
	const PRICE_CHANGE    = 'price_change';
	const QUANTITY_CHANGE = 'quantity_change';
	const SCHEDULE_CHANGE = 'schedule_change';
	const MANUAL          = 'manual';
	const OTHER           = 'other';

	/**
	 * All valid reason codes.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::SUPPLIER_CHANGE,
			self::PRICE_CHANGE,
			self::QUANTITY_CHANGE,
			self::SCHEDULE_CHANGE,
			self::MANUAL,
			self::OTHER,
		);
	}

	/**
	 * Validate a reason code. NULL/empty is allowed (no classification).
	 *
	 * @param string|null $code Reason code or null.
	 * @return true|WP_Error
	 */
	public static function validate( $code ) {
		if ( null === $code || '' === $code ) {
			return true;
		}
		if ( ! is_string( $code ) || ! in_array( $code, self::all(), true ) ) {
			return new WP_Error(
				'wc_io_po_invalid_reason_code',
				sprintf( 'Invalid PO event reason_code: %s', is_scalar( $code ) ? (string) $code : gettype( $code ) )
			);
		}
		return true;
	}
}
