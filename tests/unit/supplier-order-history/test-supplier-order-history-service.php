<?php
/**
 * M14: WC_Inventory_Overview_Supplier_Order_History_Service.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit test class naming convention.

/**
 * Pagination math, status inclusion, and value-formula correctness.
 */
class Test_WC_IO_Supplier_Order_History_Service extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema/sequence and clear PO + supplier rows for isolation.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Truncate PO aggregate tables and suppliers so numbering/sequence resets stay unique.
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * A supplier with zero POs returns an empty page and count 0, using
	 * exactly one query (count() only -- list()/values_bulk() skipped).
	 */
	public function test_zero_pos_returns_empty_page_with_one_query() {
		global $wpdb;
		$supplier = $this->create_supplier();

		$before = $wpdb->num_queries;
		$page   = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'] );
		$after  = $wpdb->num_queries;

		$this->assertSame( array(), $page['rows'] );
		$this->assertSame( 0, $page['count'] );
		$this->assertSame( 1, $page['page'] );
		$this->assertSame( 1, $after - $before, 'Zero-history supplier must cost exactly one query.' );
	}

	/**
	 * A non-empty page costs exactly three queries: count(), list(),
	 * values_bulk() -- no N+1 regardless of row count within the page.
	 */
	public function test_non_empty_page_costs_exactly_three_queries() {
		global $wpdb;
		$supplier = $this->create_supplier();
		for ( $i = 0; $i < 3; $i++ ) {
			$po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
			$this->add_po_line( $po['id'] );
		}

		$before = $wpdb->num_queries;
		$page   = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'] );
		$after  = $wpdb->num_queries;

		$this->assertCount( 3, $page['rows'] );
		$this->assertSame( 3, $page['count'] );
		$this->assertSame( 3, $after - $before, 'Non-empty page must cost exactly three queries.' );
	}

	/**
	 * Pagination math: page 2 with per_page 2 of 5 total POs returns the
	 * remaining 3rd/4th newest rows (offset = (page-1)*per_page = 2).
	 */
	public function test_pagination_offset_math() {
		$supplier = $this->create_supplier();
		$dates    = array( '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05' );
		foreach ( $dates as $date ) {
			$this->create_purchase_order(
				array(
					'supplier_id' => $supplier['id'],
					'order_date'  => $date,
				)
			);
		}

		$page1 = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 1, 2 );
		$page2 = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 2, 2 );
		$page3 = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 3, 2 );

		$this->assertSame( 5, $page1['count'] );
		$this->assertCount( 2, $page1['rows'] );
		$this->assertCount( 2, $page2['rows'] );
		$this->assertCount( 1, $page3['rows'] );

		// Newest order_date first across the whole sequence.
		$this->assertSame( '2026-01-05', $page1['rows'][0]['order_date'] );
		$this->assertSame( '2026-01-04', $page1['rows'][1]['order_date'] );
		$this->assertSame( '2026-01-03', $page2['rows'][0]['order_date'] );
		$this->assertSame( '2026-01-02', $page2['rows'][1]['order_date'] );
		$this->assertSame( '2026-01-01', $page3['rows'][0]['order_date'] );
	}

	/**
	 * An out-of-range page (count > 0 but past the last page) degrades
	 * safely: no error, an empty row set for that page, and the correct
	 * total count still reported.
	 */
	public function test_out_of_range_page_degrades_safely() {
		$supplier = $this->create_supplier();
		$this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );

		$page = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 99, 20 );

		$this->assertSame( array(), $page['rows'] );
		$this->assertSame( 1, $page['count'] );
	}

	/**
	 * Page/per-page inputs are clamped to safe defaults: page < 1 becomes 1,
	 * per_page <= 0 falls back to DEFAULT_PER_PAGE.
	 */
	public function test_page_and_per_page_are_clamped() {
		$supplier = $this->create_supplier();
		$this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );

		$zero_page = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 0 );
		$neg_page  = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], -5 );
		$zero_pp   = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 1, 0 );

		$this->assertSame( 1, $zero_page['page'] );
		$this->assertSame( 1, $neg_page['page'] );
		$this->assertSame( WC_Inventory_Overview_Supplier_Order_History_Service::DEFAULT_PER_PAGE, $zero_pp['per_page'] );
	}

	/**
	 * History is status-inclusive (INV-M14-4): draft, cancelled, and
	 * closed_short POs all appear alongside placed/received ones -- nothing
	 * is filtered out by status, unlike M13's print feature.
	 */
	public function test_history_includes_every_status() {
		$supplier = $this->create_supplier();
		$statuses = array(
			WC_Inventory_Overview_PO_Statuses::DRAFT,
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
			WC_Inventory_Overview_PO_Statuses::CANCELLED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);
		foreach ( $statuses as $status ) {
			$po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
			WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => $status ) );
		}

		$page = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'], 1, 20 );

		$this->assertSame( 6, $page['count'] );
		$found_statuses = array_column( $page['rows'], 'status' );
		sort( $found_statuses );
		$expected = $statuses;
		sort( $expected );
		$this->assertSame( $expected, $found_statuses );
	}

	/**
	 * Ordered/received value per row matches the hand-computed fixture and
	 * carries that PO's own currency -- never blended with any other row.
	 */
	public function test_row_values_and_currency_match_fixture() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'USD',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 4,
				'unit_cost'   => 12.5,
			)
		);
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po['id'] );
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $lines[0]['id'], 2 );

		$page = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'] );
		$row  = $page['rows'][0];

		$this->assertSame( 'USD', $row['currency'] );
		$this->assertDecimalEqual( 50.0, $row['ordered_value'] );
		$this->assertDecimalEqual( 25.0, $row['received_value'] );
	}

	/**
	 * A PO with no lines yet (e.g. a bare draft) reports zero/zero rather
	 * than a missing key or an error -- values_bulk()'s "absent means
	 * zero" contract is bridged correctly by the service.
	 */
	public function test_po_with_no_lines_reports_zero_values() {
		$supplier = $this->create_supplier();
		$this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );

		$page = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier['id'] );
		$row  = $page['rows'][0];

		$this->assertSame( 0.0, $row['ordered_value'] );
		$this->assertSame( 0.0, $row['received_value'] );
	}
}
