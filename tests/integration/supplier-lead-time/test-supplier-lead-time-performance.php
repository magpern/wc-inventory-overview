<?php
/**
 * Query-scaling tests for Milestone M9 — Supplier Observed Lead-Time
 * Statistics.
 *
 * Same equality-based technique already proven for Inventory Position and
 * Expected Delivery (plan §15): a bulk call over N suppliers must issue the
 * same number of aggregate queries regardless of N. GA-scale point (200)
 * follows the same precedent M8 established for Inventory Position and
 * Expected Delivery's own 200-item confirmation tests.
 *
 * Fixture rows are seeded with direct $wpdb inserts rather than the full
 * PO/receiving service lifecycle -- this file tests query *count* at scale,
 * not business-rule correctness (already covered end-to-end, through the
 * real service flow, by tests/integration/supplier-lead-time/test-supplier-
 * lead-time-observations.php).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Lead_Time_Performance extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_tables();
	}

	private function purge_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Seeds $count suppliers, each with exactly one fully-received PO
	 * (one line) and one posted receipt fulfilling it -- one qualifying
	 * observation per supplier, via direct inserts (fast at scale; row
	 * shapes match the schema exactly, correctness of the *computation* is
	 * proven elsewhere).
	 *
	 * @param int $count Number of suppliers to seed.
	 * @return int[] Supplier IDs.
	 */
	private function seed_suppliers_with_one_completed_po_each( int $count ): array {
		global $wpdb;

		$supplier_ids = array();
		$product      = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$supplier = $this->create_supplier();
			$supplier_id = (int) $supplier['id'];
			$supplier_ids[] = $supplier_id;

			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'    => 'PERF-' . $supplier_id . '-' . wp_generate_password( 6, false ),
					'supplier_id'  => $supplier_id,
					'currency'     => 'EUR',
					'status'       => WC_Inventory_Overview_PO_Statuses::RECEIVED,
					'placed_at'    => '2026-01-01 00:00:00',
					'created_by'   => 0,
					'updated_by'   => 0,
				)
			);
			$po_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Order_Lines::table_name(),
				array(
					'po_id'        => $po_id,
					'line_index'   => 0,
					'product_id'   => $product->get_id(),
					'qty_ordered'  => 5,
					'qty_received' => 5,
					'unit_cost'    => 3,
					'currency'     => 'EUR',
					'status'       => 'received',
				)
			);
			$po_line_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				WC_Inventory_Overview_Goods_Receipts::table_name(),
				array(
					'receipt_number' => 'PERF-GR-' . $po_id . '-' . wp_generate_password( 6, false ),
					'status'         => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
					'source'         => WC_Inventory_Overview_Goods_Receipts::SOURCE_PO,
					'supplier_id'    => $supplier_id,
					'currency'       => 'EUR',
					'posted_at'      => '2026-01-08 00:00:00',
					'created_by'     => 0,
					'updated_by'     => 0,
				)
			);
			$receipt_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				WC_Inventory_Overview_Receipt_Lines::table_name(),
				array(
					'receipt_id'     => $receipt_id,
					'line_index'     => 0,
					'po_line_id'     => $po_line_id,
					'product_id'     => $product->get_id(),
					'qty'            => 5,
					'entered_currency' => 'EUR',
					'entered_unit_cost' => 3,
				)
			);
		}

		return $supplier_ids;
	}

	/**
	 * Counts queries touching wc_io_purchase_orders issued while $fn runs.
	 * The service's entire computation is one query referencing this table
	 * (a subquery joined against receipt_lines/goods_receipts), so counting
	 * hits on this one table name is sufficient to prove "exactly one query,
	 * regardless of scale."
	 *
	 * @param callable $fn Callable to run.
	 * @return int
	 */
	private function count_po_table_queries( callable $fn ): int {
		$po_table = WC_Inventory_Overview_Purchase_Orders::table_name();
		$queries  = array();
		$counter  = static function ( $query ) use ( $po_table, &$queries ) {
			if ( false !== strpos( $query, $po_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$fn();
		remove_filter( 'query', $counter );

		return count( $queries );
	}

	/**
	 * 10, 40, and 200 suppliers must all issue exactly one query -- the
	 * bulk aggregate query never scales with N (no N+1).
	 */
	public function test_ten_forty_and_two_hundred_suppliers_issue_the_same_query_count() {
		$ids_10 = $this->seed_suppliers_with_one_completed_po_each( 10 );
		$this->assertCount( 10, $ids_10 );

		$count_10 = $this->count_po_table_queries(
			static function () use ( $ids_10 ) {
				WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( $ids_10 );
			}
		);
		$this->assertSame( 1, $count_10, 'Exactly one query for 10 suppliers.' );

		$this->purge_tables();
		$ids_40 = $this->seed_suppliers_with_one_completed_po_each( 40 );
		$this->assertCount( 40, $ids_40 );

		$count_40 = $this->count_po_table_queries(
			static function () use ( $ids_40 ) {
				WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( $ids_40 );
			}
		);

		$this->purge_tables();
		$ids_200 = $this->seed_suppliers_with_one_completed_po_each( 200 );
		$this->assertCount( 200, $ids_200 );

		$count_200 = $this->count_po_table_queries(
			static function () use ( $ids_200 ) {
				WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( $ids_200 );
			}
		);

		$this->assertSame( $count_10, $count_40, '10 and 40 suppliers must issue the same query count (bounded, not merely small).' );
		$this->assertSame( $count_10, $count_200, '10 and 200 suppliers must issue the same query count (GA-scale confirmation).' );
	}

	/**
	 * A single-supplier call (get_stats_for_supplier) goes through the same
	 * one-query bulk path with a one-element array -- no separate N+1-prone
	 * "single" code path exists.
	 */
	public function test_single_supplier_call_issues_exactly_one_query() {
		$ids = $this->seed_suppliers_with_one_completed_po_each( 1 );

		$count = $this->count_po_table_queries(
			static function () use ( $ids ) {
				WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $ids[0] );
			}
		);

		$this->assertSame( 1, $count );
	}
}
