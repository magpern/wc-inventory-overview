<?php
/**
 * Integration tests for Milestone M12 — Supplier List Performance Surface.
 *
 * Seeds real PO/GR history and asserts list-table cells follow the same
 * Supplier_Lead_Time_Service usability policy as the detail screen.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_List_Performance_Integration extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
		delete_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	private function purge_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

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

	private function received_po( int $supplier_id, string $placed_at, string $posted_at, ?string $expected_date = null, ?string $expected_confidence = null ): void {
		global $wpdb;
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
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'placed_at' => $placed_at ), array( 'id' => $po_id ) );

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$draft = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			$this->build_post_payload(
				array(
					array(
						'product_id' => $product->get_id(),
						'qty'        => 5,
						'unit_cost'  => 3,
						'po_line_id' => (int) $lines[0]['id'],
					),
				)
			)
		);
		$this->assertIsInt( $draft, is_wp_error( $draft ) ? $draft->get_error_message() : '' );
		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft, $token );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$wpdb->update( WC_Inventory_Overview_Goods_Receipts::table_name(), array( 'posted_at' => $posted_at ), array( 'id' => $draft ) );
	}

	/**
	 * @return WC_Inventory_Overview_Suppliers_List_Table
	 */
	private function prepared_table(): WC_Inventory_Overview_Suppliers_List_Table {
		$table = new WC_Inventory_Overview_Suppliers_List_Table();
		$table->prepare_items();
		return $table;
	}

	public function test_empty_list_prepare_does_not_fatal() {
		$table = $this->prepared_table();
		$this->assertSame( array(), $table->items );
		$columns = array_keys( $table->get_columns() );
		$this->assertContains( 'observed_lead_time', $columns );
		$this->assertContains( 'on_time_rate', $columns );
	}

	public function test_list_cells_match_service_policy_across_thresholds() {
		$none = $this->create_supplier( array( 'name' => 'M12 None' ) );
		$one  = $this->create_supplier( array( 'name' => 'M12 One' ) );
		$two  = $this->create_supplier( array( 'name' => 'M12 Two' ) );

		// One completed order (below observed threshold).
		$this->received_po( (int) $one['id'], '2026-01-01 00:00:00', '2026-01-11 00:00:00', '2026-01-12', WC_Inventory_Overview_PO_Confidence::ESTIMATED );

		// Two completed on-time orders (observed + on-time usable).
		$this->received_po( (int) $two['id'], '2026-01-01 00:00:00', '2026-01-10 00:00:00', '2026-01-12', WC_Inventory_Overview_PO_Confidence::ESTIMATED );
		$this->received_po( (int) $two['id'], '2026-02-01 00:00:00', '2026-02-09 00:00:00', '2026-02-12', WC_Inventory_Overview_PO_Confidence::ESTIMATED );

		$grace = WC_Inventory_Overview_PO_Delay::grace_days_from_option();
		$bulk  = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk(
			array( (int) $none['id'], (int) $one['id'], (int) $two['id'] ),
			$grace
		);

		$table = $this->prepared_table();
		$by_id = array();
		foreach ( $table->items as $item ) {
			$by_id[ (int) $item['id'] ] = $item;
		}

		$this->assertArrayHasKey( (int) $none['id'], $by_id );
		$this->assertArrayHasKey( (int) $one['id'], $by_id );
		$this->assertArrayHasKey( (int) $two['id'], $by_id );

		// None: em dashes.
		$this->assertStringContainsString( '—', $table->column_observed_lead_time( $by_id[ (int) $none['id'] ] ) );
		$this->assertStringContainsString( '—', $table->column_on_time_rate( $by_id[ (int) $none['id'] ] ) );

		// One completed: observed not usable; on-time not usable (1 rated).
		$this->assertFalse( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $bulk[ (int) $one['id'] ] ) );
		$this->assertStringContainsString( '—', $table->column_observed_lead_time( $by_id[ (int) $one['id'] ] ) );
		$this->assertStringContainsString( '—', $table->column_on_time_rate( $by_id[ (int) $one['id'] ] ) );

		// Two: both usable; list matches detail percentage/rounding policy.
		$two_stats = $bulk[ (int) $two['id'] ];
		$this->assertTrue( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $two_stats ) );
		$this->assertTrue( WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $two_stats ) );

		$expected_days = (int) round( (float) $two_stats['average_days'] );
		$observed_html = $table->column_observed_lead_time( $by_id[ (int) $two['id'] ] );
		$this->assertStringContainsString( (string) $expected_days, $observed_html );

		$expected_pct = (int) round( ( $two_stats['on_time_count'] / $two_stats['rated_order_count'] ) * 100 );
		$on_time_html = $table->column_on_time_rate( $by_id[ (int) $two['id'] ] );
		$this->assertStringContainsString( $expected_pct . '%', $on_time_html );

		// Configured lead-time column still works.
		$this->assertStringContainsString( 'days', $table->column_default_lead_time( array_merge( $by_id[ (int) $two['id'] ], array( 'default_lead_time_days' => 14 ) ) ) );
	}

	public function test_archived_supplier_still_shows_usable_stats() {
		$supplier = $this->create_supplier( array( 'name' => 'M12 Archived' ) );
		$sid      = (int) $supplier['id'];
		$this->received_po( $sid, '2026-01-01 00:00:00', '2026-01-10 00:00:00', '2026-01-12', WC_Inventory_Overview_PO_Confidence::ESTIMATED );
		$this->received_po( $sid, '2026-02-01 00:00:00', '2026-02-09 00:00:00', '2026-02-12', WC_Inventory_Overview_PO_Confidence::ESTIMATED );

		WC_Inventory_Overview_Suppliers::archive( $sid );

		$_REQUEST['status'] = 'archived';
		$table              = $this->prepared_table();
		unset( $_REQUEST['status'] );

		$found = null;
		foreach ( $table->items as $item ) {
			if ( (int) $item['id'] === $sid ) {
				$found = $item;
				break;
			}
		}
		$this->assertNotNull( $found, 'Archived supplier must appear when status=archived.' );
		$this->assertStringNotContainsString( '—', wp_strip_all_tags( $table->column_observed_lead_time( $found ) ) );
		$this->assertStringContainsString( '%', $table->column_on_time_rate( $found ) );
	}
}
