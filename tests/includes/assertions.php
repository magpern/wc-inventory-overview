<?php
/**
 * Custom assertion helpers for WC Inventory Overview tests
 *
 * Provides domain-specific assertions for costing, movements, and business logic.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Custom assertions for WC Inventory Overview domain logic
 */
class WC_Inventory_Overview_Assertions {

	/**
	 * Assert that a product's stock and cost state exactly match expected values.
	 *
	 * Used in characterization tests to verify the complete result of a costing operation.
	 *
	 * @param WC_Product $product The product to check.
	 * @param int   $expected_stock Expected WooCommerce stock quantity.
	 * @param float $expected_avg_cost Expected average unit cost.
	 * @param float $expected_inventory_value Expected total inventory value.
	 * @param string $scenario Optional scenario name for error reporting.
	 * @return void
	 * @throws AssertionError If any value does not match.
	 */
	public static function assert_product_state( WC_Product $product, int $expected_stock, float $expected_avg_cost, float $expected_inventory_value, string $scenario = '' ): void {
		$actual_stock = $product->get_stock_quantity();
		$actual_avg_cost = (float) ( $product->get_meta( '_wc_io_average_unit_cost' ) ?? 0 );
		$actual_inventory_value = (float) ( $product->get_meta( '_wc_io_inventory_value' ) ?? 0 );

		if ( $actual_stock !== $expected_stock ) {
			throw new AssertionError(
				"Stock mismatch {$scenario}: expected {$expected_stock}, got {$actual_stock}"
			);
		}

		$expected_avg_str = number_format( $expected_avg_cost, 6, '.', '' );
		$actual_avg_str   = number_format( $actual_avg_cost, 6, '.', '' );
		if ( $expected_avg_str !== $actual_avg_str ) {
			throw new AssertionError(
				"Average cost mismatch {$scenario}: expected {$expected_avg_str}, got {$actual_avg_str}"
			);
		}

		$expected_val_str = number_format( $expected_inventory_value, 4, '.', '' );
		$actual_val_str   = number_format( $actual_inventory_value, 4, '.', '' );
		if ( $expected_val_str !== $actual_val_str ) {
			throw new AssertionError(
				"Inventory value mismatch {$scenario}: expected {$expected_val_str}, got {$actual_val_str}"
			);
		}
	}

	/**
	 * Assert that a movement record exists in the inventory movements table with expected values.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $movement_type The movement type (e.g., 'purchase_batch', 'purchase', 'cost_adjustment').
	 * @param int    $expected_qty Expected quantity changed.
	 * @param float  $expected_unit_cost Expected unit cost.
	 * @param string $scenario Optional scenario name.
	 * @return array|null The found movement row or null.
	 * @throws AssertionError If movement not found.
	 */
	public static function assert_movement_exists( int $product_id, string $movement_type, int $expected_qty, float $expected_unit_cost, string $scenario = '' ): ?array {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$table  = "{$prefix}wc_io_inventory_movements";

		// Check if table exists.
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			throw new AssertionError( "Movements table does not exist" );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d AND movement_type = %s ORDER BY id DESC LIMIT 1",
				$product_id,
				$movement_type
			),
			ARRAY_A
		);

		if ( ! $row ) {
			throw new AssertionError(
				"Movement not found {$scenario}: product_id={$product_id}, type={$movement_type}"
			);
		}

		$actual_qty = (int) $row['qty_change'];
		$actual_unit_cost = (float) $row['unit_cost'];

		if ( $actual_qty !== $expected_qty ) {
			throw new AssertionError(
				"Movement quantity mismatch {$scenario}: expected {$expected_qty}, got {$actual_qty}"
			);
		}

		$expected_cost_str = number_format( $expected_unit_cost, 6, '.', '' );
		$actual_cost_str   = number_format( $actual_unit_cost, 6, '.', '' );
		if ( $expected_cost_str !== $actual_cost_str ) {
			throw new AssertionError(
				"Movement unit cost mismatch {$scenario}: expected {$expected_cost_str}, got {$actual_cost_str}"
			);
		}

		return $row;
	}

	/**
	 * Assert that no movement records exist for a given product and type.
	 *
	 * Used in error-path characterization to verify that failed operations leave no partial state.
	 *
	 * @param int    $product_id The product ID.
	 * @param string $movement_type Optional; if provided, check only this type.
	 * @param string $scenario Optional scenario name.
	 * @return void
	 * @throws AssertionError If movements found when none expected.
	 */
	public static function assert_no_movements_exist( int $product_id, string $movement_type = '', string $scenario = '' ): void {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$table  = "{$prefix}wc_io_inventory_movements";

		// Check if table exists (it might not in M0, only in later milestones).
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
			return; // Table doesn't exist yet; skip this assertion.
		}

		if ( $movement_type ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND movement_type = %s",
					$product_id,
					$movement_type
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE product_id = %d",
					$product_id
				)
			);
		}

		if ( $count > 0 ) {
			throw new AssertionError(
				"Expected no movements {$scenario}, but found {$count}"
			);
		}
	}
}
