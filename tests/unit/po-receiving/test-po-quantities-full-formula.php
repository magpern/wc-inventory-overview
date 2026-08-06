<?php
/**
 * M5: PO_Quantities full INV-4 formula (ordered − received − cancelled).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Quantities_Formula extends WP_UnitTestCase {

	/**
	 * received=0 must reproduce the exact pre-M5 2-arg answer — regression baseline.
	 */
	public function test_received_zero_matches_pre_m5_baseline() {
		$this->assertSame( 3.0, WC_Inventory_Overview_PO_Quantities::outstanding( 5, 0, 2 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 2, 0, 5 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 0, 0, 0 ) );
	}

	/**
	 * received reduces outstanding exactly like cancelled.
	 */
	public function test_received_reduces_outstanding() {
		$this->assertSame( 4.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 6, 0 ) );
		$this->assertSame( 2.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 4, 4 ) );
	}

	/**
	 * Over-receipt (received + cancelled > ordered) floors at zero, never negative (D5).
	 */
	public function test_over_receipt_floors_at_zero() {
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 15, 0 ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_PO_Quantities::outstanding( 10, 8, 5 ) );
	}

	/**
	 * Result is rounded to 4 decimals (numeric precision convention).
	 */
	public function test_result_rounded_to_four_decimals() {
		$this->assertSame( 0.3334, WC_Inventory_Overview_PO_Quantities::outstanding( 1, 0.33333, 0.33327 ) );
	}
}
