<?php
/**
 * Characterization tests for Cost Adjustment Service
 *
 * Golden test suite for Cost_Adjustment_Service::process.
 * Locks cost-adjustment behavior (updates average/value without changing stock).
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 * @group cost-adjustment
 */

class Test_Cost_Adjustment_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * Scenario: Cost adjustment updates average and value, preserves stock
	 *
	 * Tests that applying a cost adjustment (e.g., freight invoice) updates the product's
	 * average cost and inventory value without changing stock quantity.
	 *
	 * @test
	 */
	public function test_cost_adjustment_updates_average_preserves_stock(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Cost_Adjustment_Service' ) ) {
			$this->markTestSkipped( 'Cost_Adjustment_Service class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Test Product',
				'stock_qty' => 100,
			)
		);

		$this->set_product_average_cost( $product, 10.0 );
		$this->set_product_inventory_value( $product, 1000.0 );

		$product = wc_get_product( $product->get_id() );

		// Apply a cost adjustment: freight of 50 EUR total, spreading across 100 units = +0.50 per unit.
		$new_average = 10.5;
		$new_value = 1050.0;

		// process( $line_id, $new_avg, $note = '' ) takes a single line_id
		// (simple product ID or variation ID), not a separate
		// product_id/variation_id pair.
		$result = WC_Inventory_Overview_Cost_Adjustment_Service::process(
			$product->get_id(),
			$new_average,
			'Freight adjustment'
		);

		$this->assertNotFalse( $result, is_wp_error( $result ) ? $result->get_error_message() : 'process should return result' );

		// Verify product state: stock unchanged, average/value updated.
		$product = wc_get_product( $product->get_id() );

		$this->assertSame(
			100,
			$product->get_stock_quantity(),
			'Cost adjustment must not change stock'
		);

		$this->assertDecimalEqual(
			$new_average,
			$this->get_product_average_cost( $product ),
			6,
			'Average cost should be updated'
		);

		$this->assertDecimalEqual(
			$new_value,
			$this->get_product_inventory_value( $product ),
			4,
			'Inventory value should be updated (stock * new_average)'
		);
	}

	/**
	 * Scenario: Cost adjustment on zero-stock product
	 *
	 * Tests that a cost adjustment applied to a sold-out product is recorded
	 * but has no effect on on-hand stock (since there is none).
	 *
	 * @test
	 */
	public function test_cost_adjustment_zero_stock(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Cost_Adjustment_Service' ) ) {
			$this->markTestSkipped( 'Cost_Adjustment_Service class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Sold Out Product',
				'stock_qty' => 0,
			)
		);

		// Set historical average (e.g., from a prior shipment that was sold out).
		$this->set_product_average_cost( $product, 10.0 );
		$this->set_product_inventory_value( $product, 0.0 );

		// Apply a late freight invoice.
		$result = WC_Inventory_Overview_Cost_Adjustment_Service::process(
			$product->get_id(),
			10.05, // Slightly higher average (unabsorbed cost).
			'Late freight (zero on-hand)'
		);

		$this->assertNotFalse( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$product = wc_get_product( $product->get_id() );

		// Stock should still be 0.
		$this->assertSame( 0, $product->get_stock_quantity() );

		// New average should be stored even though on-hand is zero.
		$this->assertDecimalEqual(
			10.05,
			$this->get_product_average_cost( $product ),
			6
		);

		// Inventory value should remain 0 (0 * 10.05).
		$this->assertSame( 0.0, $this->get_product_inventory_value( $product ) );
	}
}
