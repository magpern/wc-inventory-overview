<?php
/**
 * Integration tests for WC_Inventory_Overview_Goods_Receipts header repository (M4).
 *
 * Persistence only: CRUD, compare-and-swap status transitions. Draft-only edit
 * policy is enforced by Goods_Receipt_Service, not the repository — these tests
 * exercise the repository directly.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipts_Repository extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * create_draft() allocates a unique receipt number and persists a draft header.
	 */
	public function test_create_draft_allocates_number_and_status() {
		$id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$this->assertIsInt( $id );

		$row = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertIsArray( $row );
		$this->assertSame( 'draft', $row['status'] );
		$this->assertSame( 'direct', $row['source'] );
		$this->assertStringStartsWith( 'GR-', $row['receipt_number'] );
	}

	/**
	 * update_fields() persists header changes.
	 */
	public function test_update_fields_persists_changes() {
		$id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$updated = WC_Inventory_Overview_Goods_Receipts::update_fields( $id, array( 'reference' => 'INV-123', 'note' => 'test note' ) );
		$this->assertTrue( $updated );

		$row = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'INV-123', $row['reference'] );
		$this->assertSame( 'test note', $row['note'] );
	}

	/**
	 * compare_and_swap_post() only succeeds from draft, and only once.
	 */
	public function test_compare_and_swap_post_is_conditional() {
		$id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );

		$affected = WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, 1 );
		$this->assertSame( 1, $affected );

		$row = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'posted', $row['status'] );
		$this->assertNotEmpty( $row['posted_at'] );
		$this->assertEquals( 1, $row['posted_by'] );

		// Second call: no longer draft, must affect zero rows.
		$second = WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, 1 );
		$this->assertSame( 0, $second );
	}

	/**
	 * compare_and_swap_void() only succeeds from posted, and only once, and persists the reason.
	 */
	public function test_compare_and_swap_void_is_conditional() {
		$id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );

		// Cannot void a draft.
		$this->assertSame( 0, WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id, 1, 'oops' ) );

		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, 1 );

		$affected = WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id, 2, 'damaged goods' );
		$this->assertSame( 1, $affected );

		$row = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'voided', $row['status'] );
		$this->assertSame( 'damaged goods', $row['void_reason'] );
		$this->assertEquals( 2, $row['voided_by'] );

		// Second void attempt: no longer posted.
		$this->assertSame( 0, WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id, 2, 'again' ) );
	}

	/**
	 * receipt_number is never touched by voiding — it remains attached forever.
	 */
	public function test_receipt_number_survives_void() {
		$id  = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$row = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$number = $row['receipt_number'];

		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, 1 );
		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id, 1, 'reason' );

		$after = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( $number, $after['receipt_number'] );
	}

	/**
	 * delete_draft_record() removes the header row.
	 */
	public function test_delete_draft_record() {
		$id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$this->assertTrue( WC_Inventory_Overview_Goods_Receipts::delete_draft_record( $id ) );
		$this->assertWPError( WC_Inventory_Overview_Goods_Receipts::get( $id ) );
	}

	/**
	 * list()/count() filter by status and search.
	 */
	public function test_list_and_count_filter_by_status() {
		$a = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR', 'reference' => 'AAA' ) );
		$b = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR', 'reference' => 'BBB' ) );
		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $b, 1 );

		$this->assertSame( 2, WC_Inventory_Overview_Goods_Receipts::count() );
		$this->assertSame( 1, WC_Inventory_Overview_Goods_Receipts::count( array( 'status' => 'draft' ) ) );
		$this->assertSame( 1, WC_Inventory_Overview_Goods_Receipts::count( array( 'status' => 'posted' ) ) );

		$search = WC_Inventory_Overview_Goods_Receipts::list( array( 'search' => 'AAA' ) );
		$this->assertCount( 1, $search );
		$this->assertSame( $a, (int) $search[0]['id'] );
	}
}
