<?php
/**
 * M2-A: repository + event infrastructure + architecture guards.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * PO architecture guard tests.
 */
class Test_WC_IO_PO_Architecture extends WP_UnitTestCase {

	/**
	 * Reset schema and sequence before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Status vocabulary is exactly four M2 values.
	 */
	public function test_status_vocabulary_is_exactly_four() {
		$all = WC_Inventory_Overview_PO_Statuses::all();
		$this->assertCount( 4, $all );
		$this->assertEquals(
			array( 'draft', 'placed', 'cancelled', 'closed_short' ),
			$all
		);
		$this->assertFalse( WC_Inventory_Overview_PO_Statuses::is_valid( 'partially_received' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Statuses::is_valid( 'received' ) );
	}

	/**
	 * Cancelled and closed_short are terminal.
	 */
	public function test_terminal_statuses() {
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_terminal( 'cancelled' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_terminal( 'closed_short' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Statuses::is_terminal( 'draft' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Statuses::is_terminal( 'placed' ) );
	}

	/**
	 * Lines have no qty_received; outstanding uses ordered − cancelled.
	 */
	public function test_lines_and_outstanding_without_qty_received() {
		$po_id   = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Acme',
			)
		);
		$line_id = WC_Inventory_Overview_Purchase_Order_Lines::create(
			$po_id,
			array(
				'product_id'    => 10,
				'qty_ordered'   => 5,
				'qty_cancelled' => 2,
				'unit_cost'     => 1.5,
			)
		);
		$this->assertIsInt( $line_id );

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertArrayNotHasKey( 'qty_received', $line );
		$this->assertEquals( 3.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ) );
	}

	/**
	 * Events API is append-only (no update/delete methods).
	 */
	public function test_events_are_append_only_api() {
		$methods = get_class_methods( 'WC_Inventory_Overview_PO_Events' );
		$this->assertContains( 'add', $methods );
		$this->assertContains( 'list_for_po', $methods );
		$this->assertContains( 'get', $methods );
		$this->assertNotContains( 'update', $methods );
		$this->assertNotContains( 'delete', $methods );
		$this->assertNotContains( 'remove', $methods );
	}

	/**
	 * Events store optional reason_code and JSON data.
	 */
	public function test_event_add_with_reason_code() {
		$po_id = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Acme',
			)
		);

		$event_id = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'       => $po_id,
				'event_type'  => WC_Inventory_Overview_PO_Events::TYPE_CREATED,
				'summary'     => 'Draft created',
				'reason_code' => WC_Inventory_Overview_PO_Reason_Codes::MANUAL,
				'data'        => array( 'source' => 'test' ),
			)
		);
		$this->assertIsInt( $event_id );

		$event = WC_Inventory_Overview_PO_Events::get( $event_id );
		$this->assertEquals( 'po_created', $event['event_type'] );
		$this->assertEquals( 'manual', $event['reason_code'] );
		$this->assertStringContainsString( 'source', (string) $event['data'] );
	}

	/**
	 * Unknown reason codes are rejected.
	 */
	public function test_event_rejects_unknown_reason_code() {
		$po_id = WC_Inventory_Overview_Purchase_Orders::create_draft(
			array(
				'supplier_id'            => 1,
				'supplier_name_snapshot' => 'Acme',
			)
		);

		$result = WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'       => $po_id,
				'event_type'  => 'po_edited',
				'reason_code' => 'not_a_real_code',
			)
		);
		$this->assertWPError( $result );
		$this->assertEquals( 'wc_io_po_invalid_reason_code', $result->get_error_code() );
	}

	/**
	 * Lifecycle service methods remain stubs in M2-A.
	 */
	public function test_service_lifecycle_stubs_not_implemented() {
		$this->assertWPError( WC_Inventory_Overview_PO_Service::place( 1 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Service::cancel( 1 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Service::close_short( 1 ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Service::duplicate( 1 ) );
	}

	/**
	 * Receiving event types are not declared in M2.
	 */
	public function test_no_receiving_event_types_declared() {
		$types = WC_Inventory_Overview_PO_Events::known_types();
		$this->assertNotContains( 'po_receipt_linked', $types );
		$this->assertNotContains( 'po_line_received', $types );
	}

	/**
	 * Maybe_upgrade bumps db version to 7 and writes canonical assertion.
	 */
	public function test_maybe_upgrade_sets_db_version_7() {
		update_option( 'wc_io_db_version', '6' );
		WC_Inventory_Overview_Install::maybe_upgrade();
		$this->assertEquals( '7', get_option( 'wc_io_db_version' ) );
		$assertion = get_option( 'wc_io_schema_assertion' );
		$this->assertTrue( $assertion['ok'] );
		$this->assertEquals( '7', $assertion['version'] );
	}
}
