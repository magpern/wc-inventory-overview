<?php
/**
 * Purchase restock: weighted average cost + stock + movement log.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restock orchestration (variation-first; simple optional).
 */
class WC_Inventory_Overview_Restock_Service {

	/**
	 * Apply stock + cost meta for a purchase line (no movement row). Used by single restock and batch apply.
	 *
	 * @param int   $line_id           Variation or simple product ID.
	 * @param float $qty_added         Units received (> 0).
	 * @param float $supplier_unit_cost Cost per unit (true unit cost for batches).
	 * @return array<string, mixed>|WP_Error Keys: line_id, snapshot, movement (partial row for insert_*).
	 */
	public static function apply_purchase_line_change( $line_id, $qty_added, $supplier_unit_cost ) {
		$line_id = absint( $line_id );
		if ( ! $line_id ) {
			return new WP_Error( 'wc_io_invalid', __( 'Invalid product.', 'wc-inventory-overview' ) );
		}

		if ( $qty_added <= 0 ) {
			return new WP_Error( 'wc_io_qty', __( 'Quantity added must be greater than zero.', 'wc-inventory-overview' ) );
		}

		if ( $supplier_unit_cost < 0 ) {
			return new WP_Error( 'wc_io_cost', __( 'Supplier unit cost cannot be negative.', 'wc-inventory-overview' ) );
		}

		if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && (float) $supplier_unit_cost <= 0.0 ) {
			return new WP_Error( 'wc_io_cost', __( 'Supplier unit cost must be greater than zero.', 'wc-inventory-overview' ) );
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
			return new WP_Error( 'wc_io_stock', __( 'Enable stock management for this product before restocking.', 'wc-inventory-overview' ) );
		}

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$movement_pid = $parent_id;
		$movement_vid = $variation_id;

		$old_stock = $product->get_stock_quantity();
		$old_stock = ( null === $old_stock || '' === $old_stock ) ? 0.0 : (float) wc_stock_amount( $old_stock );

