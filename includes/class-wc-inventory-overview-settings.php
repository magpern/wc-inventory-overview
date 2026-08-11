<?php
/**
 * Plugin options: defaults, sanitization, and readers for Inventory & Profit hub.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC Inventory Overview settings (stored as individual options).
 */
class WC_Inventory_Overview_Settings {

	public const OPTION_SNAPSHOT_ORDER_STATUS = 'wc_io_snapshot_order_status';

	public const OPTION_INCLUDE_SHIPPING_TAX = 'wc_io_include_shipping_tax';

	public const OPTION_DEFAULT_ACTUAL_SHIPPING = 'wc_io_default_actual_shipping';

	/**
	 * Legacy scalar (amount treated as EUR). Migrated into OPTION_DEFAULT_ACTUAL_SHIPPING on read.
	 *
	 * @deprecated
	 */
	public const OPTION_DEFAULT_ACTUAL_SHIPPING_COST = 'wc_io_default_actual_shipping_cost';

	public const OPTION_ALLOW_ZERO_SUPPLIER_COST = 'wc_io_allow_zero_supplier_cost';

	public const OPTION_AUTO_UPDATE_INVENTORY_VALUE_ON_STOCK_EDIT = 'wc_io_auto_update_inventory_value_on_stock_edit';

	public const OPTION_DEFAULT_LOW_STOCK_THRESHOLD = 'wc_io_default_low_stock_threshold';

	public const OPTION_DEFAULT_REPORTING_RANGE = 'wc_io_default_reporting_range';

	public const OPTION_HIGHLIGHT_NEGATIVE_PROFIT = 'wc_io_highlight_negative_profit';

	public const OPTION_DEFAULT_PURCHASE_CURRENCY = 'wc_io_default_purchase_currency';

	public const OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED = 'wc_io_expected_delivery_renderer_enabled';

	public const CURRENCY_EUR = 'EUR';

	public const CURRENCY_USD = 'USD';

	public const CURRENCY_SEK = 'SEK';

	public const SNAPSHOT_BOTH       = 'both';

	public const SNAPSHOT_PROCESSING = 'processing';

	public const SNAPSHOT_COMPLETED  = 'completed';

	public const RANGE_7D  = '7d';

	public const RANGE_30D = '30d';

	public const RANGE_90D = '90d';

	public const RANGE_YTD = 'ytd';

	public const RANGE_ALL = 'all';

	/**
	 * @return string processing|completed|both
	 */
	public static function get_snapshot_order_status_mode() {
		$v = get_option( self::OPTION_SNAPSHOT_ORDER_STATUS, self::SNAPSHOT_BOTH );
		$v = is_string( $v ) ? sanitize_key( $v ) : self::SNAPSHOT_BOTH;
		if ( in_array( $v, array( self::SNAPSHOT_PROCESSING, self::SNAPSHOT_COMPLETED, self::SNAPSHOT_BOTH ), true ) ) {
			return $v;
		}
		return self::SNAPSHOT_BOTH;
	}

	/**
	 * Bare order status slugs (no wc- prefix) that trigger snapshot writes.
	 *
	 * @return string[]
	 */
	public static function get_snapshot_trigger_statuses() {
		switch ( self::get_snapshot_order_status_mode() ) {
			case self::SNAPSHOT_PROCESSING:
				return array( 'processing' );
			case self::SNAPSHOT_COMPLETED:
				return array( 'completed' );
			default:
				return array( 'processing', 'completed' );
		}
	}

	/**
	 * Whether a transition target should run snapshots.
	 *
	 * @param string $to_status New status (bare slug, e.g. processing).
	 */
	public static function should_snapshot_on_transition_to( $to_status ) {
		$to_status = sanitize_key( (string) $to_status );
		return in_array( $to_status, self::get_snapshot_trigger_statuses(), true );
	}

	/**
	 * Order statuses for SQL (wc-prefixed) matching snapshot profitability aggregates.
	 *
	 * @return string[]
	 */
	public static function get_profitability_sql_order_statuses() {
		$map = array(
			self::SNAPSHOT_PROCESSING => array( 'wc-processing' ),
			self::SNAPSHOT_COMPLETED  => array( 'wc-completed' ),
			self::SNAPSHOT_BOTH       => array( 'wc-processing', 'wc-completed' ),
		);
		$mode = self::get_snapshot_order_status_mode();
		return isset( $map[ $mode ] ) ? $map[ $mode ] : $map[ self::SNAPSHOT_BOTH ];
	}

