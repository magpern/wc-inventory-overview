<?php
/**
 * Integration tests for Milestone M9 — observed supplier lead-time
 * computation, against real Purchase Order / Goods Receipt fixture data.
 *
 * Covers: correct average/fastest/slowest/sample-count; exclusion of
 * closed_short and still-open (placed/partially_received) POs; exclusion of
 * voided receipts; exclusion of migrated (M6) receipts; exclusion of direct
 * receipts without a po_line_id; "latest qualifying receipt" selection
 * across partial-then-final receiving; insertion-order independence
 * (MAX(posted_at), never MAX(id)); bulk/single consistency; archived
 * suppliers.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Lead_Time_Observations extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Truncate every table this suite writes to, so numbering/sequence
	 * resets stay unique and no cross-test fixture leakage occurs (same
	 * technique as the M7 performance suite).
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array<int,array{product_id:int,qty:mixed,unit_cost:mixed,po_line_id?:int}> $lines Lines.
	 */
	private function build_post_payload( array $lines ): array {
		$product_ids = array();
		$qtys        = array();
		$costs       = array();
		$po_line_ids = array();
		foreach ( $lines as $line ) {
			$product_ids[] = $line['product_id'];
			$qtys[]        = $line['qty'];
			$costs[]       = $line['unit_cost'];
			$po_line_ids[] = $line['po_line_id'] ?? 0;
		}
		return array(
			'wc_io_gr_currency'        => 'EUR',
			'wc_io_gr_line_product'    => $product_ids,
			'wc_io_gr_line_qty'        => $qtys,
			'wc_io_gr_line_unit_cost'  => $costs,
			'wc_io_gr_line_po_line_id' => $po_line_ids,
		);
	}

	/**
	 * @param array $lines Lines (see build_post_payload()).
	 * @return int Draft receipt id.
	 */
	private function create_receipt_draft( array $lines ): int {
		$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $this->build_post_payload( $lines ) );
		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * @param int $id Receipt id.
	 * @return array
	 */
	private function post_receipt( int $id ): array {
		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * Force a Purchase Order's placed_at to an exact value, bypassing
	 * place()'s current_time() default, so day-difference assertions are
	 * deterministic rather than depending on wall-clock test run time.
	 *
	 * @param int    $po_id     PO id.
	 * @param string $placed_at MySQL datetime.
	 */
	private function force_placed_at( int $po_id, string $placed_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'placed_at' => $placed_at ), array( 'id' => $po_id ) );
	}

	/**
	 * Force a Goods Receipt's posted_at to an exact value, bypassing
	 * post()'s current_time() default, for the same reason.
	 *
	 * @param int    $receipt_id Receipt id.
	 * @param string $posted_at  MySQL datetime.
	 */
	private function force_posted_at( int $receipt_id, string $posted_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'posted_at' => $posted_at ), array( 'id' => $receipt_id ) );
	}

	/**
	 * Builds a supplier, a placed PO for one product with the given ordered
	 * quantity, and posts one receipt against it for the full quantity
	 * (transitioning the PO to 'received' via PO_Receiving_Sync). Forces
	 * deterministic placed_at/posted_at.
	 *
	 * @param int    $supplier_id Supplier id.
	 * @param string $placed_at   MySQL datetime for the PO.
	 * @param string $posted_at   MySQL datetime for the receipt.
	 * @return array{po_id:int,receipt_id:int}
	 */
	private function received_po( int $supplier_id, string $placed_at, string $posted_at ): array {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id   = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => $supplier_id ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		$receipt_id = $this->create_receipt_draft(
			array(
				array(
					'product_id' => $product->get_id(),
					'qty'        => 5,
					'unit_cost'  => 3,
					'po_line_id' => $lines[0]['id'],
				),
			)
		);
		$this->post_receipt( $receipt_id );

		$this->force_placed_at( $po_id, $placed_at );
		$this->force_posted_at( $receipt_id, $posted_at );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( WC_Inventory_Overview_PO_Statuses::RECEIVED, $po['status'] );

		return array(
			'po_id'      => $po_id,
			'receipt_id' => $receipt_id,
		);
	}

	// -----------------------------------------------------------------
	// Correct average / fastest / slowest / sample count
	// -----------------------------------------------------------------

	public function test_single_completed_po_computes_correct_days() {
		$supplier = $this->create_supplier();

		$this->received_po( (int) $supplier['id'], '2026-01-01 09:00:00', '2026-01-08 09:00:00' );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertTrue( $stats['has_data'] );
		$this->assertSame( 7.0, $stats['average_days'] );
		$this->assertSame( 7, $stats['fastest_days'] );
		$this->assertSame( 7, $stats['slowest_days'] );
		$this->assertSame( 1, $stats['sample_count'] );
	}

	public function test_average_fastest_slowest_across_multiple_completed_pos() {
		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-06 00:00:00' ); // 5 days
		$this->received_po( $supplier_id, '2026-02-01 00:00:00', '2026-02-11 00:00:00' ); // 10 days
		$this->received_po( $supplier_id, '2026-03-01 00:00:00', '2026-03-16 00:00:00' ); // 15 days

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );

		$this->assertTrue( $stats['has_data'] );
		$this->assertSame( 10.0, $stats['average_days'] );
		$this->assertSame( 5, $stats['fastest_days'] );
		$this->assertSame( 15, $stats['slowest_days'] );
		$this->assertSame( 3, $stats['sample_count'] );
	}

	public function test_statistics_are_scoped_per_supplier_not_mixed_across_suppliers() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();

		$this->received_po( (int) $supplier_a['id'], '2026-01-01 00:00:00', '2026-01-06 00:00:00' ); // 5 days
		$this->received_po( (int) $supplier_b['id'], '2026-01-01 00:00:00', '2026-01-21 00:00:00' ); // 20 days

		$stats_a = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier_a['id'] );
		$stats_b = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier_b['id'] );

		$this->assertSame( 5.0, $stats_a['average_days'] );
		$this->assertSame( 20.0, $stats_b['average_days'] );
	}

	// -----------------------------------------------------------------
	// Exclusions
	// -----------------------------------------------------------------

	public function test_excludes_still_placed_po_with_no_receipt() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertFalse( $stats['has_data'] );
		$this->assertSame( 0, $stats['sample_count'] );
	}

	public function test_excludes_partially_received_po() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		// Only 4 of 10 received -- PO stays 'partially_received', never 'received'.
		$receipt_id = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $receipt_id );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED, $po['status'] );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertFalse( $stats['has_data'] );
	}

	public function test_excludes_closed_short_po() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		$receipt_id = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $receipt_id );

		$closed = WC_Inventory_Overview_PO_Service::close_short( $po_id );
		$this->assertIsArray( $closed, is_wp_error( $closed ) ? $closed->get_error_message() : '' );
		$this->assertSame( WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT, $closed['status'] );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertFalse( $stats['has_data'], 'A closed_short PO must never be counted as a completed order.' );
	}

	public function test_excludes_voided_receipt() {
		$supplier = $this->create_supplier();
		$fixture  = $this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-11 00:00:00' );

		$stats_before = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertTrue( $stats_before['has_data'] );

		// Force the receipt's status to 'voided' directly (isolating the
		// gr.status = 'posted' filter itself) -- voiding through the normal
		// Goods_Receipt_Service::void() path would also downgrade the PO's
		// own status via PO_Receiving_Sync, which would exclude the
		// observation through the po.status filter instead and not prove
		// this filter is doing independent work.
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'status' => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED ), array( 'id' => $fixture['receipt_id'] ) );

		$stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertFalse( $stats_after['has_data'], 'A voided receipt must never contribute an observation.' );
	}

	public function test_excludes_migrated_receipt() {
		$supplier = $this->create_supplier();
		$fixture  = $this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-11 00:00:00' );

		$stats_before = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertTrue( $stats_before['has_data'] );

		// A real M6-migrated receipt never carries a po_line_id (it predates
		// the PO/receiving flow), so the join alone already excludes it.
		// Forcing source='migrated' here on an otherwise PO-linked receipt
		// proves the explicit belt-and-suspenders exclusion (plan §6) also
		// holds on its own, independent of the join condition.
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'source' => WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED ), array( 'id' => $fixture['receipt_id'] ) );

		$stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertFalse( $stats_after['has_data'], 'A migrated (M6) receipt must never contribute an observation.' );
	}

	public function test_excludes_direct_receipt_without_po_line_id() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		// Quick Receive Without PO -- po_line_id is 0/NULL by construction,
		// nothing to compute a lead time against.
		$receipt_id = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 3 ) )
		);
		$this->post_receipt( $receipt_id );

		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'supplier_id' => (int) $supplier['id'] ), array( 'id' => $receipt_id ) );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertFalse( $stats['has_data'], 'A direct receipt with no po_line_id must never contribute an observation.' );
	}

	// -----------------------------------------------------------------
	// "Latest qualifying receipt" — never the first
	// -----------------------------------------------------------------

	public function test_partial_then_final_receipt_uses_the_final_receipts_date() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		// First (partial) receipt: 4 units, posted quickly.
		$first = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $first );

		// Final receipt completing the order, posted much later.
		$second = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 6, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $second );

		$this->force_placed_at( $po_id, '2026-01-01 00:00:00' );
		$this->force_posted_at( $first, '2026-01-04 00:00:00' ); // Would wrongly give 3 days if the first receipt were used.
		$this->force_posted_at( $second, '2026-01-21 00:00:00' ); // Correct: 20 days -- the receipt that actually completed the order.

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( WC_Inventory_Overview_PO_Statuses::RECEIVED, $po['status'] );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertTrue( $stats['has_data'] );
		$this->assertSame( 20.0, $stats['average_days'], 'Must use the final (completing) receipt, never the first partial receipt.' );
		$this->assertSame( 1, $stats['sample_count'] );
	}

	/**
	 * The insertion-order-independence invariant: the computed lead time
	 * must depend only on posted_at values, never on receipt row ID or
	 * insertion order. Constructs a fixture where the receipt with the
	 * later posted_at is inserted (and posted) FIRST, receiving the LOWER
	 * auto-increment ID, while the receipt with the earlier posted_at is
	 * inserted SECOND, receiving the HIGHER ID -- the exact reverse of
	 * insertion order vs. posted_at order. An implementation that
	 * accidentally used MAX(id) or "last inserted" instead of
	 * MAX(posted_at) would select the second (higher-ID) receipt's earlier
	 * date and silently under-report the lead time.
	 */
	public function test_insertion_order_never_substitutes_for_posted_at() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		// Inserted FIRST -> lowest ID. Will be forced to the LATEST posted_at.
		$inserted_first = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $inserted_first );

		// Inserted SECOND -> highest ID (completes the PO). Will be forced
		// to the EARLIEST posted_at -- the reverse of insertion order.
		$inserted_second = $this->create_receipt_draft(
			array( array( 'product_id' => $product->get_id(), 'qty' => 6, 'unit_cost' => 3, 'po_line_id' => $lines[0]['id'] ) )
		);
		$this->post_receipt( $inserted_second );

		$this->assertGreaterThan( $inserted_first, $inserted_second, 'Fixture precondition: the second receipt must have the higher ID.' );

		$this->force_placed_at( $po_id, '2026-01-01 00:00:00' );
		$this->force_posted_at( $inserted_first, '2026-01-20 00:00:00' ); // Lowest ID, LATEST date -- 19 days.
		$this->force_posted_at( $inserted_second, '2026-01-05 00:00:00' ); // Highest ID, EARLIEST date -- 4 days.

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertTrue( $stats['has_data'] );
		$this->assertSame(
			19.0,
			$stats['average_days'],
			'Must select MAX(posted_at) (19 days, the lowest-ID receipt), never MAX(id)/last-inserted (which would wrongly give 4 days).'
		);
	}

	// -----------------------------------------------------------------
	// Bulk / single consistency
	// -----------------------------------------------------------------

	public function test_bulk_matches_single_for_every_supplier() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();
		$supplier_c = $this->create_supplier(); // No history at all.

		$this->received_po( (int) $supplier_a['id'], '2026-01-01 00:00:00', '2026-01-06 00:00:00' );
		$this->received_po( (int) $supplier_b['id'], '2026-01-01 00:00:00', '2026-01-16 00:00:00' );

		$ids  = array( (int) $supplier_a['id'], (int) $supplier_b['id'], (int) $supplier_c['id'] );
		$bulk = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( $ids );

		foreach ( $ids as $id ) {
			$single = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $id );
			$this->assertSame( $bulk[ $id ], $single, "Bulk and single results must be identical for supplier {$id}." );
		}
	}

	// -----------------------------------------------------------------
	// Zero observations / archived suppliers
	// -----------------------------------------------------------------

	public function test_brand_new_supplier_returns_not_enough_data_state() {
		$supplier = $this->create_supplier();

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertFalse( $stats['has_data'] );
		$this->assertNull( $stats['average_days'] );
		$this->assertNull( $stats['fastest_days'] );
		$this->assertNull( $stats['slowest_days'] );
		$this->assertSame( 0, $stats['sample_count'] );
	}

	public function test_archived_supplier_still_returns_its_historical_statistics() {
		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-11 00:00:00' );

		$before = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );
		$this->assertTrue( $before['has_data'] );

		$archived = WC_Inventory_Overview_Suppliers::archive( $supplier_id );
		$this->assertTrue( $archived );

		$after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );
		$this->assertSame( $before, $after, 'Archiving a supplier must never change its observed lead-time statistics -- historical purchasing evidence is untouched by archiving.' );
	}
}
