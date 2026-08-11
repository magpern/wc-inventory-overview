<?php
/**
 * WP-M17-5: Derived-statistics reconciliation proof (INV-M17-6).
 *
 * Proves that all derived-statistics services require zero code changes to
 * correctly reflect a merge. Statistics filters only by supplier_id, which
 * is automatically bulk-updated by the merge service.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Derived_Stats extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_inventory_movements' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_receipt_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_receipt_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_po_events' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_order_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_orders' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'posts WHERE post_type = "product"' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Supplier_Lead_Time_Service reflects merge: source stats empty, target includes source data.
	 */
	public function test_lead_time_service_reflects_merge() {
		$var1 = $this->create_simple_product();
		$s1   = $this->create_supplier( 'Source' );
		$s2   = $this->create_supplier( 'Target' );

		// Create placed PO and received GR for s1.
		$po_id = $this->create_po( $s1, 'PO-001', '2025-12-01' );
		$this->place_po( $po_id );
		$this->create_and_post_receipt( $var1, $s1, $po_id, 'GR-001', '2025-12-05' );

		// Before merge: s1 has stats, s2 has none.
		$s1_stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_for_supplier( $s1 );
		$this->assertIsArray( $s1_stats );
		$this->assertGreaterThan( 0, $s1_stats['completed_order_count'] );

		$s2_stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_for_supplier( $s2 );
		$this->assertSame( 0, $s2_stats['completed_order_count'] );

		// Perform merge.
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );

		// After merge: s1 stats empty (no POs), s2 includes former s1 data.
		$s1_stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_for_supplier( $s1 );
		$this->assertSame( 0, $s1_stats_after['completed_order_count'] );

		$s2_stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_for_supplier( $s2 );
		$this->assertGreaterThan( 0, $s2_stats_after['completed_order_count'] );
		$this->assertSame( $s1_stats['completed_order_count'], $s2_stats_after['completed_order_count'] );
	}

	/**
	 * Supplier_Order_History_Service reflects merge.
	 */
	public function test_order_history_service_reflects_merge() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$po1 = $this->create_po( $s1, 'PO-001', null );
		$this->place_po( $po1 );

		// Before merge.
		$s1_history = WC_Inventory_Overview_Supplier_Order_History_Service::list( $s1, array() );
		$this->assertCount( 1, $s1_history );

		$s2_history = WC_Inventory_Overview_Supplier_Order_History_Service::list( $s2, array() );
		$this->assertCount( 0, $s2_history );

		// After merge.
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );

		$s1_history_after = WC_Inventory_Overview_Supplier_Order_History_Service::list( $s1, array() );
		$this->assertCount( 0, $s1_history_after );

		$s2_history_after = WC_Inventory_Overview_Supplier_Order_History_Service::list( $s2, array() );
		$this->assertCount( 1, $s2_history_after );
	}

	/**
	 * Supplier_Spend_Service reflects merge.
	 */
	public function test_spend_service_reflects_merge() {
		$s1 = $this->create_supplier( 'Source', 'EUR' );
		$s2 = $this->create_supplier( 'Target', 'EUR' );

		$po1 = $this->create_po( $s1, 'PO-001', null, 'EUR' );
		$this->place_po( $po1 );

		// Before merge.
		$s1_spend = WC_Inventory_Overview_Supplier_Spend_Service::get_summary_for_supplier( $s1 );
		$this->assertGreaterThan( 0, count( $s1_spend ) );

		$s2_spend = WC_Inventory_Overview_Supplier_Spend_Service::get_summary_for_supplier( $s2 );
		$this->assertSame( 0, count( $s2_spend ) );

		// After merge.
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );

		$s1_spend_after = WC_Inventory_Overview_Supplier_Spend_Service::get_summary_for_supplier( $s1 );
		$this->assertSame( 0, count( $s1_spend_after ) );

		$s2_spend_after = WC_Inventory_Overview_Supplier_Spend_Service::get_summary_for_supplier( $s2 );
		$this->assertGreaterThan( 0, count( $s2_spend_after ) );
	}

	// Helper methods.

	private function create_supplier( string $name, string $currency = 'EUR' ): int {
		return (int) WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => $currency,
			)
		);
	}

	private function create_simple_product(): int {
		$product = new WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 100 );
		return $product->save();
	}

	private function create_po( int $supplier_id, string $po_number, ?string $expected_date, string $currency = 'EUR' ): int {
		return (int) WC_Inventory_Overview_PO_Service::create_draft(
			array(
				'po_number'      => $po_number,
				'supplier_id'    => $supplier_id,
				'currency'       => $currency,
				'expected_date'  => $expected_date,
			),
			array()
		);
	}

	private function place_po( int $po_id ): void {
		WC_Inventory_Overview_PO_Service::place( $po_id, array() );
	}

	private function create_and_post_receipt( int $var_id, int $supplier_id, int $po_id, string $receipt_number, string $received_date ): int {
		$receipt_id = (int) WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'receipt_number'  => $receipt_number,
				'supplier_id'     => $supplier_id,
				'currency'        => 'EUR',
				'product_lines'   => array(
					array(
						'line_index'      => 0,
						'variation_id'    => $var_id,
						'qty'             => 10,
						'entered_cost'    => '100.00',
						'source'          => 'po',
						'po_line_id'      => $this->get_po_line_id( $po_id ),
					),
				),
				'reference'       => 'REF-001',
				'received_date'   => $received_date,
			)
		);

		WC_Inventory_Overview_Goods_Receipt_Service::post( $receipt_id );
		return $receipt_id;
	}

	private function get_po_line_id( int $po_id ): ?int {
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list( array( 'po_id' => $po_id ) );
		return $lines ? (int) $lines[0]['id'] : null;
	}
}
