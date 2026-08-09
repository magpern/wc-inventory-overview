<?php
/**
 * Query-scaling tests for Milestone M10 — Purchase Order Expected-Date
 * Suggestion.
 *
 * Same equality-based technique proven for M9's Supplier Observed Lead-Time
 * Statistics (docs/milestones/m10-implementation-plan.md §10): a bulk
 * suggestion call over N suppliers must issue the same number of aggregate
 * queries regardless of N. Expected_Date_Suggestion_Service itself never
 * touches the database directly (proven by the architecture guard) -- its
 * only query cost is the one delegated call to
 * Supplier_Lead_Time_Service::get_stats_bulk(), so counting hits on
 * wc_io_purchase_orders (the table that query joins against) is sufficient
 * to prove "exactly one query, regardless of scale," exactly as M9's own
 * performance suite already established.
 *
 * Fixture rows are seeded with direct $wpdb inserts rather than the full
 * PO/receiving service lifecycle -- this file tests query *count* at scale,
 * not business-rule correctness (already covered end-to-end by
 * tests/integration/expected-date-suggestion/test-expected-date-suggestion-observations.php).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Date_Suggestion_Performance extends WC_Inventory_Overview_Test_Case {

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
	 * Seeds $count suppliers, each with exactly one fully-received PO (one
	 * line) and one posted receipt fulfilling it -- one qualifying
	 * observation per supplier, via direct inserts (fast at scale; row
	 * shapes match the schema exactly; correctness of the *computation* is
	 * proven elsewhere). Also gives each supplier a nonzero configured
	 * fallback, so the seeded rows are realistic (though the observed
	 * value -- one sample, below MINIMUM_SAMPLE_COUNT_FOR_DISPLAY -- will
	 * fall back to it, which is irrelevant to this file's only concern:
	 * query count).
	 *
	 * @param int $count Number of suppliers to seed.
	 * @return array<int,array<string,mixed>> Supplier rows.
	 */
	private function seed_suppliers_with_one_completed_po_each( int $count ): array {
		global $wpdb;

		$suppliers = array();
		$product   = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$supplier    = $this->create_supplier( array( 'default_lead_time_days' => 7 ) );
			$supplier_id = (int) $supplier['id'];
			$suppliers[] = $supplier;

			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'   => 'PERF-' . $supplier_id . '-' . wp_generate_password( 6, false ),
					'supplier_id' => $supplier_id,
					'currency'    => 'EUR',
					'status'      => WC_Inventory_Overview_PO_Statuses::RECEIVED,
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
					'receipt_id'        => $receipt_id,
					'line_index'        => 0,
					'po_line_id'        => $po_line_id,
					'product_id'        => $product->get_id(),
					'qty'               => 5,
					'entered_currency'  => 'EUR',
					'entered_unit_cost' => 3,
				)
			);
		}

		return $suppliers;
	}

	/**
	 * Counts queries touching wc_io_purchase_orders issued while $fn runs --
	 * the same technique and target table M9's own performance suite uses,
	 * since Expected_Date_Suggestion_Service's entire query cost is exactly
	 * the one delegated Supplier_Lead_Time_Service query that joins against
	 * this table.
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
	 * 10, 40, and 200 suppliers must all issue exactly one
	 * Supplier_Lead_Time_Service query -- the suggestion bulk pass never
	 * scales with N (no N+1), matching the explicit expected count (not
	 * merely equality) M9's own suite already asserts.
	 */
	public function test_ten_forty_and_two_hundred_suppliers_issue_the_same_query_count() {
		$suppliers_10 = $this->seed_suppliers_with_one_completed_po_each( 10 );
		$this->assertCount( 10, $suppliers_10 );

		$count_10 = $this->count_po_table_queries(
			static function () use ( $suppliers_10 ) {
				WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers_10 );
			}
		);
		$this->assertSame( 1, $count_10, 'Exactly one Supplier_Lead_Time_Service query for 10 suppliers.' );

		$this->purge_tables();
		$suppliers_40 = $this->seed_suppliers_with_one_completed_po_each( 40 );
		$this->assertCount( 40, $suppliers_40 );

		$count_40 = $this->count_po_table_queries(
			static function () use ( $suppliers_40 ) {
				WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers_40 );
			}
		);
		$this->assertSame( 1, $count_40, 'Exactly one Supplier_Lead_Time_Service query for 40 suppliers.' );

		$this->purge_tables();
		$suppliers_200 = $this->seed_suppliers_with_one_completed_po_each( 200 );
		$this->assertCount( 200, $suppliers_200 );

		$count_200 = $this->count_po_table_queries(
			static function () use ( $suppliers_200 ) {
				WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers_200 );
			}
		);
		$this->assertSame( 1, $count_200, 'Exactly one Supplier_Lead_Time_Service query for 200 suppliers (GA-scale confirmation).' );

		$this->assertSame( $count_10, $count_40, '10 and 40 suppliers must issue the same query count (bounded, not merely small).' );
		$this->assertSame( $count_10, $count_200, '10 and 200 suppliers must issue the same query count (GA-scale confirmation).' );
	}

	/**
	 * A single-supplier call (get_suggestion_for_supplier) goes through the
	 * same one-query bulk path with a one-element array -- no separate
	 * N+1-prone "single" code path exists.
	 */
	public function test_single_supplier_call_issues_exactly_one_query() {
		$suppliers = $this->seed_suppliers_with_one_completed_po_each( 1 );

		$count = $this->count_po_table_queries(
			static function () use ( $suppliers ) {
				WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $suppliers[0] );
			}
		);

		$this->assertSame( 1, $count );
	}
}
