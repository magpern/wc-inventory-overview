<?php
/**
 * Query-scaling tests for Milestone M14 — Supplier Order History.
 *
 * Same equality-based technique M9/M11/M12 established for Supplier
 * performance statistics (docs/milestones/m9-implementation-plan.md §15):
 * the M14-added query count for one Order History page load must stay
 * constant regardless of total history size or requested page size --
 * proven here at GA scale (200 POs for one supplier), matching the
 * precedent's 200-item confirmation point.
 *
 * This measures the queries issued *inside*
 * WC_Inventory_Overview_Supplier_Order_History_Service::get_page() only
 * (docs/milestones/m14-implementation-plan.md §17) -- not the full Supplier
 * detail page request, which also runs its own unrelated
 * Supplier_Lead_Time_Service queries untouched by M14.
 *
 * Fixture rows are seeded with direct $wpdb inserts rather than the full PO
 * service lifecycle -- this file tests query *count* at scale, not
 * business-rule correctness (already covered end-to-end by
 * tests/unit/supplier-order-history/test-supplier-order-history-service.php).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Order_History_Performance extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_tables();
	}

	private function purge_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Seed one supplier with $count Purchase Orders, each with one line, via
	 * direct $wpdb inserts (fast at scale; row shapes match the schema
	 * exactly -- correctness of the projection itself is proven elsewhere).
	 *
	 * @param int $count Number of POs to seed.
	 * @return int Supplier id.
	 */
	private function seed_supplier_with_pos( int $count ): int {
		global $wpdb;

		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];
		$product     = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'   => 'PERF-M14-' . $supplier_id . '-' . $i,
					'supplier_id' => $supplier_id,
					'currency'    => 'EUR',
					'status'      => WC_Inventory_Overview_PO_Statuses::PLACED,
					'order_date'  => sprintf( '2026-01-%02d', 1 + ( $i % 28 ) ),
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
					'product_id'  => $product->get_id(),
					'qty_ordered' => 10,
					'unit_cost'   => 5,
				)
			);
		}

		return $supplier_id;
	}

	/**
	 * Isolate the query count of get_page() itself.
	 *
	 * @param int $supplier_id Supplier id.
	 * @param int $page        Page number.
	 * @param int $per_page    Page size.
	 * @return int
	 */
	private function queries_for_get_page( int $supplier_id, int $page, int $per_page ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Supplier_Order_History_Service::get_page( $supplier_id, $page, $per_page );
		return $wpdb->num_queries - $before;
	}

	/**
	 * At GA scale (200 POs for one supplier), a non-empty page costs exactly
	 * 3 M14-added queries -- independent of total history size.
	 */
	public function test_200_pos_costs_exactly_three_queries_at_default_page_size() {
		$supplier_id = $this->seed_supplier_with_pos( 200 );

		$this->assertSame( 3, $this->queries_for_get_page( $supplier_id, 1, 20 ) );
	}

	/**
	 * The 3-query count is independent of requested page size (10/20/50),
	 * per the plan's explicit contract -- LIMIT size never adds queries.
	 */
	public function test_query_count_is_independent_of_per_page_value() {
		$supplier_id = $this->seed_supplier_with_pos( 200 );

		foreach ( array( 10, 20, 50 ) as $per_page ) {
			$this->assertSame(
				3,
				$this->queries_for_get_page( $supplier_id, 1, $per_page ),
				"Expected exactly 3 queries at per_page={$per_page}."
			);
		}
	}

	/**
	 * The 3-query count also holds on a later page of the same 200-PO
	 * history (offset changes the LIMIT/OFFSET values, not the query
	 * count).
	 */
	public function test_query_count_is_independent_of_page_number() {
		$supplier_id = $this->seed_supplier_with_pos( 200 );

		$this->assertSame( 3, $this->queries_for_get_page( $supplier_id, 5, 20 ) );
	}

	/**
	 * A supplier with zero POs costs exactly 1 M14-added query --
	 * count() only; list()/values_bulk() are both skipped.
	 */
	public function test_zero_pos_costs_exactly_one_query() {
		$supplier = $this->create_supplier();

		$this->assertSame( 1, $this->queries_for_get_page( (int) $supplier['id'], 1, 20 ) );
	}
}
