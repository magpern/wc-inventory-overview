<?php
/**
 * Inventory movement log (custom table).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Movement type constants (phase 1: purchase only).
 */
class WC_Inventory_Overview_Movements {

	public const TYPE_PURCHASE        = 'purchase';

	public const TYPE_PURCHASE_BATCH  = 'purchase_batch';

	public const TYPE_COST_ADJUSTMENT = 'cost_adjustment';
	public const TYPE_SALE            = 'sale';
	public const TYPE_ADJUSTMENT = 'adjustment';
	public const TYPE_RETURN    = 'return';
	public const TYPE_REFUND    = 'refund';

	/**
	 * Table name including prefix.
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'wc_io_inventory_movements';
	}

	/**
	 * Human-readable labels for movement_type values (admin + CSV).
	 *
	 * @return array<string, string>
	 */
	public static function movement_type_labels() {
		return array(
			self::TYPE_PURCHASE        => __( 'Purchase', 'wc-inventory-overview' ),
			self::TYPE_PURCHASE_BATCH  => __( 'Purchase batch', 'wc-inventory-overview' ),
			self::TYPE_COST_ADJUSTMENT => __( 'Cost adjustment', 'wc-inventory-overview' ),
			self::TYPE_SALE            => __( 'Sale', 'wc-inventory-overview' ),
			self::TYPE_ADJUSTMENT     => __( 'Adjustment', 'wc-inventory-overview' ),
			self::TYPE_RETURN         => __( 'Return', 'wc-inventory-overview' ),
			self::TYPE_REFUND         => __( 'Refund', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Insert a purchase movement row.
	 *
	 * @param array<string, mixed> $r Column values.
	 * @return bool True on success.
	 */
	public static function insert_purchase( array $r ) {
		return self::insert_purchase_like( $r, self::TYPE_PURCHASE );
	}

	/**
	 * Insert a purchase_batch movement row (batch intake apply).
	 *
	 * @param array<string, mixed> $r Same shape as insert_purchase().
	 * @return bool True on success.
	 */
	public static function insert_purchase_batch( array $r ) {
		return self::insert_purchase_like( $r, self::TYPE_PURCHASE_BATCH );
	}

	/**
	 * @param array<string, mixed> $r      Column values.
	 * @param string                 $type movement_type slug.
	 */
	protected static function insert_purchase_like( array $r, $type ) {
		global $wpdb;

		$table = self::table_name();

		$old_avg = isset( $r['old_average_unit_cost'] ) && null !== $r['old_average_unit_cost'] && '' !== $r['old_average_unit_cost']
			? wc_format_decimal( $r['old_average_unit_cost'], 6 )
			: null;

		$new_avg = wc_format_decimal( $r['new_average_unit_cost'], 6 );

		if ( null === $old_avg ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (
					product_id, variation_id, movement_type, quantity_change, unit_cost, total_value,
					old_stock, new_stock, old_average_unit_cost, new_average_unit_cost,
					old_inventory_value, new_inventory_value, supplier_name, note, user_id
				) VALUES (
					%d, %d, %s, %f, %f, %f,
					%f, %f, NULL, %s,
					%f, %f, %s, %s, %d
				)",
				(int) $r['product_id'],
				(int) $r['variation_id'],
				(string) $type,
				(float) $r['quantity_change'],
				(float) $r['unit_cost'],
				(float) $r['total_value'],
				(float) $r['old_stock'],
				(float) $r['new_stock'],
				$new_avg,
				(float) $r['old_inventory_value'],
				(float) $r['new_inventory_value'],
				sanitize_text_field( (string) ( $r['supplier_name'] ?? '' ) ),
				sanitize_textarea_field( (string) ( $r['note'] ?? '' ) ),
				(int) ( $r['user_id'] ?? get_current_user_id() )
			);
		} else {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (
					product_id, variation_id, movement_type, quantity_change, unit_cost, total_value,
					old_stock, new_stock, old_average_unit_cost, new_average_unit_cost,
					old_inventory_value, new_inventory_value, supplier_name, note, user_id
				) VALUES (
					%d, %d, %s, %f, %f, %f,
					%f, %f, %s, %s,
					%f, %f, %s, %s, %d
				)",
				(int) $r['product_id'],
				(int) $r['variation_id'],
				(string) $type,
				(float) $r['quantity_change'],
				(float) $r['unit_cost'],
				(float) $r['total_value'],
				(float) $r['old_stock'],
				(float) $r['new_stock'],
				$old_avg,
				$new_avg,
				(float) $r['old_inventory_value'],
				(float) $r['new_inventory_value'],
				sanitize_text_field( (string) ( $r['supplier_name'] ?? '' ) ),
				sanitize_textarea_field( (string) ( $r['note'] ?? '' ) ),
				(int) ( $r['user_id'] ?? get_current_user_id() )
			);
		}

		$result = $wpdb->query( $sql );

		return false !== $result && (int) $wpdb->insert_id > 0;
	}

	/**
	 * Insert a cost adjustment movement (no stock change).
	 *
	 * @param array<string, mixed> $r Column values.
	 * @return bool True on success.
	 */
	public static function insert_cost_adjustment( array $r ) {
		global $wpdb;

		$table = self::table_name();

		$old_avg = isset( $r['old_average_unit_cost'] ) && null !== $r['old_average_unit_cost'] && '' !== $r['old_average_unit_cost']
			? wc_format_decimal( $r['old_average_unit_cost'], 6 )
			: null;

		$new_avg = wc_format_decimal( $r['new_average_unit_cost'], 6 );

		$delta_val = (float) $r['new_inventory_value'] - (float) $r['old_inventory_value'];

		if ( null === $old_avg ) {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (
					product_id, variation_id, movement_type, quantity_change, unit_cost, total_value,
					old_stock, new_stock, old_average_unit_cost, new_average_unit_cost,
					old_inventory_value, new_inventory_value, supplier_name, note, user_id
				) VALUES (
					%d, %d, %s, %f, %f, %f,
					%f, %f, NULL, %s,
					%f, %f, %s, %s, %d
				)",
				(int) $r['product_id'],
				(int) $r['variation_id'],
				self::TYPE_COST_ADJUSTMENT,
				0.0,
				0.0,
				$delta_val,
				(float) $r['old_stock'],
				(float) $r['new_stock'],
				$new_avg,
				(float) $r['old_inventory_value'],
				(float) $r['new_inventory_value'],
				'',
				sanitize_textarea_field( (string) ( $r['note'] ?? '' ) ),
				(int) ( $r['user_id'] ?? get_current_user_id() )
			);
		} else {
			$sql = $wpdb->prepare(
				"INSERT INTO {$table} (
					product_id, variation_id, movement_type, quantity_change, unit_cost, total_value,
					old_stock, new_stock, old_average_unit_cost, new_average_unit_cost,
					old_inventory_value, new_inventory_value, supplier_name, note, user_id
				) VALUES (
					%d, %d, %s, %f, %f, %f,
					%f, %f, %s, %s,
					%f, %f, %s, %s, %d
				)",
				(int) $r['product_id'],
				(int) $r['variation_id'],
				self::TYPE_COST_ADJUSTMENT,
				0.0,
				0.0,
				$delta_val,
				(float) $r['old_stock'],
				(float) $r['new_stock'],
				$old_avg,
				$new_avg,
				(float) $r['old_inventory_value'],
				(float) $r['new_inventory_value'],
				'',
				sanitize_textarea_field( (string) ( $r['note'] ?? '' ) ),
				(int) ( $r['user_id'] ?? get_current_user_id() )
			);
		}

		$result = $wpdb->query( $sql );

		return false !== $result && (int) $wpdb->insert_id > 0;
	}
}
