<?php
/**
 * M6 measurable performance criterion: migrating many independent batches
 * must not introduce N+1/cross-batch query growth — Batch_Migration_Service
 * never reads current stock, current cost, or any other batch's rows
 * (Invariant M6-2), so per-batch query cost must stay constant regardless of
 * how many other batches already exist or have been migrated. Modeled on
 * tests/integration/po-receiving/test-po-receiving-performance.php's
 * query-counting approach.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Performance extends WC_Inventory_Overview_Test_Case {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Create $n legacy batches (mixed currencies), migrate all of them, and
	 * return the total query count for the migration loop only (fixture
	 * creation is excluded from the count).
	 *
	 * @param int $n Batch count.
	 * @return int Total query count for migrating all $n batches.
	 */
	private function migrate_n_batches_and_count_queries( int $n ): int {
		$currencies = array( 'EUR', 'USD', 'SEK' );
		$ids        = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$fixture = $this->create_legacy_batch(
				array(
					'currency'  => $currencies[ $i % 3 ],
					'fx_rate'   => 'EUR' === $currencies[ $i % 3 ] ? '1' : '0.9',
					'qty'       => 2,
					'line_cost' => 20,
				)
			);
			$ids[] = $fixture['batch_id'];
		}

		$count   = 0;
		$counter = static function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};

		add_filter( 'query', $counter );
		foreach ( $ids as $id ) {
			$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $id );
			if ( is_wp_error( $result ) ) {
				remove_filter( 'query', $counter );
				$this->fail( 'migrate_batch failed during performance test: ' . $result->get_error_message() );
			}
		}
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * Per-batch average query cost must not grow as the total batch count
	 * grows 5x (20 -> 100, matching the M5 performance-test scale
	 * precedent) — proving migration cost is driven by each batch's own
	 * (small, fixed) row count, never by how many other batches exist.
	 */
	public function test_per_batch_query_cost_does_not_grow_with_total_batch_count() {
		$q20  = $this->migrate_n_batches_and_count_queries( 20 );
		$avg20 = $q20 / 20;

		$q100  = $this->migrate_n_batches_and_count_queries( 100 );
		$avg100 = $q100 / 100;

		// Generous headroom (2x) to absorb fixed per-run overhead while still
		// failing hard on genuine O(total_batches) or worse scaling.
		$this->assertLessThanOrEqual(
			$avg20 * 2,
			$avg100,
			"Per-batch query cost grew with total batch count: 20 batches averaged {$avg20} queries/batch, 100 batches averaged {$avg100} queries/batch."
		);
	}

	/**
	 * Listing eligible/migrated batch ids is a single bounded query
	 * regardless of how many rows exist — the CLI's batch-enumeration step
	 * has no N+1 risk even at the plan's named 200+ scale, verified here via
	 * direct fixture rows (bypassing full product/batch-apply creation, which
	 * is unnecessary to prove a listing query's own cost is O(1)).
	 */
	public function test_listing_eligible_batches_is_a_single_query_at_200_plus_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';
		for ( $i = 0; $i < 220; $i++ ) {
			$wpdb->insert( $table, array( 'batch_total' => '1.0000', 'user_id' => $this->admin_id ), array( '%s', '%d' ) );
		}

		$count   = 0;
		$counter = static function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};
		add_filter( 'query', $counter );
		$ids = WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids();
		remove_filter( 'query', $counter );

		$this->assertCount( 220, $ids );
		$this->assertSame( 1, $count, 'Listing eligible batch ids must be exactly one query regardless of row count.' );
	}
}
