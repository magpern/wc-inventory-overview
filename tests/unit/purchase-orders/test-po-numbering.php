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
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

	/**
	 * Yearly rollover resets the per-year counter independently.
	 */
	public function test_yearly_rollover() {
		$this->assertSame( 'PO-2025-0001', WC_Inventory_Overview_PO_Numbering::allocate( 2025 ) );
		$this->assertSame( 'PO-2025-0002', WC_Inventory_Overview_PO_Numbering::allocate( 2025 ) );
		$this->assertSame( 'PO-2026-0001', WC_Inventory_Overview_PO_Numbering::allocate( 2026 ) );
		$this->assertSame( 'PO-2025-0003', WC_Inventory_Overview_PO_Numbering::allocate( 2025 ) );
	}

	/**
	 * Sequence growth past 9999 uses five-digit formatting.
	 */
	public function test_sequence_growth_past_9999() {
		update_option(
			WC_Inventory_Overview_PO_Numbering::OPTION_KEY,
			array( '2026' => 9999 ),
			false
		);
		$next = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );
		$this->assertSame( 'PO-2026-10000', $next );
	}

	/**
	 * Cancelled POs keep their number; gaps are expected.
	 */
	public function test_never_reuse_after_cancellation() {
		global $wpdb;

		$id   = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Acme',
			)
		);
		$po   = WC_Inventory_Overview_Purchase_Orders::get( $id );
		$used = $po['po_number'];

		// Simulate cancellation without M2-C lifecycle writes.
		$wpdb->update(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array( 'status' => WC_Inventory_Overview_PO_Statuses::CANCELLED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		$next = WC_Inventory_Overview_PO_Numbering::allocate();
		$this->assertNotEquals( $used, $next );
		$this->assertNotNull( WC_Inventory_Overview_Purchase_Orders::get_by_number( $used ) );
	}

	/**
	 * Duplicate-key collisions retry up to MAX_RETRIES then exhaust.
	 */
	public function test_duplicate_key_retry_and_exhaustion() {
		$existing  = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Seed',
			)
		);
		$po        = WC_Inventory_Overview_Purchase_Orders::get( $existing );
		$collision = $po['po_number'];

		$filter = static function () use ( $collision ) {
			return $collision;
		};
		add_filter( 'wc_io_po_number', $filter, 10, 3 );

		$result = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );
		remove_filter( 'wc_io_po_number', $filter, 10 );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_number_exhausted', $result->get_error_code() );

		// Sequence advanced MAX_RETRIES times despite exhaustion (gaps accepted).
		$map = get_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		$this->assertGreaterThanOrEqual( WC_Inventory_Overview_PO_Numbering::MAX_RETRIES, (int) $map['2026'] );
	}

	/**
	 * Empty filtered numbers are rejected without consuming infinite retries.
	 */
	public function test_empty_filtered_number_rejected() {
		add_filter(
			'wc_io_po_number',
			static function () {
				return '';
			}
		);
		$result = WC_Inventory_Overview_PO_Numbering::allocate( 2026 );
		remove_all_filters( 'wc_io_po_number' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_number_invalid', $result->get_error_code() );
	}
}
