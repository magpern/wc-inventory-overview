<?php
/**
 * Integration tests for WC_Inventory_Overview_Goods_Receipt_Costing (M4).
 *
 * Landed-cost allocation (remainder-to-last-line, zero/negative subtotal guard)
 * and the weighted-average preview formula (zero-stock, existing-stock, and the
 * plan's permanent worked-example characterization).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Costing extends WC_Inventory_Overview_Test_Case {

	/**
	 * Allocation splits proportionally by line value, remainder absorbed by the last line.
	 */
	public function test_allocate_landed_proportional_remainder_to_last_line() {
		$allocations = WC_Inventory_Overview_Goods_Receipt_Costing::allocate_landed(
			array( 33.33, 33.33, 33.34 ),
			10.0,
			100.0
		);
		$this->assertCount( 3, $allocations );
		$this->assertEqualsWithDelta( 3.333, $allocations[0], 0.001 );
		$this->assertEqualsWithDelta( 3.333, $allocations[1], 0.001 );

		$sum = array_sum( $allocations );
		$this->assertEqualsWithDelta( 10.0, $sum, 0.0001, 'Allocations must sum exactly to the landed total (remainder absorbs rounding drift).' );
	}

	/**
	 * Zero landed cost passes through as zero allocation on every line.
	 */
	public function test_allocate_landed_zero_when_no_landed_cost() {
		$allocations = WC_Inventory_Overview_Goods_Receipt_Costing::allocate_landed( array( 50.0, 50.0 ), 0.0, 100.0 );
		$this->assertSame( array( 0.0, 0.0 ), $allocations );
	}

	/**
	 * Product-subtotal-zero-or-negative guard is enforced at the preview-building
	 * level (build_preview_from_post), not the low-level allocate_landed() helper.
	 */
	public function test_preview_rejects_landed_cost_with_zero_product_subtotal() {
		$product = $this->create_simple_product();
		$src = array(
			'wc_io_gr_currency'        => 'EUR',
			'wc_io_gr_line_product'    => array( $product->get_id() ),
			'wc_io_gr_line_qty'        => array( '1' ),
			'wc_io_gr_line_unit_cost'  => array( '0' ),
			'wc_io_gr_cost_type'       => array( 'shipping' ),
			'wc_io_gr_cost_amount'     => array( '10' ),
			'wc_io_gr_cost_currency'   => array( 'EUR' ),
		);

		$result = WC_Inventory_Overview_Goods_Receipt_Costing::build_preview_from_post( $src );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_allocate', $result->get_error_code() );
	}

	/**
	 * preview_line(): receiving into zero/never-set inventory — average degenerates
	 * to exactly the receipt's own true unit cost.
	 */
	public function test_preview_line_zero_stock_case() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$preview = WC_Inventory_Overview_Goods_Receipt_Costing::preview_line( $product, 5, 12.0 );

		$this->assertEqualsWithDelta( 0.0, $preview['old_stock'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $preview['new_stock'], 0.0001 );
		$this->assertEqualsWithDelta( 12.0, $preview['new_average_unit_cost'], 0.000001 );
		$this->assertEqualsWithDelta( 60.0, $preview['new_inventory_value'], 0.0001 );
	}

	/**
	 * preview_line(): the plan's permanent worked example.
	 * Starting 10 @ 8.00 -> receive 5 @ 12.00 -> new average 9.333333.
	 */
	public function test_preview_line_worked_example() {
		$product = $this->create_simple_product( array( 'stock_qty' => 10 ) );
		$this->set_product_average_cost( $product, 8.0 );
		$this->set_product_inventory_value( $product, 80.0 );
		$product = wc_get_product( $product->get_id() );

		$preview = WC_Inventory_Overview_Goods_Receipt_Costing::preview_line( $product, 5, 12.0 );

		$this->assertEqualsWithDelta( 15.0, $preview['new_stock'], 0.0001 );
		$this->assertEqualsWithDelta( 140.0, $preview['new_inventory_value'], 0.0001 );
		$this->assertEqualsWithDelta( 9.333333, $preview['new_average_unit_cost'], 0.000001 );
	}

	/**
	 * build_preview_from_post() rejects a variable-parent product line.
	 */
	public function test_preview_rejects_variable_parent() {
		$parent = $this->create_variable_product();
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $parent->get_id() ),
			'wc_io_gr_line_qty'       => array( '1' ),
			'wc_io_gr_line_unit_cost' => array( '5' ),
		);
		$result = WC_Inventory_Overview_Goods_Receipt_Costing::build_preview_from_post( $src );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_type', $result->get_error_code() );
	}

	/**
	 * Same product twice in one receipt is allowed — no uniqueness constraint.
	 */
	public function test_preview_allows_duplicate_product_lines() {
		$product = $this->create_simple_product();
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id(), $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '2', '3' ),
			'wc_io_gr_line_unit_cost' => array( '10', '10' ),
		);
		$result = WC_Inventory_Overview_Goods_Receipt_Costing::build_preview_from_post( $src );
		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['lines'] );
	}
}
