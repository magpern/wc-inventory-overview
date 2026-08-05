<?php
/**
 * Expected date and confidence inheritance (M2-B).
 *
 * Line values override header when set; otherwise inherit.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Effective expected-date / confidence resolver.
 */
class WC_Inventory_Overview_PO_Expected {

	/**
	 * Effective expected date for a line.
	 *
	 * @param string|null $line_date   Line override (Y-m-d) or null/empty.
	 * @param string|null $header_date Header date (Y-m-d) or null/empty.
	 * @return string|null Y-m-d or null when neither is set.
	 */
	public static function effective_date( $line_date, $header_date ) {
		$line = self::normalize_date( $line_date );
		if ( null !== $line ) {
			return $line;
		}
		return self::normalize_date( $header_date );
	}

	/**
	 * Effective confidence for a line.
	 *
	 * @param string|null $line_confidence   Line override or null/empty to inherit.
	 * @param string|null $header_confidence Header confidence (defaults to unknown).
	 */
	public static function effective_confidence( $line_confidence, $header_confidence ): string {
		$line = is_string( $line_confidence ) ? trim( $line_confidence ) : '';
		if ( '' !== $line ) {
			return $line;
		}
		$header = is_string( $header_confidence ) ? trim( $header_confidence ) : '';
		return '' !== $header ? $header : WC_Inventory_Overview_PO_Confidence::UNKNOWN;
	}

	/**
	 * Normalize a date string to Y-m-d or null.
	 *
	 * @param mixed $value Raw date.
	 * @return string|null
	 */
	private static function normalize_date( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value || '0000-00-00' === $value ) {
			return null;
		}
		return $value;
	}
}
