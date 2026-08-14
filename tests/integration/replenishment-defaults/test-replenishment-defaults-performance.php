<?php
/**
 * Integration tests for M23 WP-M23-6: the 2-axis query-count matrix
 * (0/1/10/50 historical suppliers x unconfigured/valid/stale preference)
 * for the 'prefilled' resolve() path, and a list-table zero-delta
 * re-proof.
 *
 * Covers INV-M23-16/17/18: supplier-resolution query count is bounded and
 * invariant with respect to historical-supplier count on all three
 * preference-state branches; with no preference configured the count is
 * exactly M22's own unmodified figure; Inventory Overview list-table
 * query count is unaffected (M23 code never executes during list-table
 * rendering -- proven structurally, since list-table.php is untouched by
 * M23, and its own existing M21/M22 query-count regression tests
 * (Test_WC_IO_Reorder_Signal_*, Test_WC_IO_List_Table_Reorder_Action_Link)
 * were re-run unmodified at WP-M23-5 and stayed green).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Performance extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	private function seed_committed_history( int $product_id, string $order_date, string $status = 'closed_short' ): int {
		$po = $this->create_purchase_order( array( 'order_date' => $order_date ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product_id ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => $status, 'order_date' => $order_date ) );
		return (int) $po['supplier_id'];
	}

	private function seed_n_suppliers( int $product_id, int $n ): void {
		for ( $i = 0; $i < $n; $i++ ) {
			$this->seed_committed_history( $product_id, '2026-01-0' . ( 1 + ( $i % 9 ) ) );
		}
	}

	private function count_relevant_queries( int $product_id ): int {
		$lines_table     = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$suppliers_table = WC_Inventory_Overview_Suppliers::table_name();
		$hits            = array();
		$counter         = static function ( $query ) use ( $lines_table, $suppliers_table, &$hits ) {
			if ( false === stripos( $query, 'SELECT' ) ) {
				return $query;
			}
			if ( false !== strpos( $query, $lines_table ) || false !== strpos( $query, $suppliers_table ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product_id );
		remove_filter( 'query', $counter );

		$this->assertSame( 'prefilled', $result['status'], 'Query-count fixture must stay in the prefilled branch throughout.' );

		return count( $hits );
	}

	// ---------------------------------------------------------------
	// Axis: unconfigured preference (byte-for-byte M22 -- INV-M23-18).
	// ---------------------------------------------------------------

	public function test_unconfigured_query_count_invariant_across_supplier_scale() {
		$product = $this->make_needs_reorder_product();

		$this->seed_n_suppliers( $product->get_id(), 1 );
		$n_at_1 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 9 );
		$n_at_10 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 40 );
		$n_at_50 = $this->count_relevant_queries( $product->get_id() );

		$this->assertSame( $n_at_1, $n_at_10 );
		$this->assertSame( $n_at_1, $n_at_50 );
	}

	// ---------------------------------------------------------------
	// Axis: valid preference (history query must never run -- must stay
	// invariant even as historical-supplier count grows).
	// ---------------------------------------------------------------

	public function test_valid_preference_query_count_invariant_across_supplier_scale() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );

		$n_at_0 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 1 );
		$n_at_1 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 9 );
		$n_at_10 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 40 );
		$n_at_50 = $this->count_relevant_queries( $product->get_id() );

		$this->assertSame( $n_at_0, $n_at_1 );
		$this->assertSame( $n_at_0, $n_at_10 );
		$this->assertSame( $n_at_0, $n_at_50 );
	}

	// ---------------------------------------------------------------
	// Axis: stale preference (falls back to history -- must still be
	// invariant with respect to historical-supplier count).
	// ---------------------------------------------------------------

	public function test_stale_preference_query_count_invariant_across_supplier_scale() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $preferred['id'] );

		$this->seed_n_suppliers( $product->get_id(), 1 );
		$n_at_1 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 9 );
		$n_at_10 = $this->count_relevant_queries( $product->get_id() );

		$this->seed_n_suppliers( $product->get_id(), 40 );
		$n_at_50 = $this->count_relevant_queries( $product->get_id() );

		$this->assertSame( $n_at_1, $n_at_10 );
		$this->assertSame( $n_at_1, $n_at_50 );
	}

	/**
	 * INV-M23-18: with no preference configured, the query count must be
	 * exactly what M22 alone measured (Test_WC_IO_M22_Supplier_Fallback_Characterization),
	 * proving the unconfigured path is untouched by M23's new branch.
	 */
	public function test_unconfigured_query_count_matches_m22_baseline() {
		$product = $this->make_needs_reorder_product();
		$this->seed_n_suppliers( $product->get_id(), 1 );

		$this->assertSame( 3, $this->count_relevant_queries( $product->get_id() ), 'Must match the M22 characterization baseline exactly (1 position + 1 history + 1 bulk fetch).' );
	}
}
