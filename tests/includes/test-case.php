<?php
/**
 * Base test case for WC Inventory Overview
 *
 * Extends WordPress PHPUnit with plugin-specific helpers.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * WC_Inventory_Overview_Test_Case
 *
 * Base class for integration tests. Ensures proper isolation and cleanup.
 */
abstract class WC_Inventory_Overview_Test_Case extends WP_UnitTestCase {

	/**
	 * Set up test fixtures (run before each test method).
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure database is fresh for each test.
		$this->flush_cache();
	}

	/**
	 * Tear down after each test (run after each test method).
	 */
	public function tearDown(): void {
		parent::tearDown();

		// Flush any remaining caches.
		$this->flush_cache();
	}

	/**
	 * Flush all WordPress caches (object cache, transients, etc.).
	 */
	protected function flush_cache(): void {
		wp_cache_flush();

		// Also flush product-specific transients if WooCommerce is active.
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			// WooCommerce transient cleanup would be called per-product in real tests.
		}
	}

	/**
	 * Create a simple WooCommerce product for testing.
	 *
	 * @param array $props Product properties to override defaults.
	 * @return WC_Product_Simple The created product.
	 */
	protected function create_simple_product( $props = array() ): WC_Product_Simple {
		$defaults = array(
			'name'      => 'Test Product',
			'type'      => 'simple',
			'status'    => 'publish',
			'stock_qty' => 0,
			'manage_stock' => true,
		);

		$props    = array_merge( $defaults, $props );
		$product  = new WC_Product_Simple();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );
		$product->set_manage_stock( $props['manage_stock'] );
		$product->set_stock_quantity( $props['stock_qty'] );

		$product->save();

		return $product;
	}

	/**
	 * Create a variable WooCommerce product with variations.
	 *
	 * @param array $props Product properties.
	 * @param array $variations Array of variation data.
	 * @return WC_Product_Variable The created variable product.
	 */
	protected function create_variable_product( $props = array(), $variations = array() ): WC_Product_Variable {
		$defaults = array(
			'name'      => 'Test Variable Product',
			'type'      => 'variable',
			'status'    => 'publish',
		);

		$props   = array_merge( $defaults, $props );
		$product = new WC_Product_Variable();
		$product->set_name( $props['name'] );
		$product->set_status( $props['status'] );

		$product->save();

		// Add variations.
		foreach ( $variations as $var_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_name( $var_data['name'] ?? 'Variation' );
			$variation->set_status( 'publish' );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( $var_data['stock_qty'] ?? 0 );

			// Set attributes.
			if ( isset( $var_data['attributes'] ) ) {
				foreach ( $var_data['attributes'] as $attr_name => $attr_value ) {
					$variation->set_attributes( array( $attr_name => $attr_value ) );
				}
			}

			$variation->save();
		}

		return $product;
	}

	/**
	 * Set a product's average cost meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $avg_cost The average cost value.
	 * @return void
	 */
	protected function set_product_average_cost( WC_Product $product, float $avg_cost ): void {
		$product->update_meta_data( '_wc_io_average_unit_cost', $avg_cost );
		$product->save();
	}

	/**
	 * Set a product's inventory value meta (used in golden fixture setup).
	 *
	 * @param WC_Product $product The product to update.
	 * @param float      $inventory_value The inventory value.
	 * @return void
	 */
	protected function set_product_inventory_value( WC_Product $product, float $inventory_value ): void {
		$product->update_meta_data( '_wc_io_inventory_value', $inventory_value );
		$product->save();
	}

	/**
	 * Get a product's average cost (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float|null The average cost or null if not set.
	 */
	protected function get_product_average_cost( WC_Product $product ): ?float {
		$cost = $product->get_meta( '_wc_io_average_unit_cost' );
		return ( '' === $cost || null === $cost ) ? null : (float) $cost;
	}

	/**
	 * Get a product's inventory value (as persisted in meta).
	 *
	 * @param WC_Product $product The product.
	 * @return float The inventory value.
	 */
	protected function get_product_inventory_value( WC_Product $product ): float {
		return (float) $product->get_meta( '_wc_io_inventory_value', true );
	}

	/**
	 * Assert that two decimal values are equal to a given precision.
	 *
	 * @param float  $expected The expected value.
	 * @param float  $actual The actual value.
	 * @param int    $decimals Number of decimal places to compare (default 4).
	 * @param string $message Optional failure message.
	 * @return void
	 */
	protected function assertDecimalEqual( float $expected, float $actual, int $decimals = 4, string $message = '' ): void {
		$expected_str = number_format( $expected, $decimals, '.', '' );
		$actual_str   = number_format( $actual, $decimals, '.', '' );

		$this->assertEquals( $expected_str, $actual_str, $message ?: "Decimal values do not match to {$decimals} places" );
	}
}
