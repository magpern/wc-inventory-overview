<?php
/**
 * M5: WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received() in
 * isolation — the sole physical writer of qty_received.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Lines_Increment extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
	}

	/**
	 * Additive delta increments the column by exactly the given amount.
	 */
	public function test_additive_delta() {
		$po   = $this->create_purchase_order();
		$line = $this->add_po_line( (int) $po['id'], array( 'qty_ordered' => 10 ) );

		$affected = WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], 4.0 );
		$this->assertSame( 1, $affected );

		$fresh = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEqualsWithDelta( 4.0, (float) $fresh['qty_received'], 0.0001 );
	}

	/**
	 * Negative delta (void) decrements the column.
	 */
	public function test_negative_delta() {
		$po   = $this->create_purchase_order();
		$line = $this->add_po_line( (int) $po['id'], array( 'qty_ordered' => 10 ) );

		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], 6.0 );
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], -6.0 );

		$fresh = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh['qty_received'], 0.0001 );
	}

	/**
	 * Two sequential deltas sum correctly — the same atomic UPDATE ... SET x = x
	 * + %f semantics that make this safe under concurrent transactions without
	 * row locking (M5 plan §Idempotency & concurrency).
	 */
	public function test_sequential_deltas_sum_correctly() {
		$po   = $this->create_purchase_order();
		$line = $this->add_po_line( (int) $po['id'], array( 'qty_ordered' => 20 ) );

		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], 5.0 );
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], 3.0 );
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $line['id'], -2.0 );

		$fresh = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEqualsWithDelta( 6.0, (float) $fresh['qty_received'], 0.0001 );
	}

	/**
	 * A non-existent line id affects zero rows (the caller's job to interpret
	 * that as an error — this method itself just reports rows affected).
	 */
	public function test_nonexistent_line_affects_zero_rows() {
		$affected = WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( 999999999, 1.0 );
		$this->assertSame( 0, $affected );
	}

	/**
	 * This is the only place in the codebase writing wc_io_purchase_order_lines
	 * .qty_received — confirmed here at the integration level by verifying that
	 * ordinary line create()/update() calls never populate it away from its
	 * default (the architecture-guard test asserts the same fact by source scan;
	 * this asserts it behaviorally).
	 */
	public function test_create_and_update_never_populate_qty_received() {
		$po   = $this->create_purchase_order();
		$line = $this->add_po_line( (int) $po['id'], array( 'qty_ordered' => 10 ) );
		$this->assertEqualsWithDelta( 0.0, (float) $line['qty_received'], 0.0001 );

		WC_Inventory_Overview_Purchase_Order_Lines::update( (int) $line['id'], array( 'qty_received' => 999 ) );
		$fresh = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $line['id'] );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh['qty_received'], 0.0001, 'update() must silently ignore qty_received — it is not in the allowed whitelist.' );
	}
}
