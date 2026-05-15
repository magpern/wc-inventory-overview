<?php
/**
 * Exchange rate history (USD/SEK → EUR) for Batch Intake prefills.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * CRUD + lookup: latest rate on or before a date (no interpolation).
 */
class WC_Inventory_Overview_Exchange_Rates {

	public const TO_CURRENCY = 'EUR';

	/**
	 * @return string Table name including prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wc_io_exchange_rates';
	}

	/**
	 * Resolve FX to EUR for a currency and valuation date.
	 *
	 * @param string $currency EUR|USD|SEK.
	 * @param string $date     YYYY-MM-DD.
	 * @return array{rate: float, source: string, rate_date: ?string}|WP_Error
	 */
	public static function get_exchange_rate_to_eur( $currency, $date ) {
		$currency = strtoupper( trim( (string) $currency ) );
		$date     = trim( (string) $date );

		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $currency ) {
			return array(
				'rate'      => 1.0,
				'source'    => 'eur',
				'rate_date' => null,
			);
		}

		if ( ! in_array( $currency, array( WC_Inventory_Overview_Settings::CURRENCY_USD, WC_Inventory_Overview_Settings::CURRENCY_SEK ), true ) ) {
			return new WP_Error( 'wc_io_fx_currency', __( 'Unsupported currency for exchange lookup.', 'wc-inventory-overview' ) );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wc_io_fx_date', __( 'Invalid date for exchange lookup.', 'wc-inventory-overview' ) );
		}
		$dp = explode( '-', $date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			return new WP_Error( 'wc_io_fx_date', __( 'Invalid calendar date for exchange lookup.', 'wc-inventory-overview' ) );
		}

		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT exchange_rate, rate_date FROM {$table}
				WHERE from_currency = %s AND to_currency = %s AND rate_date <= %s
				ORDER BY rate_date DESC, id DESC LIMIT 1",
				$currency,
				self::TO_CURRENCY,
				$date
			),
			ARRAY_A
		);

		if ( $row && isset( $row['exchange_rate'] ) ) {
			$r = (float) wc_format_decimal( (string) $row['exchange_rate'], 8 );
			if ( $r > 0 ) {
				return array(
					'rate'      => $r,
					'source'    => 'history',
					'rate_date' => (string) $row['rate_date'],
				);
			}
		}

		return new WP_Error(
			'wc_io_fx_missing',
			__( 'No historical exchange rate found for this date. Please enter a manual rate.', 'wc-inventory-overview' )
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_rates( int $limit = 500 ): array {
		global $wpdb;
		$table = self::table_name();
		$limit = max( 1, min( 2000, $limit ) );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, from_currency, to_currency, exchange_rate, rate_date, source_note, user_id, created_at
				FROM {$table} ORDER BY rate_date DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * @return true|WP_Error
	 */
	public static function insert_rate( string $from_currency, string $to_currency, string $rate_raw, string $rate_date, string $note ) {
		$from_currency = strtoupper( trim( $from_currency ) );
		$to_currency   = strtoupper( trim( $to_currency ) );

		if ( self::TO_CURRENCY !== $to_currency ) {
			return new WP_Error( 'wc_io_fx_to', __( 'Only EUR is supported as the target currency.', 'wc-inventory-overview' ) );
		}
		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $from_currency ) {
			return new WP_Error( 'wc_io_fx_from', __( 'EUR to EUR rates are not stored.', 'wc-inventory-overview' ) );
		}
		if ( ! in_array( $from_currency, array( WC_Inventory_Overview_Settings::CURRENCY_USD, WC_Inventory_Overview_Settings::CURRENCY_SEK ), true ) ) {
			return new WP_Error( 'wc_io_fx_from', __( 'From currency must be USD or SEK.', 'wc-inventory-overview' ) );
		}

		$rate = (float) wc_format_decimal( $rate_raw, 8 );
		if ( $rate <= 0 ) {
			return new WP_Error( 'wc_io_fx_rate', __( 'Exchange rate must be greater than zero.', 'wc-inventory-overview' ) );
		}

		$rate_date = trim( $rate_date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $rate_date ) ) {
			return new WP_Error( 'wc_io_fx_date', __( 'Rate date must be YYYY-MM-DD.', 'wc-inventory-overview' ) );
		}
		$dp = explode( '-', $rate_date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			return new WP_Error( 'wc_io_fx_date', __( 'Rate date is not a valid calendar date.', 'wc-inventory-overview' ) );
		}

		global $wpdb;
		$ok = $wpdb->insert(
			self::table_name(),
			array(
				'from_currency'  => $from_currency,
				'to_currency'    => $to_currency,
				'exchange_rate'  => wc_format_decimal( $rate, 8 ),
				'rate_date'      => $rate_date,
				'source_note'    => '' !== $note ? $note : null,
				'user_id'        => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ok ) {
			return new WP_Error( 'wc_io_fx_db', __( 'Could not save exchange rate.', 'wc-inventory-overview' ) );
		}

		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function delete_rate( int $id ) {
		if ( $id <= 0 ) {
			return new WP_Error( 'wc_io_fx_id', __( 'Invalid rate id.', 'wc-inventory-overview' ) );
		}

		global $wpdb;
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, from_currency, to_currency FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'wc_io_fx_id', __( 'Exchange rate not found.', 'wc-inventory-overview' ) );
		}
		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $row['from_currency'] && self::TO_CURRENCY === $row['to_currency'] ) {
			return new WP_Error( 'wc_io_fx_del', __( 'EUR to EUR rows must not exist and cannot be deleted this way.', 'wc-inventory-overview' ) );
		}

		$del = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
		if ( ! $del ) {
			return new WP_Error( 'wc_io_fx_db', __( 'Could not delete exchange rate.', 'wc-inventory-overview' ) );
		}

		return true;
	}
}
