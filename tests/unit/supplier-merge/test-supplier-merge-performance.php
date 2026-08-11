<?php
/**
 * WP-M17-8: Performance and internal failure-injection coverage.
 *
 * Proves INV-M17-5 (measured-constant query count at 500/2000/5000 POs,
 * via the established $wpdb->num_queries delta technique) and BR-M17-9's
 * exception-safety at all three failure-injection seam points.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Performance extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_order_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_orders' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );
		}
	}

	public function tearDown(): void {
		if ( defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );
		}
		parent::tearDown();
	}

	/**
	 * Seed one supplier with $count Purchase Orders, direct $wpdb inserts
	 * (fast at scale), mirroring
	 * tests/integration/supplier-spend/test-supplier-spend-performance.php's
	 * established fixture technique.
	 *
	 * @param int $count Number of POs to seed.
	 * @return int Supplier id.
	 */
	private function seed_supplier_with_pos( int $count ): int {
		global $wpdb;

		$supplier_id = (int) WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => 'Perf Supplier ' . $count . '-' . uniqid(),
				'default_currency' => 'EUR',
			)
		);

		for ( $i = 0; $i < $count; $i++ ) {
			$wpdb->insert(
				WC_Inventory_Overview_Purchase_Orders::table_name(),
				array(
					'po_number'   => 'PERF-M17-' . $supplier_id . '-' . $i,
					'supplier_id' => $supplier_id,
					'currency'    => 'EUR',
					'status'      => WC_Inventory_Overview_PO_Statuses::PLACED,
					'order_date'  => sprintf( '2026-01-%02d', 1 + ( $i % 28 ) ),
					'created_by'  => 0,
					'updated_by'  => 0,
				)
			);
		}

		return $supplier_id;
	}

	/**
	 * Isolate the total query count of merge() itself, via the exact
	 * $wpdb->num_queries delta technique established by
	 * tests/integration/supplier-spend/test-supplier-spend-performance.php.
	 *
	 * @param int $source_id Source supplier id.
	 * @param int $target_id Target supplier id.
	 * @return int
	 */
	private function queries_for_merge( int $source_id, int $target_id ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $source_id, $target_id, 1, WC_Inventory_Overview_Suppliers::get( $source_id )['name'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $wpdb->num_queries - $before;
	}

	/**
	 * $wpdb->insert() triggers a one-time-per-table-per-process
	 * `SHOW FULL COLUMNS FROM {table}` charset-cache warm-up on the FIRST
	 * INSERT into any given table -- standard WordPress core wpdb behavior,
	 * not specific to this plugin's code, and not a per-record cost (it
	 * never repeats for subsequent inserts into the same table within the
	 * same process). Performed once before measuring so all three fixture
	 * scales are compared from an identically-warmed baseline.
	 */
	private function warm_up_supplier_merges_charset_cache(): void {
		$s1 = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Warmup Source-' . uniqid(), 'default_currency' => 'EUR' ) );
		$s2 = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Warmup Target-' . uniqid(), 'default_currency' => 'EUR' ) );
		WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, WC_Inventory_Overview_Suppliers::get( $s1 )['name'] );
	}

	/**
	 * INV-M17-5: the measured query count is constant across fixture sizes
	 * (500/2000/5000 POs) -- not asserted equal to any pre-guessed number,
	 * only asserted equal to each other. The actual measured value is
	 * reported via the assertion message for the final implementation report.
	 */
	public function test_merge_query_count_constant_across_fixture_sizes() {
		$this->warm_up_supplier_merges_charset_cache();

		$source_500  = $this->seed_supplier_with_pos( 500 );
		$target_500  = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Target 500-' . uniqid(), 'default_currency' => 'EUR' ) );
		$count_500   = $this->queries_for_merge( $source_500, $target_500 );

		$source_2000 = $this->seed_supplier_with_pos( 2000 );
		$target_2000 = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Target 2000-' . uniqid(), 'default_currency' => 'EUR' ) );
		$count_2000  = $this->queries_for_merge( $source_2000, $target_2000 );

		$source_5000 = $this->seed_supplier_with_pos( 5000 );
		$target_5000 = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Target 5000-' . uniqid(), 'default_currency' => 'EUR' ) );
		$count_5000  = $this->queries_for_merge( $source_5000, $target_5000 );

		$message = sprintf(
			'Measured merge() query counts: 500 POs=%d, 2000 POs=%d, 5000 POs=%d',
			$count_500,
			$count_2000,
			$count_5000
		);

		$this->assertSame( $count_500, $count_2000, $message );
		$this->assertSame( $count_2000, $count_5000, $message );

		// Report the actual measured value for the final implementation report.
		fwrite( STDERR, "\n" . $message . "\n" );
	}

	/**
	 * Failure injection after po_reassign: full rollback proven by row
	 * counts/content on all four affected tables unchanged from pre-merge state.
	 */
	public function test_failure_injection_po_reassign_full_rollback() {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			$this->markTestSkipped( 'Test-only failure injection not available' );
		}

		global $wpdb;

		$source_id = $this->seed_supplier_with_pos( 5 );
		$target_id = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Rollback Target 1-' . uniqid(), 'default_currency' => 'EUR' ) );

		$po_count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( 'po_reassign' );
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $source_id, $target_id, 1, WC_Inventory_Overview_Suppliers::get( $source_id )['name'] );
		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_failed', $result->get_error_code() );

		$po_count_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$source = WC_Inventory_Overview_Suppliers::get( $source_id );

		$this->assertSame( $po_count_before, $po_count_after );
		$this->assertSame( $merges_count_before, $merges_count_after );
		$this->assertSame( 'active', $source['status'] );
		$this->assertNull( $source['merged_into_supplier_id'] );
	}

	/**
	 * Failure injection after gr_reassign: full rollback.
	 */
	public function test_failure_injection_gr_reassign_full_rollback() {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			$this->markTestSkipped( 'Test-only failure injection not available' );
		}

		global $wpdb;

		$source_id = $this->seed_supplier_with_pos( 5 );
		$target_id = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Rollback Target 2-' . uniqid(), 'default_currency' => 'EUR' ) );

		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => 'PERF-GR-' . $source_id,
				'supplier_id'    => $source_id,
				'currency'       => 'EUR',
				'status'         => WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED,
				'source'         => 'po',
				'created_by'     => 0,
				'updated_by'     => 0,
			)
		);

		$po_count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$gr_count_before  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( 'gr_reassign' );
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $source_id, $target_id, 1, WC_Inventory_Overview_Suppliers::get( $source_id )['name'] );
		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_failed', $result->get_error_code() );

		$po_count_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$gr_count_after  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$source = WC_Inventory_Overview_Suppliers::get( $source_id );

		// PO reassignment must ALSO be rolled back (single transaction, INV-M17-1).
		$this->assertSame( $po_count_before, $po_count_after );
		$this->assertSame( $gr_count_before, $gr_count_after );
		$this->assertSame( $merges_count_before, $merges_count_after );
		$this->assertSame( 'active', $source['status'] );
		$this->assertNull( $source['merged_into_supplier_id'] );
	}

	/**
	 * Failure injection after audit_insert: full rollback -- proves even the
	 * audit write itself (BR-M17-11) is inside the same all-or-nothing
	 * transaction, not committed separately.
	 */
	public function test_failure_injection_audit_insert_full_rollback() {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			$this->markTestSkipped( 'Test-only failure injection not available' );
		}

		global $wpdb;

		$source_id = $this->seed_supplier_with_pos( 5 );
		$target_id = (int) WC_Inventory_Overview_Suppliers::create( array( 'name' => 'Rollback Target 3-' . uniqid(), 'default_currency' => 'EUR' ) );

		$po_count_before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( 'audit_insert' );
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $source_id, $target_id, 1, WC_Inventory_Overview_Suppliers::get( $source_id )['name'] );
		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_failed', $result->get_error_code() );

		$po_count_after = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() . ' WHERE supplier_id = %d', $source_id ) );
		$merges_count_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$source = WC_Inventory_Overview_Suppliers::get( $source_id );

		$this->assertSame( $po_count_before, $po_count_after );
		$this->assertSame( $merges_count_before, $merges_count_after );
		$this->assertSame( 'active', $source['status'] );
		$this->assertNull( $source['merged_into_supplier_id'] );
	}
}
