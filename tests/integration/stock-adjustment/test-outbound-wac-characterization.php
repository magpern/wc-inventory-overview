<?php
/**
 * Golden characterization tests for outbound WAC math (M27 §7.1).
 *
 * Asserts against actual Restock_Service::apply_outbound_line_change() output —
 * not hand-calculated assumptions about new_average === old_average.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 */

class Test_WC_IO_Outbound_WAC_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
	}

	/**
	 * @param WC_Product $product Product.
	 * @param float      $stock   Stock qty.
	 * @param float      $avg     Average unit cost.
	 * @param float|null $value   Inventory value meta (null = omit, derive from stock×avg).
	 */
	private function seed_product_cost( WC_Product $product, float $stock, float $avg, ?float $value = null ): void {
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $stock );
		$this->set_product_average_cost( $product, $avg );
		if ( null !== $value ) {
			$this->set_product_inventory_value( $product, $value );
		} else {
			$this->set_product_inventory_value( $product, (float) wc_format_decimal( $stock * $avg, 4 ) );
		}
		$product->save();
		wc_delete_product_transients( $product->get_id() );
	}

	/**
	 * (a) Meta value matches stock × avg — new_average equals old_avg within tolerance.
	 */
	public function test_meta_consistent_with_stock_times_avg() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$this->seed_product_cost( $product, 100.0, 10.0 );

		$result = WC_Inventory_Overview_Restock_Service::apply_outbound_line_change( $product->get_id(), 25 );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$m = $result['movement'];
		$this->assertDecimalEqual( -25.0, (float) $m['quantity_change'], 4 );
		$this->assertDecimalEqual( 10.0, (float) $m['unit_cost'], 6 );
		$this->assertDecimalEqual( -250.0, (float) $m['total_value'], 4 );
		$this->assertDecimalEqual( 100.0, (float) $m['old_stock'], 4 );
		$this->assertDecimalEqual( 75.0, (float) $m['new_stock'], 4 );
		$this->assertDecimalEqual( 750.0, (float) $m['new_inventory_value'], 4 );
		$this->assertDecimalEqual( 10.0, (float) $m['new_average_unit_cost'], 6 );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 75.0, (float) $fresh->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 10.0, $this->get_product_average_cost( $fresh ), 6 );
		$this->assertDecimalEqual( 750.0, $this->get_product_inventory_value( $fresh ), 4 );
	}

	/**
	 * (b) Drifted _wc_io_inventory_value after prior cost adjustment — authoritative read uses stored meta.
	 */
	public function test_drifted_inventory_value_meta_is_authoritative() {
		$product = $this->create_simple_product( array( 'stock_qty' => 50 ) );
		$this->seed_product_cost( $product, 50.0, 10.0, 505.0 );

		$result = WC_Inventory_Overview_Restock_Service::apply_outbound_line_change( $product->get_id(), 10 );
		$this->assertIsArray( $result );

		$m = $result['movement'];
		$this->assertDecimalEqual( 505.0, (float) $m['old_inventory_value'], 4 );
		$this->assertDecimalEqual( -100.0, (float) $m['total_value'], 4 );
		$this->assertDecimalEqual( 405.0, (float) $m['new_inventory_value'], 4 );
		$this->assertDecimalEqual( 40.0, (float) $m['new_stock'], 4 );
		$this->assertDecimalEqual( 10.125, (float) $m['new_average_unit_cost'], 6, 'new_average must be recomputed from value/stock, not copied from old_avg.' );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 10.125, $this->get_product_average_cost( $fresh ), 6 );
	}

	/**
	 * (c) Stock→0 edge: average and inventory value zeroed.
	 */
	public function test_stock_to_zero_zeros_average_and_value() {
		$product = $this->create_simple_product( array( 'stock_qty' => 3 ) );
		$this->seed_product_cost( $product, 3.0, 12.5 );

		$result = WC_Inventory_Overview_Restock_Service::apply_outbound_line_change( $product->get_id(), 3 );
		$this->assertIsArray( $result );

		$m = $result['movement'];
		$this->assertDecimalEqual( 0.0, (float) $m['new_stock'], 4 );
		$this->assertDecimalEqual( 0.0, (float) $m['new_inventory_value'], 4 );
		$this->assertDecimalEqual( 0.0, (float) $m['new_average_unit_cost'], 6 );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( 0.0, (float) $fresh->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( 0.0, $this->get_product_average_cost( $fresh ), 6 );
		$this->assertDecimalEqual( 0.0, $this->get_product_inventory_value( $fresh ), 4 );
	}

	/**
	 * (d) Rounding at 4/6 decimal boundaries — value_removed uses 4dp, average uses 6dp.
	 */
	public function test_rounding_boundaries_four_and_six_decimals() {
		$product = $this->create_simple_product( array( 'stock_qty' => 7 ) );
		$this->seed_product_cost( $product, 7.0, 3.333333 );

		$result = WC_Inventory_Overview_Restock_Service::apply_outbound_line_change( $product->get_id(), 2.3333 );
		$this->assertIsArray( $result );

		$m = $result['movement'];
		$this->assertDecimalEqual( (float) wc_format_decimal( 3.333333, 6 ), (float) $m['unit_cost'], 6 );
		$this->assertDecimalEqual( (float) $result['posting']['value_removed'], abs( (float) $m['total_value'] ), 4 );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertDecimalEqual( (float) $m['new_stock'], (float) $fresh->get_stock_quantity(), 4 );
		$this->assertDecimalEqual( (float) $m['new_inventory_value'], $this->get_product_inventory_value( $fresh ), 4 );
		$this->assertDecimalEqual( (float) $m['new_average_unit_cost'], $this->get_product_average_cost( $fresh ), 6 );
	}
}