	/**
	 * @return bool
	 */
	public static function include_shipping_tax() {
		return self::is_yes( get_option( self::OPTION_INCLUDE_SHIPPING_TAX, 'yes' ) );
	}

	/**
	 * Default “actual shipping cost” for Order Profit when an order has no saved meta (EUR only).
	 *
	 * @return float Non-negative.
	 */
	public static function get_default_actual_shipping_cost() {
		$pkg = self::get_default_actual_shipping_package();
		$f   = (float) wc_format_decimal( (string) ( $pkg['converted_eur'] ?? '0' ), 6 );
		return max( 0.0, $f );
	}

	/**
	 * Persisted default actual shipping (entered currency + FX snapshot + EUR used in reporting).
	 *
	 * @return array{entered_amount:string,currency:string,exchange_rate_to_eur:string,exchange_rate_date:string,converted_eur:string}
	 */
	public static function get_default_actual_shipping_package(): array {
		$pkg = get_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING, null );
		if ( is_array( $pkg ) && isset( $pkg['converted_eur'], $pkg['currency'], $pkg['entered_amount'] ) ) {
			return self::normalize_default_actual_shipping_package( $pkg );
		}

		$legacy = get_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING_COST, false );
		if ( false !== $legacy ) {
			$amt = max( 0.0, (float) wc_format_decimal( is_scalar( $legacy ) ? (string) $legacy : '0', 6 ) );
			$today = wp_date( 'Y-m-d', null, wp_timezone() );
			$m     = array(
				'entered_amount'       => wc_format_decimal( (string) $amt, 6 ),
				'currency'             => self::CURRENCY_EUR,
				'exchange_rate_to_eur' => '1',
				'exchange_rate_date'   => $today,
				'converted_eur'        => wc_format_decimal( (string) $amt, 6 ),
			);
			update_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING, $m, false );
			delete_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING_COST );
			return $m;
		}

		return self::default_empty_actual_shipping_package();
	}

	/**
	 * @param array<string, mixed> $pkg Raw option.
	 * @return array{entered_amount:string,currency:string,exchange_rate_to_eur:string,exchange_rate_date:string,converted_eur:string}
	 */
	public static function normalize_default_actual_shipping_package( array $pkg ): array {
		$cur = isset( $pkg['currency'] ) ? strtoupper( trim( (string) $pkg['currency'] ) ) : self::CURRENCY_EUR;
		if ( ! in_array( $cur, self::allowed_purchase_currencies(), true ) ) {
			$cur = self::CURRENCY_EUR;
		}
		$date = isset( $pkg['exchange_rate_date'] ) ? trim( (string) $pkg['exchange_rate_date'] ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = wp_date( 'Y-m-d', null, wp_timezone() );
		}
		$dp = explode( '-', $date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			$date = wp_date( 'Y-m-d', null, wp_timezone() );
		}
		$rate = isset( $pkg['exchange_rate_to_eur'] ) ? (string) $pkg['exchange_rate_to_eur'] : '1';
		$rate = wc_format_decimal( $rate, 8 );
		if ( (float) $rate <= 0 ) {
			$rate = '1';
		}
		$ent = isset( $pkg['entered_amount'] ) ? wc_format_decimal( (string) $pkg['entered_amount'], 6 ) : '0';
		if ( (float) $ent < 0 ) {
			$ent = '0';
		}
		$conv = isset( $pkg['converted_eur'] ) ? wc_format_decimal( (string) $pkg['converted_eur'], 6 ) : '0';
		if ( (float) $conv < 0 ) {
			$conv = '0';
		}
		return array(
			'entered_amount'       => $ent,
			'currency'             => $cur,
			'exchange_rate_to_eur' => self::CURRENCY_EUR === $cur ? '1' : $rate,
			'exchange_rate_date'   => $date,
			'converted_eur'        => $conv,
		);
	}

	/**
	 * @return array{entered_amount:string,currency:string,exchange_rate_to_eur:string,exchange_rate_date:string,converted_eur:string}
	 */
	protected static function default_empty_actual_shipping_package(): array {
		$today = wp_date( 'Y-m-d', null, wp_timezone() );
		return array(
			'entered_amount'       => '0',
			'currency'             => self::CURRENCY_EUR,
			'exchange_rate_to_eur' => '1',
			'exchange_rate_date'   => $today,
			'converted_eur'        => '0',
		);
	}

	/**
	 * Validate POST fields for default actual shipping (same FX rules as Batch Intake).
	 *
	 * @param array<string, mixed> $post Unslashed POST.
	 * @return array<string, string>|WP_Error
	 */
	public static function parse_default_actual_shipping_from_post( array $post ) {
		$u = static function ( $v ) {
			return is_string( $v ) ? wp_unslash( $v ) : (string) $v;
		};

		$amt_raw = isset( $post['wc_io_def_ship_entered_amount'] ) ? $u( $post['wc_io_def_ship_entered_amount'] ) : '';
		$entered = (float) wc_format_decimal( '' !== trim( $amt_raw ) ? $amt_raw : '0', 6 );
		if ( $entered < 0 ) {
			return new WP_Error( 'wc_io_def_ship_amt', __( 'Default actual shipping amount cannot be negative.', 'wc-inventory-overview' ) );
		}

		$cur = isset( $post['wc_io_def_ship_currency'] ) ? strtoupper( sanitize_text_field( $u( $post['wc_io_def_ship_currency'] ) ) ) : self::CURRENCY_EUR;
		if ( ! in_array( $cur, self::allowed_purchase_currencies(), true ) ) {
			return new WP_Error( 'wc_io_def_ship_cur', __( 'Currency must be EUR, USD, or SEK.', 'wc-inventory-overview' ) );
		}

		$date = isset( $post['wc_io_def_ship_exchange_rate_date'] ) ? sanitize_text_field( $u( $post['wc_io_def_ship_exchange_rate_date'] ) ) : '';
		if ( '' === $date ) {
			$date = wp_date( 'Y-m-d', null, wp_timezone() );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wc_io_def_ship_date', __( 'Exchange rate date must be YYYY-MM-DD.', 'wc-inventory-overview' ) );
		}
		$dp = explode( '-', $date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			return new WP_Error( 'wc_io_def_ship_date', __( 'Exchange rate date is not a valid calendar date.', 'wc-inventory-overview' ) );
		}

		$rate_raw = isset( $post['wc_io_def_ship_exchange_rate_to_eur'] ) ? $u( $post['wc_io_def_ship_exchange_rate_to_eur'] ) : '';

		if ( $entered <= 0.0000001 ) {
			return array(
				'entered_amount'       => wc_format_decimal( '0', 6 ),
				'currency'             => $cur,
				'exchange_rate_to_eur' => '1',
				'exchange_rate_date'   => $date,
				'converted_eur'        => wc_format_decimal( '0', 6 ),
			);
		}

		if ( self::CURRENCY_EUR === $cur ) {
			$rate = 1.0;
		} elseif ( '' === trim( $rate_raw ) ) {
			$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $cur, $date );
			if ( is_wp_error( $lk ) ) {
				return $lk;
			}
			$rate = (float) $lk['rate'];
		} else {
			$rate = (float) wc_format_decimal( $rate_raw, 8 );
		}

		if ( self::CURRENCY_EUR === $cur ) {
			$rate = 1.0;
		} elseif ( $rate <= 0 ) {
			return new WP_Error( 'wc_io_def_ship_fx', __( 'Exchange rate to EUR must be greater than zero.', 'wc-inventory-overview' ) );
		}

		$converted = (float) wc_format_decimal( (string) ( $entered * $rate ), 6 );

		return array(
			'entered_amount'       => wc_format_decimal( (string) $entered, 6 ),
			'currency'             => $cur,
			'exchange_rate_to_eur' => wc_format_decimal( (string) $rate, 8 ),
			'exchange_rate_date'   => $date,
			'converted_eur'        => wc_format_decimal( (string) $converted, 6 ),
		);
	}

	/**
	 * @return bool
	 */
	public static function allow_zero_supplier_cost() {
		return self::is_yes( get_option( self::OPTION_ALLOW_ZERO_SUPPLIER_COST, 'yes' ) );
	}

	/**
	 * @return bool
	 */
	public static function auto_update_inventory_value_on_stock_edit() {
		return self::is_yes( get_option( self::OPTION_AUTO_UPDATE_INVENTORY_VALUE_ON_STOCK_EDIT, 'yes' ) );
	}

	/**
	 * Fallback low-stock threshold when the product has no explicit low-stock amount.
	 *
	 * @return int
	 */
	public static function get_default_low_stock_threshold() {
		$v = get_option( self::OPTION_DEFAULT_LOW_STOCK_THRESHOLD, 3 );
		if ( is_string( $v ) && is_numeric( $v ) ) {
			$v = (int) $v;
		}
		if ( ! is_int( $v ) && ! is_float( $v ) ) {
			$v = 3;
		}
		return max( 0, (int) $v );
	}

	/**
	 * Low-stock amount for plugin UI: explicit Woo value when set, else plugin default.
	 *
	 * @param WC_Product $product Product.
	 * @return float
	 */
	public static function get_effective_low_stock_amount( WC_Product $product ) {
		$raw = $product->get_low_stock_amount( 'edit' );
		if ( '' !== $raw && null !== $raw ) {
			return (float) wc_stock_amount( $raw );
		}
		$n = self::get_default_low_stock_threshold();
		return (float) $n;
	}

	/**
	 * @return string 7d|30d|90d|ytd|all
	 */
	public static function get_default_reporting_range() {
		$v = get_option( self::OPTION_DEFAULT_REPORTING_RANGE, self::RANGE_30D );
		$v = is_string( $v ) ? sanitize_key( $v ) : self::RANGE_30D;
		$allowed = array( self::RANGE_7D, self::RANGE_30D, self::RANGE_90D, self::RANGE_YTD, self::RANGE_ALL );
		return in_array( $v, $allowed, true ) ? $v : self::RANGE_30D;
	}

	/**
	 * Default local calendar bounds when the user has not set dates (Y-m-d).
	 *
	 * @return array{date_from:string,date_to:string}
	 */
	public static function get_default_report_date_bounds_local() {
		$range = self::get_default_reporting_range();
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$end   = $today->format( 'Y-m-d' );

		if ( self::RANGE_ALL === $range ) {
			return array(
				'date_from' => '',
				'date_to'   => '',
			);
		}

		if ( self::RANGE_YTD === $range ) {
			$start = $today->setDate( (int) $today->format( 'Y' ), 1, 1 )->format( 'Y-m-d' );
			return array(
				'date_from' => $start,
				'date_to'   => $end,
			);
		}

		$days = 30;
		if ( self::RANGE_7D === $range ) {
			$days = 7;
		} elseif ( self::RANGE_90D === $range ) {
			$days = 90;
		}

		$start_dt = $today->modify( '-' . ( $days - 1 ) . ' days' );
		return array(
			'date_from' => $start_dt->format( 'Y-m-d' ),
			'date_to'   => $end,
		);
	}

	/**
	 * @return bool
	 */
	public static function highlight_negative_profit() {
		return self::is_yes( get_option( self::OPTION_HIGHLIGHT_NEGATIVE_PROFIT, 'yes' ) );
	}

	/**
	 * Whether the built-in storefront renderer replaces WooCommerce's
	 * "Out of stock" text with expected-delivery wording (M7). Default
	 * 'yes': a feature that ships off by default ships untested in
	 * production.
	 *
	 * @return bool
	 */
	public static function expected_delivery_renderer_enabled() {
		return self::is_yes( get_option( self::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED, 'yes' ) );
	}

	/**
	 * PO delay grace-days (M16, BR-M16-4/BR-M16-5). Reads the existing
	 * WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS option directly --
	 * this is a UI/persistence surface for that option, not a duplicate
	 * of it.
	 *
	 * @return int 0-365
	 */
	public static function get_po_delay_grace_days() {
		return WC_Inventory_Overview_PO_Delay::grace_days_from_option();
	}

	/**
	 * @return string[]
	 */
	public static function allowed_purchase_currencies() {
		return array( self::CURRENCY_EUR, self::CURRENCY_USD, self::CURRENCY_SEK );
	}

	/**
	 * @return string EUR|USD|SEK
	 */
	public static function get_default_purchase_currency() {
		$v = get_option( self::OPTION_DEFAULT_PURCHASE_CURRENCY, self::CURRENCY_EUR );
		$v = is_string( $v ) ? strtoupper( trim( $v ) ) : self::CURRENCY_EUR;
		return in_array( $v, self::allowed_purchase_currencies(), true ) ? $v : self::CURRENCY_EUR;
	}

	/**
	 * @param mixed $v Stored value.
	 */
	protected static function is_yes( $v ) {
		if ( true === $v || 1 === $v || '1' === $v ) {
			return true;
		}
		return 'yes' === strtolower( (string) $v );
	}

	/**
	 * Persist all settings from POST (already capability-checked).
	 *
	 * @param array<string, mixed> $post Sanitized $_POST slice.
	 * @return true|WP_Error
	 */
	public static function save_from_post( array $post ) {
		$ship_pkg = self::parse_default_actual_shipping_from_post( $post );
		if ( is_wp_error( $ship_pkg ) ) {
			return $ship_pkg;
		}

		$mode = isset( $post['wc_io_snapshot_order_status'] ) ? sanitize_key( (string) $post['wc_io_snapshot_order_status'] ) : self::SNAPSHOT_BOTH;
		if ( ! in_array( $mode, array( self::SNAPSHOT_PROCESSING, self::SNAPSHOT_COMPLETED, self::SNAPSHOT_BOTH ), true ) ) {
			$mode = self::SNAPSHOT_BOTH;
		}
		update_option( self::OPTION_SNAPSHOT_ORDER_STATUS, $mode, false );

		update_option( self::OPTION_INCLUDE_SHIPPING_TAX, self::normalize_yes_no( $post, 'wc_io_include_shipping_tax' ), false );
		update_option( self::OPTION_ALLOW_ZERO_SUPPLIER_COST, self::normalize_yes_no( $post, 'wc_io_allow_zero_supplier_cost' ), false );
		update_option( self::OPTION_AUTO_UPDATE_INVENTORY_VALUE_ON_STOCK_EDIT, self::normalize_yes_no( $post, 'wc_io_auto_update_inventory_value_on_stock_edit' ), false );
		update_option( self::OPTION_HIGHLIGHT_NEGATIVE_PROFIT, self::normalize_yes_no( $post, 'wc_io_highlight_negative_profit' ), false );

		update_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING, $ship_pkg, false );
		delete_option( self::OPTION_DEFAULT_ACTUAL_SHIPPING_COST );

		$low = isset( $post['wc_io_default_low_stock_threshold'] ) ? absint( $post['wc_io_default_low_stock_threshold'] ) : 3;
		update_option( self::OPTION_DEFAULT_LOW_STOCK_THRESHOLD, $low, false );

		$range = isset( $post['wc_io_default_reporting_range'] ) ? sanitize_key( (string) $post['wc_io_default_reporting_range'] ) : self::RANGE_30D;
		$allowed = array( self::RANGE_7D, self::RANGE_30D, self::RANGE_90D, self::RANGE_YTD, self::RANGE_ALL );
		if ( ! in_array( $range, $allowed, true ) ) {
			$range = self::RANGE_30D;
		}
		update_option( self::OPTION_DEFAULT_REPORTING_RANGE, $range, false );

		$cur = isset( $post['wc_io_default_purchase_currency'] ) ? strtoupper( sanitize_text_field( (string) $post['wc_io_default_purchase_currency'] ) ) : self::CURRENCY_EUR;
		if ( ! in_array( $cur, self::allowed_purchase_currencies(), true ) ) {
			$cur = self::CURRENCY_EUR;
		}
		update_option( self::OPTION_DEFAULT_PURCHASE_CURRENCY, $cur, false );

		update_option( self::OPTION_EXPECTED_DELIVERY_RENDERER_ENABLED, self::normalize_yes_no( $post, 'wc_io_expected_delivery_renderer_enabled' ), false );

		self::maybe_save_po_delay_grace_days( $post );

		return true;
	}

	/**
	 * M16 (BR-M16-4): explicit validate-or-preserve contract for
	 * WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS. Deliberately not
	 * absint()-style coercion (which would silently turn e.g. -5 into 5) --
	 * any invalid or missing submission leaves the previously stored value
	 * completely untouched (no update_option() call at all), never
	 * clamped/coerced into range. 0 is a valid, saveable value.
	 *
	 * @param array<string, mixed> $post POST data.
	 */
	private static function maybe_save_po_delay_grace_days( array $post ) {
		if ( ! isset( $post['wc_io_po_delay_grace_days'] ) ) {
			return;
		}

		$raw = $post['wc_io_po_delay_grace_days'];

		if ( is_array( $raw ) || ! is_numeric( $raw ) ) {
			return;
		}

		// Reject non-clean integers (decimals, scientific notation, stray
		// characters) -- a value only round-trips through (int) casting
		// without loss if it was already a clean integer string.
		if ( (string) (int) $raw !== trim( (string) $raw ) ) {
			return;
		}

		$value = (int) $raw;

		if ( $value < 0 || $value > 365 ) {
			return;
		}

		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, $value, false );
	}

	/**
	 * @param array<string, mixed> $post POST data.
	 * @param string               $key  Field name.
	 */
	protected static function normalize_yes_no( array $post, $key ) {
		$v = isset( $post[ $key ] ) ? sanitize_text_field( (string) $post[ $key ] ) : 'no';
		return 'yes' === strtolower( $v ) ? 'yes' : 'no';
	}
}
