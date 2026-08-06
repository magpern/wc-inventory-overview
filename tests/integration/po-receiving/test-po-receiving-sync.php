<?php
/**
 * M5: WC_Inventory_Overview_PO_Receiving_Sync end-to-end against a real PO/line —
 * both apply_line_delta() (receiving path) and reconcile_line() (reconciliation
 * path).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Sync extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * @param float $ordered Qty ordered.
	 * @return array{po:array,line:array} Placed PO + its line.
	 */
	private function placed_po_with_line( float $ordered = 10.0 ): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => $ordered,
					'unit_cost'   => 3,
				),
			)
		);
		$placed = WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 'placed', $placed['status'], is_wp_error( $placed ) ? '' : '' );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		return array( 'po' => $placed, 'line' => $lines[0] );
	}

	/**
	 * Single-line post: placed -> partially_received.
	 */
	public function test_apply_line_delta_post_partial() {
		$setup = $this->placed_po_with_line( 10.0 );
		$result = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta(
			(int) $setup['line']['id'], 6.0, 1, 'GR-2026-0001', $this->admin_id, false
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertEqualsWithDelta( 6.0, $result['new_qty_received'], 0.0001 );
		$this->assertSame( 'placed', $result['old_status'] );
		$this->assertSame( 'partially_received', $result['new_status'] );

		$po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'partially_received', $po['status'] );
	}

	/**
	 * Full-line post: placed -> received.
	 */
	public function test_apply_line_delta_post_full() {
		$setup  = $this->placed_po_with_line( 10.0 );
		$result = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta(
			(int) $setup['line']['id'], 10.0, 1, 'GR-2026-0001', $this->admin_id, false
		);
		$this->assertSame( 'received', $result['new_status'] );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		$types  = wp_list_pluck( $events, 'event_type' );
		$this->assertContains( 'po_line_received', $types );
		$this->assertContains( 'po_received', $types );
	}

	/**
	 * Second line, same PO: partially_received -> received once fully covered.
	 */
	public function test_second_line_same_po_completes_it() {
		$supplier = $this->create_supplier();
		$product1 = $this->create_simple_product();
		$product2 = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array( 'product_id' => $product1->get_id(), 'qty_ordered' => 5, 'unit_cost' => 1 ),
				array( 'product_id' => $product2->get_id(), 'qty_ordered' => 5, 'unit_cost' => 1 ),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		$r1 = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $lines[0]['id'], 5.0, 1, 'GR-1', $this->admin_id, false );
		$this->assertSame( 'partially_received', $r1['new_status'] );

		$r2 = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $lines[1]['id'], 5.0, 2, 'GR-2', $this->admin_id, false );
		$this->assertSame( 'received', $r2['new_status'] );
	}

	/**
	 * Void: received -> partially_received when this was one of two contributions.
	 */
	public function test_apply_line_delta_void_downgrades_status() {
		$setup = $this->placed_po_with_line( 10.0 );
		WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], 6.0, 1, 'GR-1', $this->admin_id, false );
		WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], 4.0, 2, 'GR-2', $this->admin_id, false );

		$po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'received', $po['status'] );

		$result = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], -6.0, 1, 'GR-1', $this->admin_id, true );
		$this->assertSame( 'received', $result['old_status'] );
		$this->assertSame( 'partially_received', $result['new_status'] );
		$this->assertEqualsWithDelta( 4.0, $result['new_qty_received'], 0.0001 );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		$types  = wp_list_pluck( $events, 'event_type' );
		$this->assertContains( 'po_line_receipt_voided', $types );
	}

	/**
	 * A void that fully zeroes qty_received back out downgrades all the way to
	 * placed, and — because the status didn't land on partially_received/
	 * received — writes no header-level status event, per design.
	 */
	public function test_full_void_downgrades_to_placed_no_header_event() {
		$setup = $this->placed_po_with_line( 10.0 );
		WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], 10.0, 1, 'GR-1', $this->admin_id, false );

		$before_event_count = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );

		$result = WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], -10.0, 1, 'GR-1', $this->admin_id, true );
		$this->assertSame( 'placed', $result['new_status'] );
		$this->assertEqualsWithDelta( 0.0, $result['new_qty_received'], 0.0001 );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		// Exactly one new event (the line-level void event) — no header status event.
		$this->assertCount( $before_event_count + 1, $events );
	}

	/**
	 * Header status write (and its event) is skipped as a no-op when the status
	 * doesn't actually change.
	 */
	public function test_no_status_change_writes_no_header_event() {
		$setup = $this->placed_po_with_line( 10.0 );
		WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], 4.0, 1, 'GR-1', $this->admin_id, false );
		$count_after_first = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );

		// Second partial receipt: still partially_received (status unchanged) — only one new line event, no header event.
		WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( (int) $setup['line']['id'], 2.0, 2, 'GR-2', $this->admin_id, false );
		$count_after_second = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );

		$this->assertSame( $count_after_first + 1, $count_after_second );
	}

	/**
	 * reconcile_line(): given a deliberately-drifted stored qty_received, computes
	 * the correct delta, writes through the same physical increment_qty_received()
	 * path, writes a po_qty_received_reconciled event (not po_line_received), and
	 * correctly recomputes header status exactly as apply_line_delta() would.
	 */
	public function test_reconcile_line_corrects_drift_and_recomputes_status() {
		$setup = $this->placed_po_with_line( 10.0 );

		// Simulate drift: directly increment without going through the normal receiving path.
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( (int) $setup['line']['id'], 4.0 );

		$result = WC_Inventory_Overview_PO_Receiving_Sync::reconcile_line( (int) $setup['line']['id'], 10.0, $this->admin_id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertEqualsWithDelta( 4.0, $result['old_qty_received'], 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $result['new_qty_received'], 0.0001 );
		$this->assertSame( 'received', $result['new_status'] );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		$types  = wp_list_pluck( $events, 'event_type' );
		$this->assertContains( 'po_qty_received_reconciled', $types );
		$this->assertNotContains( 'po_line_received', $types, 'A reconciliation must never be logged as a normal receiving event.' );
	}

	/**
	 * reconcile_line() with a delta of exactly zero (no drift) is a safe no-op —
	 * no physical write, no event.
	 */
	public function test_reconcile_line_no_drift_is_noop() {
		$setup = $this->placed_po_with_line( 10.0 );
		$count_before = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );

		$result = WC_Inventory_Overview_PO_Receiving_Sync::reconcile_line( (int) $setup['line']['id'], 0.0, $this->admin_id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertEqualsWithDelta( 0.0, $result['new_qty_received'], 0.0001 );

		$count_after = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );
		$this->assertSame( $count_before, $count_after );
	}
}
