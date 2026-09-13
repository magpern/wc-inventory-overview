<?php
/**
 * Stock Adjustment number allocation (M27).
 *
 * Format: SA-{YYYY}-{NNNNN} (zero-padded to at least 5 digits).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Isolated Stock Adjustment numbering service.
 */
class WC_Inventory_Overview_Stock_Adjustment_Numbering {

	const OPTION_KEY  = 'wc_io_sa_number_sequence';
	const MAX_RETRIES = 3;

	/**
	 * Allocate the next Stock Adjustment number for a year.
	 *
	 * @param int|null $year Four-digit year; defaults to current site year.
	 * @return string|WP_Error
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

			$number = apply_filters( 'wc_io_sa_number', $number, $year, $seq );

			if ( ! is_string( $number ) || '' === $number ) {
				return new WP_Error( 'wc_io_sa_number_invalid', 'Filtered Stock Adjustment number is empty' );
			}

			if ( ! self::exists( $number ) ) {
				return $number;
			}
		}

		return new WP_Error(
			'wc_io_sa_number_exhausted',
			sprintf( 'Unable to allocate a unique Stock Adjustment number after %d attempts', self::MAX_RETRIES )
		);
	}

	/**
	 * @param int $year Year.
	 * @param int $seq  Sequence (1-based).
	 */
	public static function format( int $year, int $seq ): string {
		$width = max( 5, strlen( (string) $seq ) );
		return sprintf( 'SA-%d-%0' . $width . 'd', $year, $seq );
	}

	/**
	 * @param string $adjustment_number Number.
	 */
	public static function exists( string $adjustment_number ): bool {
		global $wpdb;
		$table = WC_Inventory_Overview_Stock_Adjustments::table_name();
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE adjustment_number = %s LIMIT 1", $adjustment_number ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return null !== $found && false !== $found;
	}

	/**
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
	 * @param int|null $year Year.
	 * @return int|WP_Error
	 */
	private static function normalize_year( $year ) {
		if ( null === $year ) {
			$year = (int) gmdate( 'Y', time() );
		}
		$year = (int) $year;
		if ( $year < 2000 || $year > 2100 ) {
			return new WP_Error( 'wc_io_sa_number_year', 'Stock Adjustment number year out of range' );
		}
		return $year;
	}
}
