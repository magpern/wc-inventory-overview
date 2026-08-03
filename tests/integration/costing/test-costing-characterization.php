<?php
/**
 * Characterization tests for weighted-average costing
 *
 * Golden test suite for Restock_Service::apply_purchase_line_change and Costing logic.
 * These tests lock current costing behavior before any future changes.
 *
 * Per M0.14 fixture-governance rule:
 * - Test failures must never be fixed by adjusting expected values.
 * - Expected values are golden only when an approved milestone authorizes a change.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 * @group costing
 */

class Test_Costing_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * Scenario: Fresh stock from zero (null prior average)
	 *
	 * Tests that when adding inventory to a product with no prior history,
	 * the new average equals the incoming unit cost exactly.
	 *
	 * @test
	 */
	public function test_fresh_stock_from_zero(): void {
		$fixture = WC_Inventory_Overview_Fixtures::load( 'costing/fixture-fresh-stock-from-zero.php' );

		// Create a simple product with no initial stock.
		$product = $this->create_simple_product(
			array(
				'name'      => 'Product A (Fresh)',
				'stock_qty' => $fixture['setup']['old_stock'],
			)
		);

		// Apply the operation: add 10 units at cost 15.5.
		if ( class_exists( 'Restock_Service' ) ) {
			$result = Restock_Service::apply_purchase_line_change(
				$product->get_id(),
				0, // variation_id for simple product
				$fixture['operation']['added_stock'],
				$fixture['operation']['unit_cost_entered']
			);

			// Verify operation succeeded.
			$this->assertIsArray( $result, 'apply_purchase_line_change should return a snapshot array' );
		}

		// Assert final product state matches golden fixture.
		$this->assertDecimalEqual(
			$fixture['expected']['new_average_cost'],
			$this->get_product_average_cost( $product ),
			6,
			"Average cost {$fixture['scenario_name']}"
		);

		$this->assertDecimalEqual(
			$fixture['expected']['new_inventory_value'],
			$this->get_product_inventory_value( $product ),
			4,
			"Inventory value {$fixture['scenario_name']}"
		);

		$this->assertSame(
			$fixture['expected']['new_stock'],
			$product->get_stock_quantity(),
			"Stock quantity {$fixture['scenario_name']}"
		);
	}

	/**
	 * Scenario: Blend into existing stock and average
	 *
	 * Tests that when adding inventory to a product with existing stock and average cost,
	 * the new average is computed as the value-weighted mean.
	 *
	 * Old: 100 units at avg 10.0
	 * New: 50 units at 12.0
	 * Expected: 150 units at 10.6667
	 *
	 * @test
	 */
	public function test_blend_existing_average(): void {
		$fixture = WC_Inventory_Overview_Fixtures::load( 'costing/fixture-blend-existing-average.php' );

		// Create a simple product with existing stock and average.
		$product = $this->create_simple_product(
			array(
				'name'      => 'Product B (Blend)',
				'stock_qty' => $fixture['setup']['old_stock'],
			)
		);

		// Set the existing average cost and inventory value.
		$this->set_product_average_cost( $product, $fixture['setup']['old_average_cost'] );
		$this->set_product_inventory_value( $product, $fixture['setup']['old_inventory_value'] );

		// Refresh the product object to ensure meta is loaded.
		$product = wc_get_product( $product->get_id() );

		// Apply the operation: add 50 units at cost 12.0.
		if ( class_exists( 'Restock_Service' ) ) {
			$result = Restock_Service::apply_purchase_line_change(
				$product->get_id(),
				0, // variation_id for simple product
				$fixture['operation']['added_stock'],
				$fixture['operation']['unit_cost_entered']
			);

			// Verify operation succeeded.
			$this->assertIsArray( $result, 'apply_purchase_line_change should return a snapshot array' );
		}

		// Refresh the product to get updated meta.
		$product = wc_get_product( $product->get_id() );

		// Assert final product state matches golden fixture.
		$this->assertDecimalEqual(
			$fixture['expected']['new_average_cost'],
			$this->get_product_average_cost( $product ),
			4,
			"Average cost {$fixture['scenario_name']}"
		);

		$this->assertDecimalEqual(
			$fixture['expected']['new_inventory_value'],
			$this->get_product_inventory_value( $product ),
			4,
			"Inventory value {$fixture['scenario_name']}"
		);

		$this->assertSame(
			$fixture['expected']['new_stock'],
			$product->get_stock_quantity(),
			"Stock quantity {$fixture['scenario_name']}"
		);
	}

	/**
	 * Scenario: Variation costing (same logic as simple, different product type)
	 *
	 * Tests that weighted-average costing applies identically to variations as to simple products.
	 *
	 * @test
	 */
	public function test_variation_costing_identical_to_simple(): void {
		// Create a variable product with one variation.
		$parent = $this->create_variable_product(
			array( 'name' => 'Variable Product' ),
			array(
				array(
					'name'      => 'Red / Size M',
					'stock_qty' => 0,
				),
			)
		);

		// Get the created variation.
		$variations = $parent->get_children();
		$this->assertCount( 1, $variations, 'Should have one variation' );

		$variation = wc_get_product( $variations[0] );

		// Apply costing to the variation.
		if ( class_exists( 'Restock_Service' ) ) {
			$result = Restock_Service::apply_purchase_line_change(
				$parent->get_id(),
				$variation->get_id(),
				10,
				15.5
			);

			// Verify operation succeeded.
			$this->assertIsArray( $result );
		}

		// Verify variation's costing is identical to what we'd expect for a simple product.
		$variation = wc_get_product( $variation->get_id() );

		$this->assertDecimalEqual(
			15.5,
			$this->get_product_average_cost( $variation ),
			6,
			'Variation average cost should equal input cost'
		);

		$this->assertDecimalEqual(
			155.0,
			$this->get_product_inventory_value( $variation ),
			4,
			'Variation inventory value should be 10 * 15.5'
		);
	}

	/**
	 * Scenario: Snapshot capture and restore integrity
	 *
	 * Tests that apply_purchase_line_change returns a snapshot that,
	 * when passed to restore_snapshot(), exactly reverses the mutation.
	 *
	 * @test
	 */
	public function test_snapshot_capture_and_restore(): void {
		$product = $this->create_simple_product(
			array(
				'name'      => 'Snapshot Test',
				'stock_qty' => 100,
			)
		);

		$this->set_product_average_cost( $product, 10.0 );
		$this->set_product_inventory_value( $product, 1000.0 );

		$product = wc_get_product( $product->get_id() );

		// Capture pre-operation state.
		$pre_stock = $product->get_stock_quantity();
		$pre_avg = $this->get_product_average_cost( $product );
		$pre_value = $this->get_product_inventory_value( $product );

		// Apply an operation and capture its snapshot.
		$snapshot = null;
		if ( class_exists( 'Restock_Service' ) ) {
			$snapshot = Restock_Service::apply_purchase_line_change(
				$product->get_id(),
				0,
				50,
				12.0
			);
		}

		// Verify state changed.
		$product = wc_get_product( $product->get_id() );
		$post_stock = $product->get_stock_quantity();
		$this->assertNotSame( $pre_stock, $post_stock, 'Stock should have changed' );

		// Restore the snapshot (if method exists).
		if ( $snapshot && method_exists( 'Restock_Service', 'restore_snapshot' ) ) {
			Restock_Service::restore_snapshot( $product->get_id(), 0, $snapshot );

			// Verify state is fully restored.
			$product = wc_get_product( $product->get_id() );
			$this->assertSame(
				$pre_stock,
				$product->get_stock_quantity(),
				'Stock should be restored exactly'
			);

			$this->assertDecimalEqual(
				$pre_avg,
				$this->get_product_average_cost( $product ),
				6,
				'Average cost should be restored exactly'
			);

			$this->assertDecimalEqual(
				$pre_value,
				$this->get_product_inventory_value( $product ),
				4,
				'Inventory value should be restored exactly'
			);
		}
	}
}
