<?php
/**
 * Integration tests for Milestone M11 — supplier on-time delivery rate,
 * against real Purchase Order / Goods Receipt fixture data.
 *
 * Covers: on-time (before/at/after deadline, inclusive boundary), unknown
 * confidence exclusion (both directions), missing expected date exclusion,
 * grace_days = 0 and > 0, multiple suppliers resolving independently,
 * rated_order_count smaller than M9's sample_count, "enough lead-time
 * history but insufficient rated history," archived suppliers, and explicit
 * regression checks that M9's own statistics, M10's suggestion service, and
 * PO_Delay's live delay result are all unaffected by this milestone.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_On_Time_Rate_Observations extends WC_Inventory_Overview_Test_Case {

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
		delete_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

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

	private function create_receipt_draft( array $lines ): int {
		$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $this->build_post_payload( $lines ) );
		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function post_receipt( int $id ): array {
		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	private function force_placed_at( int $po_id, string $placed_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'placed_at' => $placed_at ), array( 'id' => $po_id ) );
	}

	private function force_posted_at( int $receipt_id, string $posted_at ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'posted_at' => $posted_at ), array( 'id' => $receipt_id ) );
	}

	/**
	 * Builds a supplier, a placed PO (with an optional header expected_date/
	 * expected_confidence) for one product, and posts one receipt against it
	 * for the full quantity (transitioning the PO to 'received'). Forces
	 * deterministic placed_at/posted_at, matching M9's own fixture pattern.
	 *
	 * @param int         $supplier_id Supplier id.
	 * @param string      $placed_at   MySQL datetime for the PO.
	 * @param string      $posted_at   MySQL datetime for the receipt.
	 * @param string|null $expected_date Y-m-d, or null to leave unset.
	 * @param string|null $expected_confidence PO_Confidence value, or null to leave unset (defaults to 'unknown').
	 * @return array{po_id:int,receipt_id:int}
	 */
	private function received_po( int $supplier_id, string $placed_at, string $posted_at, ?string $expected_date = null, ?string $expected_confidence = null ): array {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$header = array( 'supplier_id' => $supplier_id );
		if ( null !== $expected_date ) {
			$header['expected_date'] = $expected_date;
		}
		if ( null !== $expected_confidence ) {
			$header['expected_confidence'] = $expected_confidence;
		}

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			$header,
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ) )
		);
		$this->assertIsInt( $po_id, is_wp_error( $po_id ) ? $po_id->get_error_message() : '' );
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
	// Scenarios 1-3: before / at / after deadline
	// -----------------------------------------------------------------

	public function test_received_before_deadline_is_on_time() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 1, $stats['rated_order_count'] );
		$this->assertSame( 1, $stats['on_time_count'] );
	}

	public function test_received_exactly_on_deadline_is_on_time_inclusive_boundary() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-08 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 1, $stats['rated_order_count'] );
		$this->assertSame( 1, $stats['on_time_count'], 'Completion on the deadline\'s own calendar day must count as on-time (inclusive boundary).' );
	}

	public function test_received_after_deadline_is_late() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-09 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 1, $stats['rated_order_count'] );
		$this->assertSame( 0, $stats['on_time_count'] );
	}

	// -----------------------------------------------------------------
	// Scenarios 4-5: EXACT and ESTIMATED confidence both eligible
	// -----------------------------------------------------------------

	public function test_exact_confidence_is_eligible() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertSame( 1, $stats['rated_order_count'] );
	}

	public function test_estimated_confidence_is_eligible() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::ESTIMATED );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
		$this->assertSame( 1, $stats['rated_order_count'] );
	}

	// -----------------------------------------------------------------
	// Scenarios 6-7: unknown confidence / missing date excluded
	// -----------------------------------------------------------------

	public function test_unknown_confidence_excluded_from_numerator_and_denominator() {
		$supplier = $this->create_supplier();
		// Would be "on time" by date alone, but confidence is unknown.
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::UNKNOWN );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertTrue( $stats['has_data'], 'Unknown-confidence orders still contribute to M9\'s own lead-time statistics.' );
		$this->assertSame( 1, $stats['sample_count'] );
		$this->assertSame( 0, $stats['rated_order_count'], 'Unknown confidence must never count toward rated_order_count.' );
		$this->assertSame( 0, $stats['on_time_count'], 'Unknown confidence must never count toward on_time_count.' );
	}

	public function test_missing_expected_date_excluded() {
		$supplier = $this->create_supplier();
		// No expected_date/expected_confidence set at all (defaults apply).
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00' );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 1, $stats['sample_count'] );
		$this->assertSame( 0, $stats['rated_order_count'] );
		$this->assertSame( 0, $stats['on_time_count'] );
	}

	// -----------------------------------------------------------------
	// Scenarios 8-9: grace days
	// -----------------------------------------------------------------

	public function test_grace_days_zero_uses_exact_deadline() {
		$supplier = $this->create_supplier();
		// One day late; grace_days = 0 means still late.
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-09 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'], 0 );

		$this->assertSame( 0, $stats['on_time_count'] );
	}

	public function test_grace_days_positive_changes_the_result() {
		$supplier = $this->create_supplier();
		// One day late; grace_days = 1 makes this on-time.
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-09 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats_no_grace = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'], 0 );
		$stats_grace    = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'], 1 );

		$this->assertSame( 0, $stats_no_grace['on_time_count'] );
		$this->assertSame( 1, $stats_grace['on_time_count'], 'A 1-day grace period must absorb a 1-day lateness.' );
	}

	// -----------------------------------------------------------------
	// Scenario 10: multiple suppliers resolve independently
	// -----------------------------------------------------------------

	public function test_multiple_suppliers_resolve_independently() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();

		// Supplier A: on time.
		$this->received_po( (int) $supplier_a['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );
		// Supplier B: late.
		$this->received_po( (int) $supplier_b['id'], '2026-01-01 00:00:00', '2026-01-12 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$bulk = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( array( (int) $supplier_a['id'], (int) $supplier_b['id'] ) );

		$this->assertSame( 1, $bulk[ (int) $supplier_a['id'] ]['on_time_count'] );
		$this->assertSame( 0, $bulk[ (int) $supplier_b['id'] ]['on_time_count'] );

		foreach ( array( $supplier_a, $supplier_b ) as $supplier ) {
			$single = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );
			$this->assertSame( $bulk[ (int) $supplier['id'] ], $single, 'Bulk and single results must be identical (M11 fields included).' );
		}
	}

	// -----------------------------------------------------------------
	// Scenario 11: rated_order_count smaller than M9's sample_count
	// -----------------------------------------------------------------

	public function test_rated_order_count_can_be_smaller_than_sample_count() {
		$supplier = $this->create_supplier();
		// One rated (known date), one unrated (unknown confidence) -- both
		// still count toward M9's sample_count.
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );
		$this->received_po( (int) $supplier['id'], '2026-02-01 00:00:00', '2026-02-05 00:00:00', '2026-02-08', WC_Inventory_Overview_PO_Confidence::UNKNOWN );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 2, $stats['sample_count'] );
		$this->assertSame( 1, $stats['rated_order_count'] );
	}

	// -----------------------------------------------------------------
	// Scenario 12: enough lead-time history, insufficient rated history
	// -----------------------------------------------------------------

	public function test_enough_lead_time_history_but_insufficient_rated_history() {
		$supplier = $this->create_supplier();
		// Two completed orders (meets MINIMUM_SAMPLE_COUNT_FOR_DISPLAY for
		// lead time), but only one has a known expected date (below the
		// threshold for the on-time rate).
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );
		$this->received_po( (int) $supplier['id'], '2026-02-01 00:00:00', '2026-02-05 00:00:00' );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertTrue( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $stats ), 'Lead-time figures must be usable (2 completed orders).' );
		$this->assertFalse( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats ), 'On-time rate must not be usable (only 1 rated order).' );
	}

	// -----------------------------------------------------------------
	// Scenario 13: archived supplier retains statistics
	// -----------------------------------------------------------------

	public function test_archived_supplier_retains_on_time_statistics() {
		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];

		$this->received_po( $supplier_id, '2026-01-01 00:00:00', '2026-01-05 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$before = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );
		$this->assertSame( 1, $before['on_time_count'] );

		$archived = WC_Inventory_Overview_Suppliers::archive( $supplier_id );
		$this->assertTrue( $archived );

		$after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id );
		$this->assertSame( $before, $after, 'Archiving a supplier must never change its on-time statistics.' );
	}

	// -----------------------------------------------------------------
	// Scenarios 14-16: regression -- M9, M10, PO_Delay unaffected
	// -----------------------------------------------------------------

	public function test_m9_observed_lead_time_output_is_unchanged_by_this_milestone() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-08 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'] );

		$this->assertSame( 7.0, $stats['average_days'] );
		$this->assertSame( 7, $stats['fastest_days'] );
		$this->assertSame( 7, $stats['slowest_days'] );
		$this->assertSame( 1, $stats['sample_count'] );
	}

	public function test_m10_expected_date_suggestion_service_output_is_unchanged_by_this_milestone() {
		$supplier = $this->create_supplier();
		$this->received_po( (int) $supplier['id'], '2026-01-01 00:00:00', '2026-01-08 00:00:00', '2026-01-08', WC_Inventory_Overview_PO_Confidence::EXACT );
		$this->received_po( (int) $supplier['id'], '2026-02-01 00:00:00', '2026-02-08 00:00:00', '2026-02-08', WC_Inventory_Overview_PO_Confidence::EXACT );

		$supplier_row = WC_Inventory_Overview_Suppliers::get( (int) $supplier['id'] );

		$before = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier_row );
		$this->assertSame( 'observed', $before['source'] );
		$this->assertSame( 7, $before['days'] );

		// Calling the M11-extended statistics service in between must not
		// change M10's own resolution.
		WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'], 5 );

		$after = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier_row );
		$this->assertSame( $before, $after, 'M10\'s suggestion output must be unchanged by M11.' );
	}

	public function test_po_delay_live_result_is_unchanged_by_this_milestone() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'supplier_id'         => (int) $supplier['id'],
				'expected_date'       => '2000-01-01',
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );

		$po    = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );

		$before = WC_Inventory_Overview_PO_Delay::is_po_delayed( $po['status'], $po['expected_date'], $po['expected_confidence'], $lines, 0 );
		$this->assertTrue( $before, 'Fixture precondition: a 2000-01-01 deadline must already be delayed.' );

		// Exercise the M11-extended statistics service in between.
		WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( (int) $supplier['id'], 3 );

		$after = WC_Inventory_Overview_PO_Delay::is_po_delayed( $po['status'], $po['expected_date'], $po['expected_confidence'], $lines, 0 );
		$this->assertSame( $before, $after, 'PO_Delay\'s live result must be unchanged by M11.' );
	}
}
