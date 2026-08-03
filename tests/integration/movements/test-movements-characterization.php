<?php
/**
 * Characterization tests for Inventory Movements ledger
 *
 * Golden test suite for Movements::insert_purchase_batch, insert_purchase, and insert_cost_adjustment.
 * Locks the movement record creation behavior.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 * @group movements
 */

class Test_Movements_Characterization extends WC_Inventory_Overview_Test_Case {

	/**
	 * Scenario: insert_purchase_batch creates correct movement record
	 *
	 * Tests that posting a batch creates one movement per line with correct values.
	 *
	 * @test
	 */
	public function test_insert_purchase_batch_creates_movement(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Movements' ) ) {
			$this->markTestSkipped( 'Movements class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Test Product',
				'stock_qty' => 0,
			)
		);

		// Create a mock movement record data (as would be generated during batch apply).
		$movement_result = WC_Inventory_Overview_Movements::insert_purchase_batch(
			$product->get_id(),
			0,  // variation_id
			100, // old_stock
			110, // new_stock
			15.5, // unit_cost
			1705.0, // total_value (110 * 15.5)
			'Test Supplier',
			'Batch ID: 1, Reference: REF-001, ...'
		);

		// Verify movement was created.
		$this->assertNotFalse( $movement_result, 'insert_purchase_batch should return movement ID' );

		// Verify movement record exists in the ledger (if table exists).
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d",
					$movement_result
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement, 'Movement should exist in database' );
			$this->assertSame( 'purchase_batch', $movement['movement_type'] );
			$this->assertSame( (int) $product->get_id(), (int) $movement['product_id'] );
			$this->assertSame( 10, (int) $movement['qty_change'], 'qty_change = new_stock - old_stock' );
		}
	}

	/**
	 * Scenario: insert_purchase creates movement with correct fields
	 *
	 * Tests that a simple "quick restock" creates a movement record with correct structure.
	 *
	 * @test
	 */
	public function test_insert_purchase_creates_movement(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Movements' ) ) {
			$this->markTestSkipped( 'Movements class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Test Product',
				'stock_qty' => 50,
			)
		);

		// Insert a purchase movement (quick restock).
		$movement_result = WC_Inventory_Overview_Movements::insert_purchase(
			$product->get_id(),
			0,  // variation_id
			50, // old_stock
			70, // new_stock
			12.0, // unit_cost
			240.0, // total_value
			'Test Supplier',
			'Purchased 20 units'
		);

		$this->assertNotFalse( $movement_result, 'insert_purchase should return movement ID' );

		// Verify in database if table exists.
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d",
					$movement_result
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement );
			$this->assertSame( 'purchase', $movement['movement_type'] );
			$this->assertSame( 20, (int) $movement['qty_change'], 'qty_change = 70 - 50' );
		}
	}

	/**
	 * Scenario: insert_cost_adjustment preserves stock, changes average/value
	 *
	 * Tests that cost adjustments create movements with qty_change = 0 and correct value delta.
	 *
	 * @test
	 */
	public function test_insert_cost_adjustment_movement(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Movements' ) ) {
			$this->markTestSkipped( 'Movements class not found' );
		}

		$product = $this->create_simple_product(
			array(
				'name'      => 'Test Product',
				'stock_qty' => 100,
			)
		);

		$this->set_product_average_cost( $product, 10.0 );
		$this->set_product_inventory_value( $product, 1000.0 );

		// Insert a cost adjustment (e.g., freight cost).
		$movement_result = WC_Inventory_Overview_Movements::insert_cost_adjustment(
			$product->get_id(),
			0,  // variation_id
			100, // stock (unchanged)
			100, // stock (unchanged)
			10.0, // old_average
			10.5, // new_average
			1000.0, // old_value
			1050.0, // new_value
			'Freight adjustment'
		);

		$this->assertNotFalse( $movement_result, 'insert_cost_adjustment should return movement ID' );

		// Verify in database if table exists.
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d",
					$movement_result
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement );
			$this->assertSame( 'cost_adjustment', $movement['movement_type'] );
			$this->assertSame( 0, (int) $movement['qty_change'], 'Cost adjustment should not change qty' );
			// Value delta should equal new_value - old_value.
			$this->assertDecimalEqual(
				50.0,
				(float) $movement['total_value'],
				4,
				'Movement value should be the adjustment amount'
			);
		}
	}
}
