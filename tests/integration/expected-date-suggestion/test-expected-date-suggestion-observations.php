<?php
/**
 * Integration tests for Milestone M10 — Purchase Order Expected-Date
 * Suggestion, against real Supplier / Purchase Order / Goods Receipt
 * fixture data.
 *
 * Covers: real observed-average suggestion end-to-end; configured-only
 * fallback; no-suggestion state; observed winning over configured when
 * both are present; multiple suppliers via the bulk path in one pass; and
 * that this milestone never changes Supplier_Lead_Time_Service's own
 * (M9) output.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Date_Suggestion_Observations extends WC_Inventory_Overview_Test_Case {

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
	 * Same technique as tests/integration/supplier-lead-time's own suite --
	 * truncate every table this suite writes to, so numbering/sequence
	 * resets stay unique and no cross-test fixture leakage occurs.
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
	 * @param int    $po_id     PO id.
	 * @param string $placed_at MySQL datetime.
	 */
	private function force_placed_at( int $po_id, string $placed_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'placed_at' => $placed_at ), array( 'id' => $po_id ) );
	}

	/**
	 * @param int    $receipt_id Receipt id.
	 * @param string $posted_at  MySQL datetime.
	 */
	private function force_posted_at( int $receipt_id, string $posted_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'posted_at' => $posted_at ), array( 'id' => $receipt_id ) );
	}

	/**
	 * Builds a supplier, a placed PO for one product, and posts one receipt
	 * against it for the full quantity (transitioning the PO to 'received'
	 * via PO_Receiving_Sync). Forces deterministic placed_at/posted_at.
	 * Same technique as tests/integration/supplier-lead-time's own suite.
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
	// Observed suggestion, end-to-end
	// -----------------------------------------------------------------

	public function test_observed_average_used_when_supplier_has_enough_completed_orders() {
		$supplier    = $this->create_supplier( array( 'default_lead_time_days' => 20 ) );
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-06 00:00:00' ); // 5 days
		$this->received_po( $supplier_id, '2026-02-01 00:00:00', '2026-02-11 00:00:00' ); // 10 days

		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );

		$this->assertSame( 8, $suggestion['days'], 'Rounded average of 5 and 10 is 7.5, rounds to 8 (matches M9\'s own (int) round() convention).' );
		$this->assertSame( WC_Inventory_Overview_PO_Confidence::ESTIMATED, $suggestion['confidence'] );
		$this->assertSame( 'observed', $suggestion['source'], 'Observed must win over the supplier\'s own configured 20-day fallback once enough history exists.' );
		$this->assertSame( 2, $suggestion['sample_count'], 'M16: evidence sample_count must match the two completed orders.' );
		$this->assertSame( 8, $suggestion['average_days'], 'M16: evidence average_days must use the same (int) round() convention as days.' );
	}

	// -----------------------------------------------------------------
	// Configured fallback
	// -----------------------------------------------------------------

	public function test_configured_lead_time_used_when_supplier_has_no_completed_orders() {
		$supplier = $this->create_supplier( array( 'default_lead_time_days' => 14 ) );

		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );

		$this->assertSame( 14, $suggestion['days'] );
		$this->assertSame( WC_Inventory_Overview_PO_Confidence::ESTIMATED, $suggestion['confidence'] );
		$this->assertSame( 'configured', $suggestion['source'] );
		$this->assertNull( $suggestion['sample_count'] );
		$this->assertNull( $suggestion['average_days'] );
	}

	/**
	 * A single completed order is below MINIMUM_SAMPLE_COUNT_FOR_DISPLAY --
	 * must fall back to configured, exactly like the no-history case,
	 * proving is_observed_value_usable() (not a locally-duplicated
	 * threshold) actually governs this path end-to-end.
	 */
	public function test_configured_lead_time_used_when_observed_sample_count_is_below_threshold() {
		$supplier    = $this->create_supplier( array( 'default_lead_time_days' => 11 ) );
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-21 00:00:00' ); // 20 days, only 1 sample

		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );

		$this->assertSame( 11, $suggestion['days'] );
		$this->assertSame( 'configured', $suggestion['source'] );
	}

	// -----------------------------------------------------------------
	// No suggestion
	// -----------------------------------------------------------------

	public function test_no_suggestion_when_supplier_has_neither_observed_nor_configured() {
		$supplier = $this->create_supplier( array( 'default_lead_time_days' => 0 ) );

		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );

		$this->assertNull( $suggestion['days'] );
		$this->assertNull( $suggestion['confidence'] );
		$this->assertSame( 'none', $suggestion['source'] );
	}

	// -----------------------------------------------------------------
	// Bulk path, multiple suppliers
	// -----------------------------------------------------------------

	public function test_bulk_path_resolves_each_supplier_independently() {
		$observed_supplier   = $this->create_supplier( array( 'default_lead_time_days' => 99 ) );
		$configured_supplier = $this->create_supplier( array( 'default_lead_time_days' => 6 ) );
		$no_data_supplier    = $this->create_supplier( array( 'default_lead_time_days' => 0 ) );

		$observed_id = (int) $observed_supplier['id'];
		$this->received_po( $observed_id, '2026-01-01 00:00:00', '2026-01-11 00:00:00' ); // 10 days
		$this->received_po( $observed_id, '2026-02-01 00:00:00', '2026-02-11 00:00:00' ); // 10 days

		$suppliers = array( $observed_supplier, $configured_supplier, $no_data_supplier );
		$bulk      = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers );

		$this->assertSame( 10, $bulk[ $observed_id ]['days'] );
		$this->assertSame( 'observed', $bulk[ $observed_id ]['source'] );
		$this->assertSame( 2, $bulk[ $observed_id ]['sample_count'] );
		$this->assertSame( 10, $bulk[ $observed_id ]['average_days'] );

		$this->assertSame( 6, $bulk[ (int) $configured_supplier['id'] ]['days'] );
		$this->assertSame( 'configured', $bulk[ (int) $configured_supplier['id'] ]['source'] );
		$this->assertNull( $bulk[ (int) $configured_supplier['id'] ]['sample_count'] );
		$this->assertNull( $bulk[ (int) $configured_supplier['id'] ]['average_days'] );

		$this->assertNull( $bulk[ (int) $no_data_supplier['id'] ]['days'] );
		$this->assertSame( 'none', $bulk[ (int) $no_data_supplier['id'] ]['source'] );
		$this->assertNull( $bulk[ (int) $no_data_supplier['id'] ]['sample_count'] );
		$this->assertNull( $bulk[ (int) $no_data_supplier['id'] ]['average_days'] );

		foreach ( $suppliers as $supplier ) {
			$single = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );
			$this->assertSame( $bulk[ (int) $supplier['id'] ], $single, "Bulk and single results must be identical for supplier {$supplier['id']}." );
		}
	}

	// -----------------------------------------------------------------
	// M9 regression: this milestone must never change Supplier_Lead_Time_Service's own output
	// -----------------------------------------------------------------

	public function test_supplier_lead_time_service_output_is_unchanged_by_this_milestone() {
		$supplier    = $this->create_supplier( array( 'default_lead_time_days' => 15 ) );
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-11 00:00:00' );
		$this->received_po( $supplier_id, '2026-02-01 00:00:00', '2026-02-11 00:00:00' );

		$before = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );

		// Exercise the new service in between -- must not mutate anything
		// Supplier_Lead_Time_Service subsequently reports.
		WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );

		$after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );

		$this->assertSame( $before, $after, 'Supplier_Lead_Time_Service\'s own output must be byte-for-byte unchanged by M10 calling into it.' );
		$this->assertTrue( $before['has_data'] );
		$this->assertSame( 10.0, $before['average_days'] );
	}
}
