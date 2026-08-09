<?php
/**
 * Query-scaling tests for Milestone M12 — Supplier List Performance Surface.
 *
 * Primary invariant (INV-M12-2): prepare_items() issues exactly one
 * get_stats_bulk()-backed statistics SQL query (identified by the
 * observed_days computation token owned by Supplier_Lead_Time_Service),
 * independent of page size at 10/40/200. The service contract already
 * guarantees one SELECT per get_stats_bulk() call; this file proves the
 * list table does not add N+1 single-supplier retrievals on top.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_List_Performance_Queries extends WC_Inventory_Overview_Test_Case {

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
	 * @param int $count Supplier count.
	 */
	private function seed_suppliers( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->create_supplier( array( 'name' => 'M12 Perf ' . $i ) );
		}
	}

	/**
	 * Count SELECT statements that carry the Lead Time Service computation
	 * signature (observed_days) while $fn runs.
	 *
	 * @param callable $fn Callable.
	 * @return int
	 */
	private function count_stats_queries( callable $fn ): int {
		$queries = array();
		$counter = static function ( $query ) use ( &$queries ) {
			if ( false !== stripos( $query, 'SELECT' ) && false !== strpos( $query, 'observed_days' ) ) {
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
	 * Force a large page size so prepare_items() loads all seeded suppliers
	 * on one page.
	 *
	 * @param int $per_page Per page.
	 */
	private function prepare_with_per_page( int $per_page ): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		update_user_meta( $user_id, 'wc_io_suppliers_per_page', $per_page );

		$table = new WC_Inventory_Overview_Suppliers_List_Table();
		$table->prepare_items();
	}

	public function test_list_prepare_issues_one_stats_query_at_ten_forty_two_hundred() {
		$this->seed_suppliers( 10 );
		$count_10 = $this->count_stats_queries(
			function () {
				$this->prepare_with_per_page( 10 );
			}
		);
		$this->assertSame( 1, $count_10, 'Exactly one stats SQL query for a 10-supplier page (service contract: one query per get_stats_bulk).' );

		$this->purge_tables();
		$this->seed_suppliers( 40 );
		$count_40 = $this->count_stats_queries(
			function () {
				$this->prepare_with_per_page( 40 );
			}
		);

		$this->purge_tables();
		$this->seed_suppliers( 200 );
		$count_200 = $this->count_stats_queries(
			function () {
				$this->prepare_with_per_page( 200 );
			}
		);

		$this->assertSame( $count_10, $count_40, '10 and 40 supplier pages must issue the same stats query count.' );
		$this->assertSame( $count_10, $count_200, '10 and 200 supplier pages must issue the same stats query count (no N+1).' );
		$this->assertSame( 1, $count_200 );
	}

	public function test_empty_page_issues_zero_stats_sql_queries() {
		// get_stats_bulk([]) returns before querying — zero observed_days SQL.
		$count = $this->count_stats_queries(
			function () {
				$this->prepare_with_per_page( 20 );
			}
		);
		$this->assertSame( 0, $count, 'Empty supplier page must not issue a stats SQL query.' );
	}
}
