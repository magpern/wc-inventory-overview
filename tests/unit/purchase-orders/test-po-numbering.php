<?php
/**
 * M2-A: PO numbering architecture tests.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * PO numbering tests.
 */
class Test_WC_IO_PO_Numbering extends WP_UnitTestCase {

	/**
	 * Reset schema and sequence before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Format zero-pads to four digits.
	 */
	public function test_format_zero_pads_to_four_digits() {
		$this->assertEquals( 'PO-2026-0001', WC_Inventory_Overview_PO_Numbering::format( 2026, 1 ) );
		$this->assertEquals( 'PO-2026-0042', WC_Inventory_Overview_PO_Numbering::format( 2026, 42 ) );
		$this->assertEquals( 'PO-2026-9999', WC_Inventory_Overview_PO_Numbering::format( 2026, 9999 ) );
	}

	/**
	 * Format grows past four digits naturally.
	 */
	public function test_format_grows_past_four_digits() {
		$this->assertEquals( 'PO-2026-10000', WC_Inventory_Overview_PO_Numbering::format( 2026, 10000 ) );
	}

	/**
	 * Allocate advances the per-year sequence.
	 */
	public function test_allocate_advances_sequence() {
		$a = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );
		$b = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );

		$this->assertEquals( 'PO-2026-0001', $a );
		$this->assertEquals( 'PO-2026-0002', $b );
	}

	/**
	 * Sequences are independent per calendar year.
	 */
	public function test_sequences_are_per_year() {
		$a = WC_Inventory_Overview_PO_Numbering::allocate( 2025 );
		$b = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );

		$this->assertEquals( 'PO-2025-0001', $a );
		$this->assertEquals( 'PO-2026-0001', $b );
	}

	/**
	 * Deleting a draft never recycles its PO number.
	 */
	public function test_never_reuse_after_draft_delete() {
		$id = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Acme',
			)
		);
		$this->assertIsInt( $id );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $id );
		$this->assertEquals( 'draft', $po['status'] );
		$used_number = $po['po_number'];

		$deleted = WC_Inventory_Overview_Purchase_Orders::delete_draft( $id );
		$this->assertTrue( $deleted );

		$next = WC_Inventory_Overview_PO_Numbering::allocate();
		$this->assertNotEquals( $used_number, $next );
		$this->assertMatchesRegularExpression( '/^PO-\d{4}-\d+$/', $next );
	}

	/**
	 * Create_draft persists an allocated number on a draft row.
	 */
	public function test_create_draft_persists_allocated_number() {
		$id = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 7,
				'supplier_name_snapshot' => 'Nordic Labs',
				'currency'               => 'EUR',
			)
		);
		$this->assertIsInt( $id );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $id );
		$this->assertMatchesRegularExpression( '/^PO-\d{4}-\d{4,}$/', $po['po_number'] );
		$this->assertEquals( 'draft', $po['status'] );
		$this->assertNull( $po['placed_at'] );
		$this->assertNull( $po['closed_at'] );
	}
}
