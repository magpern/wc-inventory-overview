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

		// insert_purchase_batch() takes a single associative array and returns
		// bool (includes/class-wc-inventory-overview-movements.php), not a
		// positional-argument list returning a movement ID.
		$inserted = WC_Inventory_Overview_Movements::insert_purchase_batch(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => 10, // new_stock - old_stock
				'unit_cost'             => 15.5,
				'total_value'           => 1705.0, // 110 * 15.5
				'old_stock'             => 100,
				'new_stock'             => 110,
				'old_average_unit_cost' => null,
				'new_average_unit_cost' => 15.5,
				'old_inventory_value'   => 0.0,
				'new_inventory_value'   => 1705.0,
				'supplier_name'         => 'Test Supplier',
				'note'                  => 'Batch ID: 1, Reference: REF-001, ...',
			)
		);

		$this->assertTrue( $inserted, 'insert_purchase_batch should return true' );

		// Verify movement record exists in the ledger (if table exists).
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d AND movement_type = %s ORDER BY id DESC LIMIT 1",
					$product->get_id(),
					WC_Inventory_Overview_Movements::TYPE_PURCHASE_BATCH
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement, 'Movement should exist in database' );
			$this->assertSame( 'purchase_batch', $movement['movement_type'] );
			$this->assertSame( (int) $product->get_id(), (int) $movement['product_id'] );
			$this->assertSame( 10, (int) $movement['quantity_change'], 'quantity_change = new_stock - old_stock' );
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

		// insert_purchase() takes a single associative array and returns bool.
		$inserted = WC_Inventory_Overview_Movements::insert_purchase(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => 20, // new_stock - old_stock
				'unit_cost'             => 12.0,
				'total_value'           => 240.0,
				'old_stock'             => 50,
				'new_stock'             => 70,
				'old_average_unit_cost' => null,
				'new_average_unit_cost' => 12.0,
				'old_inventory_value'   => 0.0,
				'new_inventory_value'   => 240.0,
				'supplier_name'         => 'Test Supplier',
				'note'                  => 'Purchased 20 units',
			)
		);

		$this->assertTrue( $inserted, 'insert_purchase should return true' );

		// Verify in database if table exists.
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d AND movement_type = %s ORDER BY id DESC LIMIT 1",
					$product->get_id(),
					WC_Inventory_Overview_Movements::TYPE_PURCHASE
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement );
			$this->assertSame( 'purchase', $movement['movement_type'] );
			$this->assertSame( 20, (int) $movement['quantity_change'], 'quantity_change = 70 - 50' );
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

		// insert_cost_adjustment() takes a single associative array and returns
		// bool; quantity_change/unit_cost are always 0, total_value is the
		// old->new inventory value delta (computed internally, not supplied).
		$inserted = WC_Inventory_Overview_Movements::insert_cost_adjustment(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'old_stock'             => 100,
				'new_stock'             => 100,
				'old_average_unit_cost' => 10.0,
				'new_average_unit_cost' => 10.5,
				'old_inventory_value'   => 1000.0,
				'new_inventory_value'   => 1050.0,
				'note'                  => 'Freight adjustment',
			)
		);

		$this->assertTrue( $inserted, 'insert_cost_adjustment should return true' );

		// Verify in database if table exists.
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_inventory_movements';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
			$movement = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE product_id = %d AND movement_type = %s ORDER BY id DESC LIMIT 1",
					$product->get_id(),
					WC_Inventory_Overview_Movements::TYPE_COST_ADJUSTMENT
				),
				ARRAY_A
			);

			$this->assertNotNull( $movement );
			$this->assertSame( 'cost_adjustment', $movement['movement_type'] );
			$this->assertSame( 0, (int) $movement['quantity_change'], 'Cost adjustment should not change qty' );
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
