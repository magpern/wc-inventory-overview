<?php
/**
 * Query-scaling tests for Milestone M15 — Supplier Spend Summary.
 *
 * Same equality-based technique M9/M11/M12/M14 established for query-count
 * contracts (docs/milestones/m9-implementation-plan.md §15): the M15-added
 * query count for Supplier_Spend_Service::get_summary() must stay constant
 * regardless of total committed-PO count -- proven here at GA scale (200
 * committed POs across 3 currencies for one supplier).
 *
 * Unlike M14's Order History (which pages, so its contract is a fixed
 * "3 queries per page"), M15's aggregate has no pagination step at all --
 * the contract here is exactly 1 query, period, for any history size.
 *
 * Fixture rows are seeded with direct $wpdb inserts rather than the full PO
 * service lifecycle -- this file tests query *count* at scale, not
 * business-rule correctness (already covered by
 * tests/unit/purchase-orders/test-po-spend-summary.php and
 * tests/unit/supplier-spend/test-supplier-spend-service.php).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Spend_Performance extends WC_Inventory_Overview_Test_Case {

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
	 * Seed one supplier with $count committed Purchase Orders, cycling
	 * across three currencies and the four committed statuses, each with
	 * one line, via direct $wpdb inserts (fast at scale; row shapes match
	 * the schema exactly -- correctness of the projection itself is proven
	 * elsewhere).
	 *
	 * @param int $count Number of POs to seed.
	 * @return int Supplier id.
	 */
	private function seed_supplier_with_committed_pos( int $count ): int {
		global $wpdb;

		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];
		$product     = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$currencies = array( 'EUR', 'USD', 'SEK' );
		$statuses   = array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);

		for ( $i = 0; $i < $count; $i++ ) {
			$currency = $currencies[ $i % count( $currencies ) ];
			$status   = $statuses[ $i % count( $statuses ) ];

			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'   => 'PERF-M15-' . $supplier_id . '-' . $i,
					'supplier_id' => $supplier_id,
					'currency'    => $currency,
					'status'      => $status,
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
					'currency'    => $currency,
					'qty_ordered' => 10,
					'qty_received' => 5,
					'unit_cost'   => 5,
				)
			);
		}

		return $supplier_id;
	}

	/**
	 * Seed one supplier with $count draft/cancelled-only Purchase Orders
	 * (never committed) via direct $wpdb inserts.
	 *
	 * @param int $count Number of POs to seed.
	 * @return int Supplier id.
	 */
	private function seed_supplier_with_uncommitted_pos( int $count ): int {
		global $wpdb;

		$supplier    = $this->create_supplier();
		$supplier_id = (int) $supplier['id'];
		$product     = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$statuses = array( WC_Inventory_Overview_PO_Statuses::DRAFT, WC_Inventory_Overview_PO_Statuses::CANCELLED );

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'   => 'PERF-M15-UNCOMMITTED-' . $supplier_id . '-' . $i,
					'supplier_id' => $supplier_id,
					'currency'    => 'EUR',
					'status'      => $statuses[ $i % 2 ],
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
					'currency'    => 'EUR',
					'qty_ordered' => 10,
					'unit_cost'   => 5,
				)
			);
		}

		return $supplier_id;
	}

	/**
	 * Isolate the query count of get_summary() itself.
	 *
	 * @param int $supplier_id Supplier id.
	 * @return int
	 */
	private function queries_for_get_summary( int $supplier_id ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $supplier_id );
		return $wpdb->num_queries - $before;
	}

	/**
	 * At GA scale (200 committed POs across 3 currencies for one supplier),
	 * get_summary() costs exactly 1 query -- independent of history size,
	 * unlike M14's paginated 3-query contract.
	 */
	public function test_200_pos_multi_currency_costs_exactly_one_query() {
		$supplier_id = $this->seed_supplier_with_committed_pos( 200 );

		$this->assertSame( 1, $this->queries_for_get_summary( $supplier_id ) );
	}

	/**
	 * The formula/currency-grouping correctness also holds at this scale --
	 * a light sanity check alongside the query-count assertion (deep
	 * correctness is covered by the unit tests; this just confirms the
	 * scaled fixture actually produces the expected 3 currency rows).
	 */
	public function test_200_pos_multi_currency_produces_three_currency_rows() {
		$supplier_id = $this->seed_supplier_with_committed_pos( 200 );

		$result = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $supplier_id );

		$this->assertCount( 3, $result );
		$total_po_count = 0;
		foreach ( $result as $row ) {
			$total_po_count += $row['po_count'];
		}
		$this->assertSame( 200, $total_po_count, 'Every seeded PO has exactly one line in exactly one currency here, so the row po_counts sum to 200 in this fixture only -- not a general guarantee (BR-M15-5).' );
	}

	/**
	 * A supplier with only draft/cancelled POs (never committed) costs
	 * exactly 1 query and returns an empty array -- no per-PO query, no
	 * preliminary existence query.
	 */
	public function test_uncommitted_only_supplier_costs_exactly_one_query() {
		$supplier_id = $this->seed_supplier_with_uncommitted_pos( 50 );

		$this->assertSame( 1, $this->queries_for_get_summary( $supplier_id ) );
		$this->assertSame( array(), WC_Inventory_Overview_Supplier_Spend_Service::get_summary( $supplier_id ) );
	}

	/**
	 * A supplier with zero POs at all costs exactly 1 query and returns an
	 * empty array.
	 */
	public function test_zero_pos_costs_exactly_one_query() {
		$supplier = $this->create_supplier();

		$this->assertSame( 1, $this->queries_for_get_summary( (int) $supplier['id'] ) );
		$this->assertSame( array(), WC_Inventory_Overview_Supplier_Spend_Service::get_summary( (int) $supplier['id'] ) );
	}
}
