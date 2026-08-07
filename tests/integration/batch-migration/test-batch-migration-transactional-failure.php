<?php
/**
 * Invariant M6-1 (one transaction per batch, never one for a whole run):
 * forcing a failure for one batch must leave that batch's transaction fully
 * rolled back (zero receipt/line rows, migrated_receipt_id still NULL),
 * while a batch already migrated earlier in the same "run" (a loop of
 * independent migrate_batch() calls, exactly as the CLI performs it)
 * remains fully committed — proving there is no shared/outer transaction
 * spanning multiple batches.
 *
 * The failure is forced deterministically by exhausting
 * Goods_Receipt_Numbering::allocate()'s MAX_RETRIES=3 collision-retry budget
 * for the batch's target year — no wpdb mocking required, and the failure
 * surfaces exactly where the plan says any fallible call inside the
 * transaction closure must: bridged through throw_if_error() into a rollback.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Transactional_Failure extends WC_Inventory_Overview_Test_Case {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Pre-occupy the next three receipt numbers for the current year, so
	 * Goods_Receipt_Numbering::allocate()'s three retry attempts each collide
	 * and it returns WP_Error('wc_io_gr_number_exhausted') — deterministically
	 * failing migrate_batch() before any receipt/line row is written.
	 */
	private function exhaust_receipt_number_retries_for_current_year(): void {
		$year    = (int) gmdate( 'Y' );
		$current = WC_Inventory_Overview_Goods_Receipt_Numbering::peek_next_sequence( $year ) - 1;

		global $wpdb;
		for ( $i = 1; $i <= 3; $i++ ) {
			$number = WC_Inventory_Overview_Goods_Receipt_Numbering::format( $year, $current + $i );
			$wpdb->insert(
				WC_Inventory_Overview_Goods_Receipts::table_name(),
				array(
					'receipt_number' => $number,
					'status'         => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT,
					'source'         => WC_Inventory_Overview_Goods_Receipts::SOURCE_DIRECT,
					'currency'       => 'EUR',
					'created_by'     => $this->admin_id,
					'updated_by'     => $this->admin_id,
					'created_at'     => current_time( 'mysql', true ),
					'updated_at'     => current_time( 'mysql', true ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
			);
		}
	}

	public function test_forced_failure_rolls_back_the_failed_batch_completely() {
		$fixture = $this->create_legacy_batch();
		$this->exhaust_receipt_number_retries_for_current_year();

		$before_receipt_count = WC_Inventory_Overview_Goods_Receipts::count();
		$before_line_count    = $this->count_rows( WC_Inventory_Overview_Receipt_Lines::table_name() );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertWPError( $result, 'Exhausted receipt-number retries must surface as a WP_Error, not a silent partial write.' );

		$this->assertSame( $before_receipt_count, WC_Inventory_Overview_Goods_Receipts::count(), 'Failed migration must insert zero receipt rows.' );
		$this->assertSame( $before_line_count, $this->count_rows( WC_Inventory_Overview_Receipt_Lines::table_name() ), 'Failed migration must insert zero line rows.' );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_receipt_id FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $fixture['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNull( $row['migrated_receipt_id'], 'A rolled-back migration must leave migrated_receipt_id NULL.' );
	}

	public function test_earlier_successfully_migrated_batches_remain_committed_after_a_later_failure() {
		$earlier = $this->create_legacy_batch();
		$later   = $this->create_legacy_batch();

		$ok = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $earlier['batch_id'] );
		$this->assertIsArray( $ok, is_wp_error( $ok ) ? $ok->get_error_message() : '' );

		$this->exhaust_receipt_number_retries_for_current_year();
		$failed = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $later['batch_id'] );
		$this->assertWPError( $failed );

		// The earlier batch's own transaction already committed before the
		// later one even began — a shared/outer transaction would have rolled
		// BOTH back, which is exactly what Invariant M6-1 rules out.
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_receipt_id FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $earlier['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotNull( $row['migrated_receipt_id'], 'An earlier, already-committed migration must survive a later batch\'s failure.' );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( (int) $row['migrated_receipt_id'] );
		$this->assertIsArray( $receipt );
	}

	public function test_a_rerun_after_the_forced_failure_is_resolved_migrates_successfully() {
		$fixture = $this->create_legacy_batch();
		$this->exhaust_receipt_number_retries_for_current_year();

		$failed = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertWPError( $failed );

		// Resolve the collision (e.g. an operator deletes the conflicting
		// draft receipts) and rerun — resumability after a real interruption.
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() . " WHERE status = 'draft'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$retry = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $retry, is_wp_error( $retry ) ? $retry->get_error_message() : '' );
	}

	/**
	 * @param string $table Full table name.
	 */
	private function count_rows( string $table ): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
