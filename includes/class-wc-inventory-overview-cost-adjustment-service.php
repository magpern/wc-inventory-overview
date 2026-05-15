<?php
/**
 * Admin cost adjustment: set average unit cost without changing stock.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Correct average unit cost and derived inventory value only.
 */
class WC_Inventory_Overview_Cost_Adjustment_Service {

	/**
	 * Apply a new average unit cost (stock unchanged).
	 *
	 * @param int    $line_id  Variation or simple product ID.
	 * @param float  $new_avg  New average unit cost (>= 0).
	 * @param string $note     Optional note.
	 * @return true|WP_Error
	 */
	public static function process( $line_id, $new_avg, $note = '' ) {
		$line_id = absint( $line_id );
		if ( ! $line_id ) {
			return new WP_Error( 'wc_io_invalid', __( 'Invalid product.', 'wc-inventory-overview' ) );
		}

		if ( $new_avg < 0 ) {
			return new WP_Error( 'wc_io_cost', __( 'Average unit cost cannot be negative.', 'wc-inventory-overview' ) );
		}

		$product = wc_get_product( $line_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'wc_io_product', __( 'Product not found.', 'wc-inventory-overview' ) );
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return new WP_Error( 'wc_io_type', __( 'Select a variation or a simple product, not a parent variable product.', 'wc-inventory-overview' ) );
		}

		if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
			return new WP_Error( 'wc_io_type', __( 'Unsupported product type for costing.', 'wc-inventory-overview' ) );
		}

		if ( ! $product->managing_stock() ) {
			return new WP_Error( 'wc_io_stock', __( 'Enable stock management for this product before adjusting cost.', 'wc-inventory-overview' ) );
		}

		$stock_qty = $product->get_stock_quantity();
		if ( null === $stock_qty || '' === $stock_qty ) {
			return new WP_Error( 'wc_io_stock', __( 'Stock quantity must be set to adjust inventory value.', 'wc-inventory-overview' ) );
		}

		$stock = (float) wc_stock_amount( $stock_qty );

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

		$old_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$old_avg_f   = WC_Inventory_Overview_Costing::get_average_float( $product );

		$old_val_raw = $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
		$old_inv     = ( '' === $old_val_raw || null === $old_val_raw )
			? ( ( null === $old_avg_f ) ? 0.0 : (float) wc_format_decimal( $stock * $old_avg_f, 4 ) )
			: (float) wc_format_decimal( $old_val_raw, 4 );

		$new_avg_f = (float) wc_format_decimal( $new_avg, 6 );
		$new_inv   = (float) wc_format_decimal( $stock * $new_avg_f, 4 );

		$snapshot = array(
			'avg_meta' => $old_avg_raw,
			'val_meta' => ( '' === $old_val_raw || null === $old_val_raw ) ? null : (string) $old_val_raw,
		);

		$product->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, wc_format_decimal( $new_avg_f, 6 ) );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, wc_format_decimal( $new_inv, 4 ) );

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'wc_io_save', $e->getMessage() );
		}

		$movement_ok = WC_Inventory_Overview_Movements::insert_cost_adjustment(
			array(
				'product_id'            => $parent_id,
				'variation_id'          => $variation_id,
				'old_stock'             => $stock,
				'new_stock'             => $stock,
				'old_average_unit_cost' => $old_avg_raw,
				'new_average_unit_cost' => $new_avg_f,
				'old_inventory_value'   => $old_inv,
				'new_inventory_value'   => $new_inv,
				'note'                  => $note,
			)
		);

		if ( ! $movement_ok ) {
			self::restore_meta_snapshot( $line_id, $snapshot );
			global $wpdb;
			return new WP_Error(
				'wc_io_movement',
				sprintf(
					/* translators: %s: database error */
					__( 'Could not write movement log. %s', 'wc-inventory-overview' ),
					$wpdb->last_error ? $wpdb->last_error : __( 'Unknown database error.', 'wc-inventory-overview' )
				)
			);
		}

		wc_delete_product_transients( $line_id );
		if ( $variation_id > 0 ) {
			wc_delete_product_transients( $parent_id );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		return true;
	}

	/**
	 * @param int                  $line_id  Product ID.
	 * @param array<string, mixed> $snapshot avg_meta (null|string), val_meta (null|string).
	 */
	protected static function restore_meta_snapshot( $line_id, array $snapshot ) {
		$p = wc_get_product( $line_id );
		if ( ! $p ) {
			return;
		}
		if ( null === $snapshot['avg_meta'] || '' === $snapshot['avg_meta'] ) {
			$p->delete_meta_data( WC_Inventory_Overview_Costing::META_AVG );
		} else {
			$p->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, $snapshot['avg_meta'] );
		}
		if ( null === $snapshot['val_meta'] || '' === $snapshot['val_meta'] ) {
			$p->delete_meta_data( WC_Inventory_Overview_Costing::META_VAL );
		} else {
			$p->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, $snapshot['val_meta'] );
		}
		$p->save();
	}
}