		$old_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$old_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );

		$old_inventory_value = ( null === $old_avg )
			? 0.0
			: (float) wc_format_decimal( $old_stock * $old_avg, 4 );

		$added_stock = (float) wc_stock_amount( $qty_added );
		$unit_cost   = (float) wc_format_decimal( $supplier_unit_cost, 6 );
		$added_value = (float) wc_format_decimal( $added_stock * $unit_cost, 4 );

		$new_stock = (float) wc_format_decimal( $old_stock + $added_stock, 4 );
		if ( $new_stock <= 0 ) {
			return new WP_Error( 'wc_io_stock', __( 'Invalid resulting stock quantity.', 'wc-inventory-overview' ) );
		}

		$new_inventory_value = (float) wc_format_decimal( $old_inventory_value + $added_value, 4 );
		$new_average         = (float) wc_format_decimal( $new_inventory_value / $new_stock, 6 );

		$snapshot = array(
			'stock_quantity' => $old_stock,
			'avg_meta'       => $old_avg_raw,
			'val_meta'       => $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true ),
		);

		$product->set_stock_quantity( $new_stock );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, wc_format_decimal( $new_average, 6 ) );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, wc_format_decimal( $new_inventory_value, 4 ) );

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'wc_io_save', $e->getMessage() );
		}

		return array(
			'line_id'  => $line_id,
			'snapshot' => $snapshot,
			'movement' => array(
				'product_id'            => $movement_pid,
				'variation_id'          => $movement_vid,
				'quantity_change'       => $added_stock,
				'unit_cost'             => $unit_cost,
				'total_value'           => $added_value,
				'old_stock'             => $old_stock,
				'new_stock'             => $new_stock,
				'old_average_unit_cost' => $old_avg_raw,
				'new_average_unit_cost' => $new_average,
				'old_inventory_value'   => $old_inventory_value,
				'new_inventory_value'   => $new_inventory_value,
			),
		);
	}

	/**
	 * Process a purchase restock for a variation or simple product.
	 *
	 * @param int    $line_id           Variation ID or simple product ID.
	 * @param float  $qty_added         Units received (> 0).
	 * @param float  $supplier_unit_cost Cost per unit (>= 0).
	 * @param string $supplier_name     Optional.
	 * @param string $note              Optional.
	 * @return true|WP_Error
	 */
	public static function process_purchase_restock( $line_id, $qty_added, $supplier_unit_cost, $supplier_name = '', $note = '' ) {
		$applied = self::apply_purchase_line_change( $line_id, $qty_added, $supplier_unit_cost );
		if ( is_wp_error( $applied ) ) {
			return $applied;
		}

		$line_id  = (int) $applied['line_id'];
		$snapshot = $applied['snapshot'];
		$base     = $applied['movement'];

		$product      = wc_get_product( $line_id );
		$variation_id = $product && $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

		$movement_ok = WC_Inventory_Overview_Movements::insert_purchase(
			array_merge(
				$base,
				array(
					'supplier_name' => $supplier_name,
					'note'          => $note,
				)
			)
		);

		if ( ! $movement_ok ) {
			self::restore_snapshot( $line_id, $snapshot );
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
			wc_delete_product_transients( (int) $product->get_parent_id() );
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		return true;
	}

	/**
	 * Reverse a previously posted Goods Receipt line's own contribution (M4 void).
	 *
	 * Current-state-relative subtraction, NOT a snapshot restore: reads stock/average
	 * NOW (not a value stored at posting time) and subtracts only this line's own
	 * immutable delta (its stored qty and true_unit_cost). This composes correctly
	 * regardless of how many other receipts posted against the same product in the
	 * interim — it never reads or restores anyone else's before/after state. See the
	 * M4 implementation plan's "Voiding correctness" section.
	 *
	 * @param int   $line_id        Variation or simple product ID.
	 * @param float $reversal_qty   The receipt line's own stored qty (> 0).
	 * @param float $reversal_value round(reversal_qty * receipt_line.true_unit_cost, 4).
	 * @return array<string, mixed>|WP_Error Same shape as apply_purchase_line_change().
	 */
	public static function apply_purchase_line_reversal( $line_id, $reversal_qty, $reversal_value ) {
		$line_id = absint( $line_id );
		if ( ! $line_id ) {
			return new WP_Error( 'wc_io_invalid', __( 'Invalid product.', 'wc-inventory-overview' ) );
		}

		if ( $reversal_qty <= 0 ) {
			return new WP_Error( 'wc_io_qty', __( 'Reversal quantity must be greater than zero.', 'wc-inventory-overview' ) );
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

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$movement_pid = $parent_id;
		$movement_vid = $variation_id;

		$current_stock = $product->get_stock_quantity();
		$current_stock = ( null === $current_stock || '' === $current_stock ) ? 0.0 : (float) wc_stock_amount( $current_stock );

		$current_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$current_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );
		$current_value   = ( null === $current_avg ) ? 0.0 : (float) wc_format_decimal( $current_stock * $current_avg, 4 );

		$reversal_qty   = (float) wc_stock_amount( $reversal_qty );
		$reversal_value = (float) wc_format_decimal( $reversal_value, 4 );

		$new_stock = (float) wc_format_decimal( $current_stock - $reversal_qty, 4 );
		if ( $new_stock < 0 ) {
			return new WP_Error(
				'wc_io_gr_void_insufficient_stock',
				__( 'Cannot void: units received by this receipt have since been sold or consumed (resulting stock would be negative).', 'wc-inventory-overview' )
			);
		}

		$new_value = max( 0.0, (float) wc_format_decimal( $current_value - $reversal_value, 4 ) );
		$new_avg   = $new_stock > 0 ? (float) wc_format_decimal( $new_value / $new_stock, 6 ) : 0.0;

		$snapshot = array(
			'stock_quantity' => $current_stock,
			'avg_meta'       => $current_avg_raw,
			'val_meta'       => $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true ),
		);

		$product->set_stock_quantity( $new_stock );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, wc_format_decimal( $new_avg, 6 ) );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, wc_format_decimal( $new_value, 4 ) );

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'wc_io_save', $e->getMessage() );
		}

		return array(
			'line_id'  => $line_id,
			'snapshot' => $snapshot,
			'movement' => array(
				'product_id'            => $movement_pid,
				'variation_id'          => $movement_vid,
				'quantity_change'       => -$reversal_qty,
				'unit_cost'             => $reversal_qty > 0 ? (float) wc_format_decimal( $reversal_value / $reversal_qty, 6 ) : 0.0,
				'total_value'           => -$reversal_value,
				'old_stock'             => $current_stock,
				'new_stock'             => $new_stock,
				'old_average_unit_cost' => $current_avg_raw,
				'new_average_unit_cost' => $new_avg,
				'old_inventory_value'   => $current_value,
				'new_inventory_value'   => $new_value,
			),
		);
	}

	/**
	 * Revert product stock and cost meta after a failed movement insert.
	 *
	 * @param int                  $line_id  Product ID.
	 * @param array<string, mixed> $snapshot Prior state.
	 */
	public static function restore_snapshot( $line_id, array $snapshot ) {
		$p = wc_get_product( $line_id );
		if ( ! $p ) {
			return new WP_Error( 'wc_io_compensation_failed', __( 'Could not restore product state during compensation.', 'wc-inventory-overview' ), array( 'line_id' => $line_id ) );
		}
		$p->set_stock_quantity( $snapshot['stock_quantity'] );
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
		try {
			$p->save();
		} catch ( Throwable $e ) {
			return new WP_Error(
				'wc_io_compensation_failed',
				sprintf(
					/* translators: %1$d: product id, %2$s: error message */
					__( 'Could not restore product %1$d during compensation: %2$s', 'wc-inventory-overview' ),
					$line_id,
					$e->getMessage()
				),
				array( 'line_id' => $line_id )
			);
		}
		return true;
	}

	/**
	 * Read inventory value using M27 §7.1 conventions (prefer stored meta when set).
	 *
	 * @param WC_Product $product   Product.
	 * @param float        $old_stock On-hand qty.
	 * @param float|null   $old_avg   Average float or null.
	 * @return float
	 */
	public static function read_inventory_value_at_post( WC_Product $product, float $old_stock, $old_avg ) {
		$old_val_raw = $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
		if ( '' === $old_val_raw || null === $old_val_raw ) {
			return ( null === $old_avg )
				? 0.0
				: (float) wc_format_decimal( $old_stock * $old_avg, 4 );
		}
		return (float) wc_format_decimal( $old_val_raw, 4 );
	}

	/**
	 * Apply outbound stock + cost change for personal use (M27). No movement row.
	 *
	 * @param int   $line_id Variation or simple product ID.
	 * @param float $qty_out Units withdrawn (> 0).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_outbound_line_change( $line_id, $qty_out ) {
		$line_id = absint( $line_id );
		if ( ! $line_id ) {
			return new WP_Error( 'wc_io_invalid', __( 'Invalid product.', 'wc-inventory-overview' ) );
		}

		if ( $qty_out <= 0 ) {
			return new WP_Error( 'wc_io_qty', __( 'Quantity withdrawn must be greater than zero.', 'wc-inventory-overview' ) );
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
			return new WP_Error( 'wc_io_stock', __( 'Enable stock management for this product before recording personal use.', 'wc-inventory-overview' ) );
		}

		$stock_qty = $product->get_stock_quantity();
		if ( null === $stock_qty || '' === $stock_qty ) {
			return new WP_Error( 'wc_io_stock', __( 'Stock quantity must be set before recording personal use.', 'wc-inventory-overview' ) );
		}

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

		$old_stock = (float) wc_stock_amount( $stock_qty );
		$old_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$old_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );

		if ( null === $old_avg ) {
			return new WP_Error(
				'wc_io_sa_no_cost',
				__( 'No inventory cost on record — receive stock or set cost before recording personal use.', 'wc-inventory-overview' )
			);
		}

		if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && (float) $old_avg <= 0.0 ) {
			return new WP_Error( 'wc_io_cost', __( 'Average unit cost must be greater than zero.', 'wc-inventory-overview' ) );
		}

		$qty_out = (float) wc_stock_amount( $qty_out );
		if ( $qty_out > $old_stock ) {
			return new WP_Error(
				'wc_io_sa_insufficient_stock',
				sprintf(
					/* translators: 1: requested qty, 2: on hand */
					__( 'Cannot withdraw %1$s units — only %2$s on hand.', 'wc-inventory-overview' ),
					wc_format_decimal( $qty_out, 4 ),
					wc_format_decimal( $old_stock, 4 )
				)
			);
		}

		$old_inventory_value = self::read_inventory_value_at_post( $product, $old_stock, $old_avg );
		$unit_cost           = (float) wc_format_decimal( $old_avg, 6 );
		$value_removed       = (float) wc_format_decimal( $qty_out * $unit_cost, 4 );

		$new_stock = (float) wc_format_decimal( $old_stock - $qty_out, 4 );
		$new_inventory_value = max( 0.0, (float) wc_format_decimal( $old_inventory_value - $value_removed, 4 ) );
		$new_average         = $new_stock > 0
			? (float) wc_format_decimal( $new_inventory_value / $new_stock, 6 )
			: 0.0;

		$snapshot = array(
			'stock_quantity' => $old_stock,
			'avg_meta'       => $old_avg_raw,
			'val_meta'       => $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true ),
		);

		$product->set_stock_quantity( $new_stock );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, wc_format_decimal( $new_average, 6 ) );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, wc_format_decimal( $new_inventory_value, 4 ) );

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'wc_io_save', $e->getMessage() );
		}

		return array(
			'line_id'  => $line_id,
			'snapshot' => $snapshot,
			'movement' => array(
				'product_id'            => $parent_id,
				'variation_id'          => $variation_id,
				'quantity_change'       => -$qty_out,
				'unit_cost'             => $unit_cost,
				'total_value'           => -$value_removed,
				'old_stock'             => $old_stock,
				'new_stock'             => $new_stock,
				'old_average_unit_cost' => $old_avg_raw,
				'new_average_unit_cost' => $new_average,
				'old_inventory_value'   => $old_inventory_value,
				'new_inventory_value'   => $new_inventory_value,
			),
			'posting'  => array(
				'unit_cost_at_post' => $unit_cost,
				'value_removed'     => $value_removed,
			),
		);
	}

	/**
	 * Restore outbound personal-use line delta (M27 void). Current-state-relative addition.
	 *
	 * @param int   $line_id        Variation or simple product ID.
	 * @param float $restore_qty    Posted line qty (> 0).
	 * @param float $restore_value  Posted line value_removed (> 0).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function apply_outbound_line_reversal( $line_id, $restore_qty, $restore_value ) {
		$line_id = absint( $line_id );
		if ( ! $line_id ) {
			return new WP_Error( 'wc_io_invalid', __( 'Invalid product.', 'wc-inventory-overview' ) );
		}

		if ( $restore_qty <= 0 ) {
			return new WP_Error( 'wc_io_qty', __( 'Restore quantity must be greater than zero.', 'wc-inventory-overview' ) );
		}

		$product = wc_get_product( $line_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'wc_io_product', __( 'Product not found.', 'wc-inventory-overview' ) );
		}

		$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

		$current_stock = $product->get_stock_quantity();
		$current_stock = ( null === $current_stock || '' === $current_stock ) ? 0.0 : (float) wc_stock_amount( $current_stock );

		$current_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$current_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );
		$current_value   = self::read_inventory_value_at_post( $product, $current_stock, $current_avg );

		$restore_qty   = (float) wc_stock_amount( $restore_qty );
		$restore_value = (float) wc_format_decimal( $restore_value, 4 );

		$new_stock = (float) wc_format_decimal( $current_stock + $restore_qty, 4 );
		$new_value = (float) wc_format_decimal( $current_value + $restore_value, 4 );
		$new_avg   = $new_stock > 0 ? (float) wc_format_decimal( $new_value / $new_stock, 6 ) : 0.0;

		$snapshot = array(
			'stock_quantity' => $current_stock,
			'avg_meta'       => $current_avg_raw,
			'val_meta'       => $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true ),
		);

		$product->set_stock_quantity( $new_stock );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_AVG, wc_format_decimal( $new_avg, 6 ) );
		$product->update_meta_data( WC_Inventory_Overview_Costing::META_VAL, wc_format_decimal( $new_value, 4 ) );

		try {
			$product->save();
		} catch ( Throwable $e ) {
			return new WP_Error( 'wc_io_save', $e->getMessage() );
		}

		$unit_cost = $restore_qty > 0 ? (float) wc_format_decimal( $restore_value / $restore_qty, 6 ) : 0.0;

		return array(
			'line_id'  => $line_id,
			'snapshot' => $snapshot,
			'movement' => array(
				'product_id'            => $parent_id,
				'variation_id'          => $variation_id,
				'quantity_change'       => $restore_qty,
				'unit_cost'             => $unit_cost,
				'total_value'           => $restore_value,
				'old_stock'             => $current_stock,
				'new_stock'             => $new_stock,
				'old_average_unit_cost' => $current_avg_raw,
				'new_average_unit_cost' => $new_avg,
				'old_inventory_value'   => $current_value,
				'new_inventory_value'   => $new_value,
			),
		);
	}
}
