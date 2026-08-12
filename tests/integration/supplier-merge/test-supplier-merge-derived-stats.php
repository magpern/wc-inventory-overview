<?php
/**
 * WP-M17-5: Derived-statistics reconciliation proof (INV-M17-6).
 *
 * Proves that all derived-statistics services require zero code changes to
 * correctly reflect a merge. Statistics filters only by supplier_id, which
 * is automatically bulk-updated by the merge service. Fixtures are seeded
 * via direct $wpdb inserts, mirroring the established fast-fixture technique
 * from tests/integration/supplier-spend/test-supplier-spend-performance.php.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Derived_Stats extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_receipt_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_order_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_orders' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Supplier_Lead_Time_Service reflects merge: source stats empty after
	 * merge, target inherits the reassigned observation.
	 */
	public function test_lead_time_service_reflects_merge() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$product_id = $this->create_simple_product();
		$this->seed_completed_po_with_receipt( $s1, $product_id, 'PO-LT-001', 'GR-LT-001' );

		// Before merge: s1 has data, s2 has none.
		$s1_stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $s1 );
		$this->assertTrue( $s1_stats['has_data'] );
		$this->assertSame( 1, $s1_stats['sample_count'] );

		$s2_stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $s2 );
		$this->assertFalse( $s2_stats['has_data'] );
		$this->assertSame( 0, $s2_stats['sample_count'] );

		// Perform merge.
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertIsArray( $result );

		// After merge: s1 stats empty (no POs left), s2 includes former s1 data.
		$s1_stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $s1 );
		$this->assertFalse( $s1_stats_after['has_data'] );
		$this->assertSame( 0, $s1_stats_after['sample_count'] );

		$s2_stats_after = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $s2 );
		$this->assertTrue( $s2_stats_after['has_data'] );
		$this->assertSame( 1, $s2_stats_after['sample_count'] );
	}

	/**
	 * Supplier_Order_History_Service reflects merge.
	 */
	public function test_order_history_service_reflects_merge() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$product_id = $this->create_simple_product();
		$this->seed_placed_po( $s1, $product_id, 'PO-OH-001' );

		// Before merge.
		$s1_history = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $s1 );
		$this->assertSame( 1, $s1_history['count'] );

		$s2_history = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $s2 );
		$this->assertSame( 0, $s2_history['count'] );

		// After merge.
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );

		$s1_history_after = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $s1 );
		$this->assertSame( 0, $s1_history_after['count'] );

		$s2_history_after = WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $s2 );
		$this->assertSame( 1, $s2_history_after['count'] );
	}

	/**
	 * Supplier_Spend_Service reflects merge.
	 */
	public function test_spend_service_reflects_merge() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source', 'EUR' );
		$s2 = $this->create_supplier( 'Target', 'EUR' );

		$product_id = $this->create_simple_product();
		$this->seed_placed_po( $s1, $product_id, 'PO-SP-001', 'EUR' );

		// Before merge.
		$s1_spend = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $s1 );
		$this->assertGreaterThan( 0, count( $s1_spend ) );

		$s2_spend = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $s2 );
		$this->assertSame( 0, count( $s2_spend ) );

		// After merge.
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );

		$s1_spend_after = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $s1 );
		$this->assertSame( 0, count( $s1_spend_after ) );

		$s2_spend_after = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $s2 );
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

	/**
	 * Seed a placed PO (committed status) for a supplier, direct $wpdb insert.
	 */
	private function seed_placed_po( int $supplier_id, int $product_id, string $po_number, string $currency = 'EUR' ): int {
		global $wpdb;

		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array(
				'po_number'   => $po_number,
				'supplier_id' => $supplier_id,
				'currency'    => $currency,
				'status'      => WC_Inventory_Overview_PO_Statuses::PLACED,
				'order_date'  => '2026-01-01',
				'placed_at'   => '2026-01-01 00:00:00',
				'created_by'  => 0,
				'updated_by'  => 0,
			)
		);
		$po_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Order_Lines::table_name(),
			array(
				'po_id'       => $po_id,
				'line_index'  => 0,
				'product_id'  => $product_id,
				'currency'    => $currency,
				'qty_ordered' => 10,
				'unit_cost'   => 5,
			)
		);

		return $po_id;
	}

	/**
	 * Seed a fully-received PO with a posted Goods Receipt linked via
	 * receipt_lines.po_line_id, matching the exact shape
	 * Supplier_Lead_Time_Service::query_observations() requires.
	 */
	private function seed_completed_po_with_receipt( int $supplier_id, int $product_id, string $po_number, string $receipt_number ): void {
		global $wpdb;

		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array(
				'po_number'   => $po_number,
				'supplier_id' => $supplier_id,
				'currency'    => 'EUR',
				'status'      => WC_Inventory_Overview_PO_Statuses::RECEIVED,
				'order_date'  => '2026-01-01',
				'placed_at'   => '2026-01-01 00:00:00',
				'created_by'  => 0,
				'updated_by'  => 0,
			)
		);
		$po_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Order_Lines::table_name(),
			array(
				'po_id'        => $po_id,
				'line_index'   => 0,
				'product_id'   => $product_id,
				'currency'     => 'EUR',
				'qty_ordered'  => 10,
				'qty_received' => 10,
				'unit_cost'    => 5,
			)
		);
		$po_line_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => $receipt_number,
				'supplier_id'    => $supplier_id,
				'currency'       => 'EUR',
				'status'         => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
				'source'         => 'po',
				'posted_at'      => '2026-01-06 00:00:00',
				'created_by'     => 0,
				'updated_by'     => 0,
			)
		);
		$receipt_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			WC_Inventory_Overview_Receipt_Lines::table_name(),
			array(
				'receipt_id'  => $receipt_id,
				'line_index'  => 0,
				'po_line_id'  => $po_line_id,
				'product_id'  => $product_id,
				'qty'         => 10,
			)
		);
	}
}
