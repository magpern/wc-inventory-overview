<?php
/**
 * Unit tests for WC_Inventory_Overview_Goods_Receipt_Numbering (M4).
 *
 * Mirrors tests/unit/purchase-orders/test-po-numbering.php's coverage:
 * format, never-reuse, collision-retry via the filter, year-bounded validation.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Numbering extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * Format is GR-{YYYY}-{NNNN}, zero-padded to 4 digits.
	 */
	public function test_format_is_gr_year_padded_sequence() {
		$this->assertSame( 'GR-2026-0001', WC_Inventory_Overview_Goods_Receipt_Numbering::format( 2026, 1 ) );
		$this->assertSame( 'GR-2026-0042', WC_Inventory_Overview_Goods_Receipt_Numbering::format( 2026, 42 ) );
		$this->assertSame( 'GR-2026-10000', WC_Inventory_Overview_Goods_Receipt_Numbering::format( 2026, 10000 ) );
	}

	/**
	 * Allocation advances the sequence and never allocates the same number twice.
	 */
	public function test_allocate_never_reuses() {
		$year = (int) gmdate( 'Y' );
		$a    = WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year );
		$this->assertIsString( $a );

		global $wpdb;
		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => $a,
				'status'         => 'draft',
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			)
		);

		$b = WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year );
		$this->assertIsString( $b );
		$this->assertNotSame( $a, $b );
	}

	/**
	 * A failed/abandoned draft's number is never recycled — the sequence only advances.
	 */
	public function test_sequence_never_decrements_on_unused_allocation() {
		$year = (int) gmdate( 'Y' );
		$next1 = WC_Inventory_Overview_Goods_Receipt_Numbering::peek_next_sequence( $year );
		WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year ); // Allocated but never persisted (simulates an abandoned draft).
		$next2 = WC_Inventory_Overview_Goods_Receipt_Numbering::peek_next_sequence( $year );
		$this->assertSame( $next1 + 1, $next2, 'Sequence must advance permanently even if the allocated number is never used.' );
	}

	/**
	 * Collision via the wc_io_gr_number filter forces a retry with a different number.
	 */
	public function test_collision_retry_via_filter() {
		$year = (int) gmdate( 'Y' );

		add_filter(
			'wc_io_gr_number',
			static function ( $number, $y, $seq ) {
				// Force every number to collide with a fixed value for the first two attempts.
				return 1 === $seq || 2 === $seq ? 'GR-COLLIDE' : $number;
			},
			10,
			3
		);

		global $wpdb;
		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => 'GR-COLLIDE',
				'status'         => 'draft',
				'created_at'     => current_time( 'mysql', true ),
				'updated_at'     => current_time( 'mysql', true ),
			)
		);

		$result = WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( $year );
		remove_all_filters( 'wc_io_gr_number' );

		$this->assertIsString( $result );
		$this->assertNotSame( 'GR-COLLIDE', $result );
	}

	/**
	 * Out-of-range years are rejected.
	 */
	public function test_year_out_of_range_rejected() {
		$result = WC_Inventory_Overview_Goods_Receipt_Numbering::allocate( 1999 );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_number_year', $result->get_error_code() );
	}
}
