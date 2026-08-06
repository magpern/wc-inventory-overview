<?php
/**
 * Integration tests for WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal() (M4).
 *
 * Isolated from Goods_Receipt_Service: exact subtraction math, negative-stock
 * guard, zero-stock-after-void, average re-derivation.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Restock_Service_Reversal extends WC_Inventory_Overview_Test_Case {

	/**
	 * Straightforward reversal subtracts exactly this line's own qty/value.
	 */
	public function test_reversal_subtracts_exact_delta() {
		$product = $this->create_simple_product( array( 'stock_qty' => 15 ) );
		$this->set_product_average_cost( $product, 9.333333 );
		$this->set_product_inventory_value( $product, 140.0 );
		$product = wc_get_product( $product->get_id() );

		// Reverse the 5 @ 12.00 receipt from the worked example.
		$result = WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal( $product->get_id(), 5.0, 60.0 );
		$this->assertIsArray( $result );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 10.0, (float) $after->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 80.0, $this->get_product_inventory_value( $after ), 0.0001 );
		$this->assertEqualsWithDelta( 8.0, $this->get_product_average_cost( $after ), 0.000001 );
	}

	/**
	 * Reversing to exactly zero stock/value is allowed and floors the average at 0.0.
	 */
	public function test_reversal_to_zero_stock() {
		$product = $this->create_simple_product( array( 'stock_qty' => 5 ) );
		$this->set_product_average_cost( $product, 12.0 );
		$this->set_product_inventory_value( $product, 60.0 );
		$product = wc_get_product( $product->get_id() );

		$result = WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal( $product->get_id(), 5.0, 60.0 );
		$this->assertIsArray( $result );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $after->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 0.0, $this->get_product_average_cost( $after ), 0.0001 );
	}

	/**
	 * Void is rejected when the resulting stock would be negative (units sold since posting).
	 */
	public function test_reversal_rejected_when_insufficient_stock() {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$this->set_product_average_cost( $product, 12.0 );
		$this->set_product_inventory_value( $product, 24.0 );
		$product = wc_get_product( $product->get_id() );

		// This receipt's line contributed 5 units, but only 2 remain (3 were sold since posting).
		$result = WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal( $product->get_id(), 5.0, 60.0 );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_void_insufficient_stock', $result->get_error_code() );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 2.0, (float) $after->get_stock_quantity(), 0.0001, 'Rejected reversal must not mutate stock at all.' );
	}

	/**
	 * The critical intervening-receipt scenario: post A, post B (same product), void
	 * A — B's contribution must remain exactly intact. This is Restock_Service-level
	 * isolation of the same regression covered end-to-end in
	 * Test_WC_IO_Goods_Receipt_Service_Void.
	 */
	public function test_reversal_preserves_other_receipts_contribution() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		// Receipt A: 10 @ 8.00.
		WC_Inventory_Overview_Restock_Service::apply_purchase_line_change( $product->get_id(), 10, 8.0 );
		// Receipt B: 5 @ 12.00.
		WC_Inventory_Overview_Restock_Service::apply_purchase_line_change( $product->get_id(), 5, 12.0 );

		$mid = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 15.0, (float) $mid->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 140.0, $this->get_product_inventory_value( $mid ), 0.0001 );

		// Void A: reverse only A's 10 @ 8.00 = 80.00.
		$reversal = WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal( $product->get_id(), 10.0, 80.0 );
		$this->assertIsArray( $reversal );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 5.0, (float) $after->get_stock_quantity(), 0.0001, 'Only A\'s 10 units should be removed, leaving B\'s 5.' );
		$this->assertEqualsWithDelta( 60.0, $this->get_product_inventory_value( $after ), 0.0001, 'Only A\'s 80.00 value should be removed, leaving B\'s 60.00.' );
		$this->assertEqualsWithDelta( 12.0, $this->get_product_average_cost( $after ), 0.000001, 'Remaining average must reflect only B\'s contribution (60/5=12).' );
	}

	/**
	 * Reversal is rejected for a variable parent product.
	 */
	public function test_reversal_rejects_variable_parent() {
		$parent = $this->create_variable_product();
		$result = WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal( $parent->get_id(), 1.0, 1.0 );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_type', $result->get_error_code() );
	}
}
