<?php
/**
 * Cost meta helpers and display formatting.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Variation/simple costing meta (_wc_io_average_unit_cost, _wc_io_inventory_value).
 */
class WC_Inventory_Overview_Costing {

	public const META_AVG  = '_wc_io_average_unit_cost';
	public const META_VAL  = '_wc_io_inventory_value';

	/**
	 * Raw stored average (edit context) or null if unset.
	 *
	 * @param WC_Product $product Product.
	 * @return string|null
	 */
	public static function get_average_raw( WC_Product $product ) {
		$v = $product->get_meta( self::META_AVG, true );
		if ( '' === $v || null === $v ) {
			return null;
		}

		return (string) $v;
	}

	/**
	 * Parsed average as float or null.
	 *
	 * @param WC_Product $product Product.
	 * @return float|null
	 */
	public static function get_average_float( WC_Product $product ) {
		$raw = self::get_average_raw( $product );
		if ( null === $raw ) {
			return null;
		}

		return (float) wc_format_decimal( $raw, 6 );
	}

	/**
	 * After inline stock edit: refresh inventory value only (never change average).
	 *
	 * @param WC_Product $product Variation or simple.
	 */
	public static function maybe_sync_inventory_value_after_stock_change( WC_Product $product ) {
		if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
			return;
		}

		$avg = self::get_average_float( $product );
		if ( null === $avg ) {
			return;
		}

		$qty = $product->get_stock_quantity();
		if ( null === $qty || '' === $qty ) {
			return;
		}

		$new_val = (float) wc_format_decimal( (float) $qty * $avg, 4 );
		$product->update_meta_data( self::META_VAL, wc_format_decimal( $new_val, 4 ) );
	}

	/**
	 * Sum `_wc_io_inventory_value` across simple products and variations (published-like, not trash).
	 *
	 * @return float
	 */
	public static function get_total_inventory_value_meta_sum() {
		global $wpdb;

		$key = self::META_VAL;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are fixed.
		$sql = $wpdb->prepare(
			"SELECT SUM(CAST(pm.meta_value AS DECIMAL(24,8)))
			FROM {$wpdb->postmeta} AS pm
			INNER JOIN {$wpdb->posts} AS p ON p.ID = pm.post_id
			WHERE pm.meta_key = %s
			AND p.post_type IN ( 'product', 'product_variation' )
			AND p.post_status NOT IN ( 'trash', 'auto-draft' )
			AND pm.meta_value IS NOT NULL
			AND TRIM(pm.meta_value) <> ''",
			$key
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$raw = $wpdb->get_var( $sql );
		if ( null === $raw || '' === $raw ) {
			return 0.0;
		}

		return (float) wc_format_decimal( (string) $raw, 4 );
	}

	/**
	 * @param float|null $avg Average or null.
	 * @return string HTML-safe display.
	 */
	public static function format_average_display( $avg ) {
		if ( null === $avg ) {
			return '—';
		}

		return esc_html( wc_format_decimal( $avg, 4 ) );
	}

	/**
	 * @param float|string|null $value Inventory value.
	 * @return string HTML (wc_price).
	 */
	public static function format_value_display( $value ) {
		if ( null === $value || '' === $value ) {
			return '<span class="wc-io-muted">—</span>';
		}

		return wp_kses_post( wc_price( wc_format_decimal( $value, 4 ) ) );
	}
}
