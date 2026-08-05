<?php
/**
 * Purchase Order number allocation (M2).
 *
 * Format: PO-{YYYY}-{NNNN} (zero-padded to at least 4 digits; grows past 9999 naturally).
 * Numbers are allocated once and never reused. Sequence gaps are expected.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Isolated PO numbering service.
 */
class WC_Inventory_Overview_PO_Numbering {

	const OPTION_KEY  = 'wc_io_po_number_sequence';
	const MAX_RETRIES = 3;

	/**
	 * Allocate the next PO number for a year.
	 *
	 * Advances the per-year sequence permanently. Failed downstream inserts must
	 * not roll the counter back (never-reuse invariant).
	 *
	 * @param int|null $year Four-digit year; defaults to current site year.
	 * @return string|WP_Error Allocated number or error after exhausted retries.
	 */
	public static function allocate( $year = null ) {
		$year = self::normalize_year( $year );
		if ( is_wp_error( $year ) ) {
			return $year;
		}

		$attempts = 0;
		while ( $attempts < self::MAX_RETRIES ) {
			++$attempts;
			$seq    = self::next_sequence_value( $year );
			$number = self::format( $year, $seq );

			/**
			 * Filter the generated PO number before uniqueness check.
			 *
			 * @param string $number Formatted PO number.
			 * @param int    $year   Year component.
			 * @param int    $seq    Sequence component.
			 */
			$number = apply_filters( 'wc_io_po_number', $number, $year, $seq );

			if ( ! is_string( $number ) || '' === $number ) {
				return new WP_Error( 'wc_io_po_number_invalid', 'Filtered PO number is empty' );
			}

			if ( ! self::exists( $number ) ) {
				return $number;
			}
			// Collision (filter override or rare race): advance and retry.
			// Sequence already advanced — gap is intentional under never-reuse.
		}

		return new WP_Error(
			'wc_io_po_number_exhausted',
			sprintf( 'Unable to allocate a unique PO number after %d attempts', self::MAX_RETRIES )
		);
	}

	/**
	 * Format a PO number. Padding is at least 4 digits; larger sequences grow naturally.
	 *
	 * @param int $year Year.
	 * @param int $seq  Sequence (1-based).
	 */
	public static function format( int $year, int $seq ): string {
		$width = max( 4, strlen( (string) $seq ) );
		return sprintf( 'PO-%d-%0' . $width . 'd', $year, $seq );
	}

	/**
	 * Peek at the next sequence value without allocating (for tests/diagnostics).
	 *
	 * @param int|null $year Year.
	 * @return int|WP_Error
	 */
	public static function peek_next_sequence( $year = null ) {
		$year = self::normalize_year( $year );
		if ( is_wp_error( $year ) ) {
			return $year;
		}
		$map     = self::get_sequence_map();
		$current = isset( $map[ (string) $year ] ) ? (int) $map[ (string) $year ] : 0;
		return $current + 1;
	}

	/**
	 * Whether a PO number already exists in the header table.
	 *
	 * @param string $po_number PO number.
	 */
	public static function exists( string $po_number ): bool {
		global $wpdb;
		$table = WC_Inventory_Overview_Purchase_Orders::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE po_number = %s LIMIT 1", $po_number ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null !== $found && false !== $found;
	}

	/**
	 * Advance and persist the per-year sequence. Never decrements.
	 *
	 * @param int $year Year.
	 */
	private static function next_sequence_value( int $year ): int {
		$map         = self::get_sequence_map();
		$key         = (string) $year;
		$current     = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
		$next        = $current + 1;
		$map[ $key ] = $next;
		update_option( self::OPTION_KEY, $map, false );
		return $next;
	}

	/**
	 * Load the year => last-sequence map from options.
	 *
	 * @return array<string,int>
	 */
	private static function get_sequence_map(): array {
		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $y => $n ) {
			$out[ (string) $y ] = absint( $n );
		}
		return $out;
	}

	/**
	 * Normalize and validate a year argument.
	 *
	 * @param int|null $year Year.
	 * @return int|WP_Error
	 */
	private static function normalize_year( $year ) {
		if ( null === $year ) {
			$year = (int) gmdate( 'Y', time() );
		}
		$year = (int) $year;
		if ( $year < 2000 || $year > 2100 ) {
			return new WP_Error( 'wc_io_po_number_year', 'PO number year out of range' );
		}
		return $year;
	}
}
