<?php
/**
 * Expected-receipt confidence vocabulary (M2-B).
 *
 * Exact / Estimated / Unknown per CLAUDE.md D9 / §7.2.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO expected-confidence constants.
 */
class WC_Inventory_Overview_PO_Confidence {

	const EXACT     = 'exact';
	const ESTIMATED = 'estimated';
	const UNKNOWN   = 'unknown';

	/**
	 * All valid confidence values.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::EXACT,
			self::ESTIMATED,
			self::UNKNOWN,
		);
	}

	/**
	 * Whether a confidence value is valid.
	 *
	 * @param string $confidence Confidence.
	 */
	public static function is_valid( string $confidence ): bool {
		return in_array( $confidence, self::all(), true );
	}

	/**
	 * Validate confidence. Empty/null is invalid for headers (use unknown explicitly);
	 * for optional line overrides, empty means inherit (caller handles).
	 *
	 * @param string $confidence Confidence.
	 * @return true|WP_Error
	 */
	public static function validate( string $confidence ) {
		if ( ! self::is_valid( $confidence ) ) {
			return new WP_Error(
				'wc_io_po_invalid_confidence',
				sprintf( 'Unknown expected_confidence: %s', $confidence )
			);
		}
		return true;
	}
}
