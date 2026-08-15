<?php
/**
 * M25 WP-M25-7/§24/§39/§48 Amendment F: query-count + wall-clock performance
 * matrix at 1/1, 10/1, 50/1, 50/10, 100/1, 100/20, 100/100 (lines/suppliers).
 *
 * Every fixture here is genuinely commit-eligible -- no pre-seeded
 * draft/placed/partially_received PO line ever exists for any seeded
 * product before the measured commit() call, so the conflict detector never
 * skips the workload before create_draft() is actually measured (§48
 * Amendment F).
 *
 * Excluded from the default CI filter via @group performance, mirroring
 * Test_WC_IO_Replenishment_Planning_Query_Count's own established
 * convention -- run explicitly as part of a milestone's own
 * release-readiness validation pass.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Performance extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Commit_Service::reset_test_seams();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Build $lines items across $supplier_count distinct suppliers, round-robin.
	 *
	 * @return array{items: array<int,array{product_id:int,variation_id:int,qty:float}>}
	 */
	private function build_fixture( int $lines, int $supplier_count ): array {
		$suppliers = array();
		for ( $i = 0; $i < $supplier_count; $i++ ) {
			$suppliers[] = $this->create_supplier();
		}

		$items = array();
		for ( $i = 0; $i < $lines; $i++ ) {
			$supplier = $suppliers[ $i % $supplier_count ];
			$product  = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$product->set_low_stock_amount( 5 );
			$product->save();
			WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );
			$items[] = array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 1 );
		}

		return array( 'items' => $items );
	}

	/**
	 * @return array{elapsed:float, queries:int, get_lock:int, release_lock:int, created:int, failed:int, skipped:int, po_count:int, lock_wall_time:float}
	 */
	private function measure( array $items ): array {
		global $wpdb;
		wp_cache_flush();

		// Separate micro-measurement of lock-acquisition wall time alone
		// (uncontended -- these ids have never been locked before), so the
		// commit-time GET_LOCK() round trips can be reported distinctly from
		// total commit wall time.
		$item_post_ids = array_map(
			static function ( array $item ) {
				return $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			},
			$items
		);
		$lock_start = microtime( true );
		$probe      = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $item_post_ids, 1 );
		$lock_wall_time = microtime( true ) - $lock_start;
		WC_Inventory_Overview_Replenishment_Item_Lock::release( $probe );

		$get_lock_count     = 0;
		$release_lock_count = 0;
		$counter            = static function ( $query ) use ( &$get_lock_count, &$release_lock_count ) {
			if ( false !== stripos( $query, 'GET_LOCK' ) ) {
				++$get_lock_count;
			}
			if ( false !== stripos( $query, 'RELEASE_LOCK' ) ) {
				++$release_lock_count;
			}
			return $query;
		};
		add_filter( 'query', $counter );

		$before = $wpdb->num_queries;
		$start  = microtime( true );
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );
		$elapsed = microtime( true ) - $start;
		$after  = $wpdb->num_queries;

		remove_filter( 'query', $counter );

		$po_count = count( array_unique( wp_list_pluck( $result['created'], 'po_id' ) ) );

		return array(
			'elapsed'        => $elapsed,
			'queries'        => $after - $before,
			'get_lock'       => $get_lock_count,
			'release_lock'   => $release_lock_count,
			'created'        => count( $result['created'] ),
			'failed'         => count( $result['failed'] ),
			'skipped'        => count( $result['skipped'] ),
			'po_count'       => $po_count,
			'lock_wall_time' => $lock_wall_time,
		);
	}

	private function report( string $label, array $m ): void {
		fwrite(
			STDERR,
			sprintf(
				"\n[M25 WP-M25-7 performance] %-8s selected=%-4d created=%-4d failed=%-3d skipped=%-3d POs=%-4d queries=%-5d GET_LOCK=%-4d RELEASE_LOCK=%-4d lock_wall=%.4fs total_wall=%.4fs\n",
				$label,
				$m['created'] + $m['failed'] + $m['skipped'],
				$m['created'],
				$m['failed'],
				$m['skipped'],
				$m['po_count'],
				$m['queries'],
				$m['get_lock'],
				$m['release_lock'],
				$m['lock_wall_time'],
				$m['elapsed']
			)
		);
	}

	/**
	 * @group performance
	 */
	public function test_1_line_1_supplier() {
		$fixture = $this->build_fixture( 1, 1 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '1/1', $m );

		$this->assertSame( 1, $m['created'] );
		$this->assertSame( 1, $m['po_count'] );
	}

	/**
	 * @group performance
	 */
	public function test_10_lines_1_supplier() {
		$fixture = $this->build_fixture( 10, 1 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '10/1', $m );

		$this->assertSame( 10, $m['created'] );
		$this->assertSame( 1, $m['po_count'] );
	}

	/**
	 * @group performance
	 */
	public function test_50_lines_1_supplier() {
		$fixture = $this->build_fixture( 50, 1 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '50/1', $m );

		$this->assertSame( 50, $m['created'] );
		$this->assertSame( 1, $m['po_count'] );
	}

	/**
	 * @group performance
	 */
	public function test_50_lines_10_suppliers() {
		$fixture = $this->build_fixture( 50, 10 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '50/10', $m );

		$this->assertSame( 50, $m['created'] );
		$this->assertSame( 10, $m['po_count'] );
	}

	/**
	 * @group performance
	 */
	public function test_100_lines_1_supplier() {
		$fixture = $this->build_fixture( 100, 1 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '100/1', $m );

		$this->assertSame( 100, $m['created'] );
		$this->assertSame( 1, $m['po_count'] );
		$this->assertLessThan( 10.0, $m['elapsed'], 'Total commit wall time must clear the ~10s synchronous-HTTP-request budget (§24).' );
	}

	/**
	 * @group performance
	 */
	public function test_100_lines_20_suppliers() {
		$fixture = $this->build_fixture( 100, 20 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '100/20', $m );

		$this->assertSame( 100, $m['created'] );
		$this->assertSame( 20, $m['po_count'] );
		$this->assertLessThan( 10.0, $m['elapsed'], 'Total commit wall time must clear the ~10s synchronous-HTTP-request budget (§24).' );
	}

	/**
	 * The mandatory worst-case measurement (§24/§48 Amendment F): 100
	 * genuinely commit-eligible lines across 100 distinct suppliers (one
	 * line per supplier group, the maximum possible group-attempt count for
	 * a 100-line commit) -- drives the MAX_COMMIT_GROUPS decision.
	 *
	 * @group performance
	 */
	public function test_100_lines_100_suppliers_worst_case() {
		$fixture = $this->build_fixture( 100, 100 );
		$m       = $this->measure( $fixture['items'] );
		$this->report( '100/100 (worst case)', $m );

		$this->assertSame( 100, $m['created'], 'All 100 lines must genuinely reach create_draft() -- Amendment F fixture-integrity check.' );
		$this->assertSame( 100, $m['po_count'], '100 distinct suppliers must yield 100 distinct POs.' );
		$this->assertSame( 0, $m['failed'] );
		$this->assertSame( 0, $m['skipped'] );

		fwrite(
			STDERR,
			sprintf(
				"\n[M25 §24 decision] 100/100 worst case: %.4fs total wall time (threshold: 10s). %s\n",
				$m['elapsed'],
				$m['elapsed'] < 10.0 ? 'MEASURED, NO ADDITIONAL CAP NEEDED.' : 'EXCEEDS THRESHOLD -- MAX_COMMIT_GROUPS REQUIRED.'
			)
		);

		$this->assertLessThan( 10.0, $m['elapsed'], 'Per §24\'s decision rule: the 100/100 worst case must clear ~10s, or MAX_COMMIT_GROUPS must be introduced.' );
	}
}
