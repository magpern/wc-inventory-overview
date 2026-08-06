<?php
/**
 * M5: WC_Inventory_Overview_Goods_Receipt_Service::post() with po_line_id-bearing
 * lines — happy path, forced-failure rollback (the single most important test in
 * this milestone), mixed receipts, multi-PO receipts, over-receipt.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Service_PO_Linked_Post extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * @param float $ordered Qty ordered.
	 * @return array{po:array,line:array,product:WC_Product_Simple}
	 */
	private function placed_po_with_line( float $ordered = 10.0 ): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => $ordered, 'unit_cost' => 3 ) )
		);
		$placed = WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines  = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		return array( 'po' => $placed, 'line' => $lines[0], 'product' => $product );
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
			'wc_io_gr_currency'         => 'EUR',
			'wc_io_gr_line_product'     => $product_ids,
			'wc_io_gr_line_qty'         => $qtys,
			'wc_io_gr_line_unit_cost'   => $costs,
			'wc_io_gr_line_po_line_id'  => $po_line_ids,
		);
	}

	/**
	 * @param array $lines Lines.
	 * @return int Draft receipt id.
	 */
	private function create_draft( array $lines ): int {
		$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $this->build_post_payload( $lines ) );
		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * @param int $id Receipt id.
	 * @return array|WP_Error
	 */
	private function post_receipt( int $id ) {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		return WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
	}

	/**
	 * Happy path: qty_received and PO status update inside the same commit as
	 * stock/cost.
	 */
	public function test_po_linked_post_updates_qty_received_and_status() {
		$setup = $this->placed_po_with_line( 10.0 );
		$id    = $this->create_draft(
			array( array( 'product_id' => $setup['product']->get_id(), 'qty' => 6, 'unit_cost' => 3, 'po_line_id' => (int) $setup['line']['id'] ) )
		);

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh_product = wc_get_product( $setup['product']->get_id() );
		$this->assertEqualsWithDelta( 6.0, (float) $fresh_product->get_stock_quantity(), 0.0001 );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $setup['line']['id'] );
		$this->assertEqualsWithDelta( 6.0, (float) $fresh_line['qty_received'], 0.0001 );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'partially_received', $fresh_po['status'] );
	}

	/**
	 * Forced-failure rollback — the single most important test in this milestone
	 * (mirrors M4's own highest-priority rollback test, one level up the stack):
	 * two PO-linked lines against the same PO; line 2's product is sabotaged
	 * (stock management disabled, mirroring M4's exact proven technique) after
	 * draft creation, so Restock_Service::apply_purchase_line_change() fails on
	 * line 2 — after line 1's stock mutation AND its PO_Receiving_Sync call
	 * (qty_received + PO status) have already completed inside the same open
	 * transaction. The whole transaction must roll back: line 1's stock, its
	 * qty_received credit, and the PO's status change all revert together, not
	 * just the stock half.
	 *
	 * (A deleted-PO-line scenario was deliberately not used here: the pre-
	 * transaction validation step already rejects that case before any
	 * transaction opens at all — see test-po-receiving-validation.php — so it
	 * cannot exercise an in-transaction rollback; this scenario can.)
	 */
	public function test_forced_second_line_failure_rolls_back_first_lines_po_sync_too() {
		$setup = $this->placed_po_with_line( 20.0 );
		$sabotaged_product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		// create_draft()/place() in placed_po_with_line() already legitimately
		// wrote (and committed) their own PO events (po_created, po_placed) — the
		// event-count assertion below must compare against this baseline, not
		// against zero, or it would incorrectly count those as "survivors" of the
		// forced rollback.
		$events_before_post_attempt = count( WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] ) );

		$id = $this->create_draft(
			array(
				array( 'product_id' => $setup['product']->get_id(), 'qty' => 6, 'unit_cost' => 3, 'po_line_id' => (int) $setup['line']['id'] ),
				array( 'product_id' => $sabotaged_product->get_id(), 'qty' => 5, 'unit_cost' => 12 ),
			)
		);

		// Sabotage line 2's product after draft creation: disable stock management.
		$sabotage = wc_get_product( $sabotaged_product->get_id() );
		$sabotage->set_manage_stock( false );
		$sabotage->save();

		$result = $this->post_receipt( $id );
		$this->assertTrue( is_wp_error( $result ), 'Posting must fail when a line product can no longer be mutated.' );

		$fresh_product = wc_get_product( $setup['product']->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh_product->get_stock_quantity(), 0.0001, 'Line 1\'s stock mutation must be fully rolled back.' );
		$this->assertNull( $this->get_product_average_cost( $fresh_product ), 'Line 1\'s cost meta must be unset after rollback.' );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $setup['line']['id'] );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh_line['qty_received'], 0.0001, 'Line 1\'s qty_received credit must roll back together with its stock mutation — not left partially applied.' );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'placed', $fresh_po['status'], 'The PO\'s status change must roll back too — it must not be left "partially_received" for a receipt that never actually posted.' );

		global $wpdb;
		$movement_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $movement_count, 'No movement row may survive a rolled-back post.' );

		$po_events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		$this->assertCount( $events_before_post_attempt, $po_events, 'No new PO event may survive a rolled-back post — the line event and the header event must both roll back.' );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'draft', $receipt['status'], 'The receipt must remain draft after a rolled-back post.' );
	}

	/**
	 * Mixed receipt: one PO-linked line + one direct line — only the PO-linked
	 * one touches qty_received; source is 'mixed'.
	 */
	public function test_mixed_receipt_only_po_linked_line_touches_qty_received() {
		$setup        = $this->placed_po_with_line( 10.0 );
		$direct_product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$id = $this->create_draft(
			array(
				array( 'product_id' => $setup['product']->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => (int) $setup['line']['id'] ),
				array( 'product_id' => $direct_product->get_id(), 'qty' => 2, 'unit_cost' => 5 ),
			)
		);

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'mixed', $receipt['source'] );

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $setup['line']['id'] );
		$this->assertEqualsWithDelta( 4.0, (float) $fresh_line['qty_received'], 0.0001 );

		$fresh_direct = wc_get_product( $direct_product->get_id() );
		$this->assertEqualsWithDelta( 2.0, (float) $fresh_direct->get_stock_quantity(), 0.0001 );
	}

	/**
	 * A pure direct receipt (no po_line_id anywhere) still has source 'direct' —
	 * M4's Quick Receive path is unmodified.
	 */
	public function test_pure_direct_receipt_source_unchanged() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 3, 'unit_cost' => 2 ) ) );
		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'direct', $receipt['source'] );
	}

	/**
	 * Multi-PO receipt: lines from two different POs in one receipt, both POs'
	 * statuses update independently and correctly.
	 */
	public function test_multi_po_receipt_updates_both_pos_independently() {
		$setup1 = $this->placed_po_with_line( 10.0 );
		$setup2 = $this->placed_po_with_line( 4.0 );

		$id = $this->create_draft(
			array(
				array( 'product_id' => $setup1['product']->get_id(), 'qty' => 10, 'unit_cost' => 3, 'po_line_id' => (int) $setup1['line']['id'] ),
				array( 'product_id' => $setup2['product']->get_id(), 'qty' => 4, 'unit_cost' => 3, 'po_line_id' => (int) $setup2['line']['id'] ),
			)
		);
		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$po1 = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup1['po']['id'] );
		$po2 = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup2['po']['id'] );
		$this->assertSame( 'received', $po1['status'] );
		$this->assertSame( 'received', $po2['status'] );
	}

	/**
	 * Over-receipt: qty > outstanding is never rejected (D5); PO status still
	 * correctly reaches 'received', qty_outstanding floors at 0 via GREATEST, and
	 * the event carries over_receipt:true.
	 */
	public function test_over_receipt_allowed_warned_and_audited() {
		$setup = $this->placed_po_with_line( 5.0 );
		$id    = $this->create_draft(
			array( array( 'product_id' => $setup['product']->get_id(), 'qty' => 8, 'unit_cost' => 3, 'po_line_id' => (int) $setup['line']['id'] ) )
		);

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( (int) $setup['line']['id'] );
		$this->assertEqualsWithDelta( 8.0, (float) $fresh_line['qty_received'], 0.0001, 'qty_received legitimately exceeds qty_ordered on over-receipt.' );
		$this->assertEqualsWithDelta( 0.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $fresh_line ), 0.0001, 'Outstanding floors at zero, never negative.' );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'received', $fresh_po['status'] );

		$events = WC_Inventory_Overview_PO_Events::list_by_po( (int) $setup['po']['id'] );
		$line_event = null;
		foreach ( $events as $event ) {
			if ( 'po_line_received' === $event['event_type'] ) {
				$line_event = $event;
				break;
			}
		}
		$this->assertNotNull( $line_event );
		$data = json_decode( (string) $line_event['data'], true );
		$this->assertTrue( $data['over_receipt'] );
		$this->assertEqualsWithDelta( 3.0, (float) $data['qty_over'], 0.0001 );
	}
}
