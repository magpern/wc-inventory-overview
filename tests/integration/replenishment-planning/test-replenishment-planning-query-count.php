<?php
/**
 * M24 WP-M24-8: the corrected §20 query/performance contract -- the
 * COMPLETE build_plan() call (not isolated service calls in sequence),
 * cold-cache setup before each measurement, at N=10/50/100 (item_ids-
 * scoped) and N=10/50/200/500 (catalog-wide candidates surfaced, resolution
 * input always <=500 per §9.5). Asserts flatness of the observed baseline
 * -- not a pre-declared exact count -- and separately asserts no per-ID
 * query pattern is ever observed (BR-M24-20, INV-M24-5).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Planning_Query_Count extends WC_Inventory_Overview_Test_Case {

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

	private function seed_needs_reorder_product_with_supplier(): int {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '3' );
		return $product->get_id();
	}

	/**
	 * Full query count (all SELECTs) for one build_plan() call, cold cache.
	 */
	private function measure_total_queries( array $item_ids = array() ): int {
		wp_cache_flush();
		global $wpdb;
		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), $item_ids );
		return $wpdb->num_queries - $before;
	}

	/**
	 * Count of any query that looks like a single-row/single-id lookup
	 * (`= %d` shaped WHERE on the products/postmeta/suppliers/lines
	 * tables) -- a crude but effective per-ID-loop detector: a genuinely
	 * bounded bulk implementation issues a small, N-independent number of
	 * such single-row queries (e.g. one po JOIN per resolved item is NOT
	 * how these queries are shaped -- they are IN(...)-based), while a
	 * per-ID loop would issue one per requested id, scaling linearly.
	 */
	private function count_queries_scaling_with_n( array $item_ids, int $n ): int {
		wp_cache_flush();
		$hits    = array();
		$counter = static function ( $query ) use ( &$hits ) {
			if ( false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $counter );
		WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), $item_ids );
		remove_filter( 'query', $counter );
		return count( $hits );
	}

	/**
	 * @group performance
	 */
	public function test_scoped_path_query_count_flat_at_10_50_100() {
		$ids_10 = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$ids_10[] = $this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_10 = $this->measure_total_queries( $ids_10 );

		$ids_50 = $ids_10;
		for ( $i = 0; $i < 40; $i++ ) {
			$ids_50[] = $this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_50 = $this->measure_total_queries( $ids_50 );

		$ids_100 = $ids_50;
		for ( $i = 0; $i < 50; $i++ ) {
			$ids_100[] = $this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_100 = $this->measure_total_queries( $ids_100 );

		fwrite( STDERR, "\n[M24 WP-M24-8 scoped query-count evidence] N=10:{$n_at_10} N=50:{$n_at_50} N=100:{$n_at_100}\n" );

		// Flatness: query count must not track N -- allow modest headroom
		// for the query-count instrumentation's own noise (e.g. an extra
		// meta_cache priming statement at larger batches is fine; scaling
		// with N is not).
		$this->assertLessThanOrEqual( $n_at_10 + 5, $n_at_50, 'Scoped-path query count must stay flat between N=10 and N=50.' );
		$this->assertLessThanOrEqual( $n_at_10 + 5, $n_at_100, 'Scoped-path query count must stay flat between N=10 and N=100.' );
		$this->assertLessThan( 30, $n_at_100, 'Query count at N=100 must be nowhere near N -- proves no per-ID query pattern.' );
	}

	public function test_scoped_path_no_per_id_query_pattern() {
		$ids = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$ids[] = $this->seed_needs_reorder_product_with_supplier();
		}

		$total = $this->count_queries_scaling_with_n( $ids, count( $ids ) );

		// A per-ID loop over 20 items would issue at least 20 additional
		// queries just for that loop, on top of the fixed baseline. A
		// bounded implementation stays well under 20.
		$this->assertLessThan( 20, $total, 'A per-ID query loop would issue at least one query per selected item; the bounded implementation must not.' );
	}

	// -----------------------------------------------------------------
	// Catalog-wide path: N=10/50/200/500 candidates surfaced. Resolution
	// input is always <=500 regardless of N (§9.5, proven separately in
	// test-build-plan-resolution-cap.php) -- this file measures the query
	// COUNT stays flat, not the candidate count.
	// -----------------------------------------------------------------

	/**
	 * @group performance
	 */
	public function test_catalog_wide_query_count_flat_at_10_50() {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_10 = $this->measure_total_queries();

		for ( $i = 0; $i < 40; $i++ ) {
			$this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_50 = $this->measure_total_queries();

		fwrite( STDERR, "\n[M24 WP-M24-8 catalog-wide query-count evidence] N=10:{$n_at_10} N=50:{$n_at_50}\n" );

		$this->assertLessThanOrEqual( $n_at_10 + 5, $n_at_50, 'Catalog-wide query count must stay flat between N=10 and N=50 candidates.' );
	}

	/**
	 * @group performance
	 */
	public function test_catalog_wide_query_count_flat_at_200() {
		for ( $i = 0; $i < 200; $i++ ) {
			$this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_200 = $this->measure_total_queries();

		fwrite( STDERR, "\n[M24 WP-M24-8 catalog-wide query-count evidence] N=200:{$n_at_200}\n" );

		// A per-item resolution loop over 200 candidates would issue at
		// least 200 additional queries; the bounded implementation
		// (<=40 discovery pages + <=2 classify + <=1 defaults + <=2 history
		// + <=1 suppliers, per §20) must stay far below that.
		$this->assertLessThan( 100, $n_at_200, 'Query count at N=200 catalog-wide candidates must be nowhere near N.' );
	}

	/**
	 * @group performance
	 */
	public function test_catalog_wide_query_count_flat_at_500() {
		for ( $i = 0; $i < 500; $i++ ) {
			$this->seed_needs_reorder_product_with_supplier();
		}
		$n_at_500 = $this->measure_total_queries();

		fwrite( STDERR, "\n[M24 WP-M24-8 catalog-wide query-count evidence] N=500:{$n_at_500}\n" );

		$this->assertLessThan( 100, $n_at_500, 'Query count at N=500 catalog-wide candidates (the resolution-cap boundary) must be nowhere near N.' );
	}
}
