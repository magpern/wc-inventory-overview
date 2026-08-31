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

	/**
	 * Scenario: insert_personal_use creates correct outbound movement row (M27).
	 *
	 * @test
	 */
	public function test_insert_personal_use_creates_movement(): void {
		if ( ! class_exists( 'WC_Inventory_Overview_Movements' ) ) {
			$this->markTestSkipped( 'Movements class not found' );
		}

		WC_Inventory_Overview_Install::create_tables();

		$product = $this->create_simple_product(
			array(
				'name'      => 'Personal Use Product',
				'stock_qty' => 20,
			)
		);
		$this->set_product_average_cost( $product, 6.0 );
		$this->set_product_inventory_value( $product, 120.0 );

		$inserted = WC_Inventory_Overview_Movements::insert_personal_use(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => -3,
				'unit_cost'             => 6.0,
				'total_value'           => -18.0,
				'old_stock'             => 20,
				'new_stock'             => 17,
				'old_average_unit_cost' => 6.0,
				'new_average_unit_cost' => 6.0,
				'old_inventory_value'   => 120.0,
				'new_inventory_value'   => 102.0,
				'reference_id'          => 42,
				'note'                  => 'Personal use SA-2026-00001 — samples',
				'user_id'               => get_current_user_id(),
			)
		);

		$this->assertTrue( $inserted );

		global $wpdb;
		$table = WC_Inventory_Overview_Movements::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE reference_type = %s AND reference_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::REFERENCE_TYPE_STOCK_ADJUSTMENT,
				42
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE, $row['movement_type'] );
		$this->assertSame( WC_Inventory_Overview_Movements::REFERENCE_TYPE_STOCK_ADJUSTMENT, $row['reference_type'] );
		$this->assertDecimalEqual( -3.0, (float) $row['quantity_change'], 4 );
	}

	/**
	 * Scenario: personal_use_void row mirrors posted delta with opposite sign (M27).
	 *
	 * @test
	 */
	public function test_insert_personal_use_void_creates_movement(): void {
		WC_Inventory_Overview_Install::create_tables();

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		$inserted = WC_Inventory_Overview_Movements::insert_personal_use_void(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => 3,
				'unit_cost'             => 6.0,
				'total_value'           => 18.0,
				'old_stock'             => 17,
				'new_stock'             => 20,
				'old_average_unit_cost' => 6.0,
				'new_average_unit_cost' => 6.0,
				'old_inventory_value'   => 102.0,
				'new_inventory_value'   => 120.0,
				'reference_id'          => 42,
				'note'                  => 'Void personal use SA-2026-00001 — mistake',
			)
		);

		$this->assertTrue( $inserted );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT movement_type, quantity_change, total_value FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				42
			),
			ARRAY_A
		);

		$this->assertSame( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID, $row['movement_type'] );
		$this->assertDecimalEqual( 3.0, (float) $row['quantity_change'], 4 );
		$this->assertDecimalEqual( 18.0, (float) $row['total_value'], 4 );
	}

	/**
	 * Movements filter dropdown includes personal use types (M27 §5.1).
	 */
	public function test_movements_filter_includes_personal_use_types(): void {
		$labels = WC_Inventory_Overview_Movements::movement_type_labels();
		$this->assertArrayHasKey( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE, $labels );
		$this->assertArrayHasKey( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID, $labels );
		$this->assertSame( 'Personal use', $labels[ WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE ] );
		$this->assertSame( 'Personal use void', $labels[ WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID ] );

		$_REQUEST['wc_io_mv_type'] = WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE;
		$args                      = WC_Inventory_Overview_Movements_List_Table::get_request_args();
		$this->assertSame( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE, $args['type'] );
	}

	/**
	 * Net economic outbound = personal_use + personal_use_void total_value (M27 §5.1 fixture).
	 */
	public function test_net_personal_use_reporting_fixture(): void {
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );

		WC_Inventory_Overview_Movements::insert_personal_use(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => -4,
				'unit_cost'             => 5.0,
				'total_value'           => -20.0,
				'old_stock'             => 10,
				'new_stock'             => 6,
				'old_average_unit_cost' => 5.0,
				'new_average_unit_cost' => 5.0,
				'old_inventory_value'   => 50.0,
				'new_inventory_value'   => 30.0,
				'reference_id'          => 1,
				'note'                  => 'Withdrawal',
			)
		);
		WC_Inventory_Overview_Movements::insert_personal_use_void(
			array(
				'product_id'            => $product->get_id(),
				'variation_id'          => 0,
				'quantity_change'       => 4,
				'unit_cost'             => 5.0,
				'total_value'           => 20.0,
				'old_stock'             => 6,
				'new_stock'             => 10,
				'old_average_unit_cost' => 5.0,
				'new_average_unit_cost' => 5.0,
				'old_inventory_value'   => 30.0,
				'new_inventory_value'   => 50.0,
				'reference_id'          => 1,
				'note'                  => 'Void withdrawal',
			)
		);

		$table = WC_Inventory_Overview_Movements::table_name();
		$net   = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total_value), 0) FROM {$table} WHERE movement_type IN (%s, %s) AND reference_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE,
				WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID,
				1
			)
		);

		$this->assertDecimalEqual( 0.0, $net, 4, 'Net personal use economic outbound must sum posted + void rows.' );
	}

	/**
	 * Movement list renders stock-adjustment drill-down link (M27 §10).
	 */
	public function test_movement_list_renders_stock_adjustment_drill_down_link(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$table = new WC_Inventory_Overview_Movements_List_Table();
		$item  = (object) array(
			'movement_type' => WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE,
			'reference_type' => WC_Inventory_Overview_Movements::REFERENCE_TYPE_STOCK_ADJUSTMENT,
			'reference_id'   => 99,
		);

		$method = new ReflectionMethod( WC_Inventory_Overview_Movements_List_Table::class, 'column_movement_type' );
		$method->setAccessible( true );
		$html = (string) $method->invoke( $table, $item );

		$this->assertStringContainsString( 'View adjustment', $html );
		$this->assertStringContainsString( 'sa_action=view', $html );
		$this->assertStringContainsString( 'sa_id=99', $html );
	}

	/**
	 * CSV export query preserves personal_use type filter (M27 §5.1).
	 */
	public function test_csv_export_query_preserves_personal_use_filter(): void {
		$_REQUEST = array(
			'page'          => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'           => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
			'wc_io_mv_type' => WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID,
		);

		$args = WC_Inventory_Overview_Movements_List_Table::get_request_args();
		$this->assertSame( WC_Inventory_Overview_Movements::TYPE_PERSONAL_USE_VOID, $args['type'] );

		$method = new ReflectionMethod( WC_Inventory_Overview_Movements_List_Table::class, 'build_where_sql' );
		$method->setAccessible( true );
		$where_parts = $method->invoke( null, $args['type'] );
		$this->assertStringContainsString( 'movement_type', $where_parts['where_sql'] );
	}
}
