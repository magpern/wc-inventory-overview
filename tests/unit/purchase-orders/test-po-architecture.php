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
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Status vocabulary is exactly six values as of M5 (the original four M2
	 * statuses plus the two auto-transitioned M5 receiving statuses). Superseded
	 * from the pre-M5 "exactly four, partially_received/received invalid" claim —
	 * M5 implementation plan §Testing "M4 guard-revision audit".
	 */
	public function test_status_vocabulary_is_exactly_six() {
		$all = WC_Inventory_Overview_PO_Statuses::all();
		$this->assertCount( 6, $all );
		$this->assertEquals(
			array( 'draft', 'placed', 'partially_received', 'received', 'cancelled', 'closed_short' ),
			$all
		);
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_valid( 'partially_received' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Statuses::is_valid( 'received' ) );
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
	 * Lines now carry a real, maintained qty_received column (schema v9, M5);
	 * outstanding uses the full INV-4 formula (ordered − received − cancelled).
	 * Superseded from the pre-M5 "no qty_received column" claim — M5
	 * implementation plan §Testing "M4 guard-revision audit". qty_received
	 * defaults to 0 on a freshly-created line (it is never operator-settable
	 * through create()/update() — only WC_Inventory_Overview_Purchase_Order_Lines
	 * ::increment_qty_received() writes it), so the outstanding figure here is
	 * unchanged from the pre-M5 baseline.
	 */
	public function test_lines_and_outstanding_with_qty_received_column() {
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
		$this->assertArrayHasKey( 'qty_received', $line );
		$this->assertEquals( 0.0, (float) $line['qty_received'] );
		$this->assertEquals( 3.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ) );
	}

	/**
	 * Events API is append-only (no generic update/delete methods).
	 *
	 * Delete_for_draft_aggregate() is allowed only as draft hard-delete cleanup;
	 * it is not a generic mutation path.
	 */
	public function test_events_are_append_only_api() {
		$methods = get_class_methods( 'WC_Inventory_Overview_PO_Events' );
		$this->assertContains( 'add', $methods );
		$this->assertContains( 'list_for_po', $methods );
		$this->assertContains( 'list_by_po', $methods );
		$this->assertContains( 'count_by_po', $methods );
		$this->assertContains( 'get', $methods );
		$this->assertContains( 'get_latest', $methods );
		$this->assertNotContains( 'update', $methods );
		$this->assertNotContains( 'delete', $methods );
		$this->assertNotContains( 'remove', $methods );
		$this->assertContains( 'delete_for_draft_aggregate', $methods );

		$forbidden = array( 'update', 'delete', 'remove', 'erase', 'truncate' );
		foreach ( $forbidden as $name ) {
			$this->assertFalse(
				method_exists( 'WC_Inventory_Overview_PO_Events', $name ),
				"PO_Events must not expose generic {$name}()"
			);
		}
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
	 * Lifecycle service methods are implemented in M2-C (missing PO → not-found, not stub).
	 */
	public function test_service_lifecycle_methods_are_implemented() {
		$this->assertFalse(
			method_exists( 'WC_Inventory_Overview_PO_Service', 'not_implemented' ),
			'PO_Service must not retain M2-A not_implemented stub'
		);

		$missing = WC_Inventory_Overview_PO_Service::place( 999999 );
		$this->assertWPError( $missing );
		$this->assertSame( 'wc_io_po_not_found', $missing->get_error_code() );
	}

	/**
	 * Receiving event types are not declared in M2.
	 */
	/**
	 * M5 revision (not silently broken — M5 implementation plan §Testing "M4
	 * guard-revision audit"): po_line_received is now a real, legitimate M5 event
	 * type, written only by PO_Receiving_Sync. 'po_receipt_linked' — a name never
	 * actually used by any milestone's implementation — remains correctly absent.
	 */
	public function test_receiving_event_types_declared_only_by_m5() {
		$types = WC_Inventory_Overview_PO_Events::known_types();
		$this->assertNotContains( 'po_receipt_linked', $types );
		$this->assertContains( 'po_line_received', $types );
		$this->assertContains( 'po_line_receipt_voided', $types );
		$this->assertContains( 'po_partially_received', $types );
		$this->assertContains( 'po_received', $types );
		$this->assertContains( 'po_qty_received_reconciled', $types );
	}

	/**
	 * Maybe_upgrade bumps db version to the current DB_VERSION and writes canonical
	 * assertion. Was hardcoded to '7' pre-M4; M4 bumped DB_VERSION to '8', so an
	 * upgrade from a stale v6 option now jumps straight to v8 (no intermediate stop).
	 */
	public function test_maybe_upgrade_sets_current_db_version() {
		update_option( 'wc_io_db_version', '6' );
		WC_Inventory_Overview_Install::maybe_upgrade();
		$this->assertEquals( WC_Inventory_Overview_Install::DB_VERSION, get_option( 'wc_io_db_version' ) );
		$assertion = get_option( 'wc_io_schema_assertion' );
		$this->assertTrue( $assertion['ok'] );
		$this->assertEquals( WC_Inventory_Overview_Install::DB_VERSION, $assertion['version'] );
	}
}
