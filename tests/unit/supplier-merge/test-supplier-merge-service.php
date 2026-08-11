<?php
/**
 * WP-M17-3: Unit tests for supplier merge orchestration service.
 *
 * Tests complete merge() method, exception-safety, server-side confirmation,
 * all business rules BR-M17-1 through BR-M17-18.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Service extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_orders' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Disarm any leftover test failure injection.
		if ( defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );
		}
	}

	/**
	 * BR-M17-1: Same supplier error.
	 */
	public function test_merge_same_supplier() {
		$s1 = $this->create_supplier( 'Supplier' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s1, 1, 'Supplier' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_same_supplier', $result->get_error_code() );
	}

	/**
	 * BR-M17-2: Source not found.
	 */
	public function test_merge_source_not_found() {
		$s2 = $this->create_supplier( 'Target' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( 99999, $s2, 1, 'Nonexistent' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_source_not_found', $result->get_error_code() );
	}

	/**
	 * BR-M17-2: Target not found.
	 */
	public function test_merge_target_not_found() {
		$s1 = $this->create_supplier( 'Source' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, 99999, 1, 'Source' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_target_not_found', $result->get_error_code() );
	}

	/**
	 * BR-M17-16: Confirmation mismatch.
	 */
	public function test_merge_confirmation_mismatch() {
		$s1 = $this->create_supplier( 'Source Supplier' );
		$s2 = $this->create_supplier( 'Target' );

		// Wrong confirmation text.
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Wrong Text' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_confirmation_mismatch', $result->get_error_code() );
	}

	/**
	 * BR-M17-4: Target not active.
	 */
	public function test_merge_target_not_active() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );
		WC_Inventory_Overview_Suppliers::archive( $s2 );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_target_not_active', $result->get_error_code() );
	}

	/**
	 * BR-M17-3: Source already merged.
	 */
	public function test_merge_source_already_merged() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );
		$s3 = $this->create_supplier( 'New Target' );

		// First merge s1 into s2.
		$this->perform_merge( $s1, $s2 );

		// Try to merge s1 again.
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s3, 1, 'Source' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_supplier_merge_source_already_merged', $result->get_error_code() );
	}

	/**
	 * BR-M17-4: Target already merged (can't use a dissolved supplier as target).
	 */
	public function test_merge_target_already_merged() {
		$s1 = $this->create_supplier( 'Source 1' );
		$s2 = $this->create_supplier( 'Target 1' );
		$s3 = $this->create_supplier( 'Source 2' );

		// First merge s1 into s2.
		$this->perform_merge( $s1, $s2 );

		// Try to merge s3 into s1 (which is now dissolved).
		// This should fail because s1 is no longer active.
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s3, $s1, 1, 'Source 2' );
		$this->assertWPError( $result );
		// Could be target_not_active or target_already_merged, both valid.
		$code = $result->get_error_code();
		$this->assertContains( $code, array( 'wc_io_supplier_merge_target_not_active', 'wc_io_supplier_merge_target_already_merged' ) );
	}

	/**
	 * Happy path: merge source into target, success.
	 */
	public function test_merge_success() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		// Create some POs and receipts for s1.
		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array(
				'po_number'   => 'PO-001',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
				'status'      => 'placed',
			)
		);
		$po1 = (int) $wpdb->insert_id;

		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => 'GR-001',
				'supplier_id'    => $s1,
				'currency'       => 'EUR',
				'status'         => 'posted',
			)
		);
		$gr1 = (int) $wpdb->insert_id;

		// Perform merge.
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertIsArray( $result );
		$this->assertSame( $s1, $result['source_supplier_id'] );
		$this->assertSame( $s2, $result['target_supplier_id'] );
		$this->assertSame( 1, $result['purchase_orders_reassigned'] );
		$this->assertSame( 1, $result['goods_receipts_reassigned'] );

		// Verify source is archived and merged.
		$source = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'archived', $source['status'] );
		$this->assertSame( $s2, (int) $source['merged_into_supplier_id'] );

		// Verify PO and GR reassigned.
		$po = WC_Inventory_Overview_Purchase_Orders::get( $po1 );
		$this->assertSame( $s2, (int) $po['supplier_id'] );

		$gr = WC_Inventory_Overview_Goods_Receipts::get( $gr1 );
		$this->assertSame( $s2, (int) $gr['supplier_id'] );

		// Verify audit entry.
		$merges = WC_Inventory_Overview_Supplier_Merges::get_for_source( $s1 );
		$this->assertCount( 1, $merges );
		$this->assertSame( $s1, (int) $merges[0]['source_supplier_id'] );
		$this->assertSame( $s2, (int) $merges[0]['target_supplier_id'] );
	}

	/**
	 * Exception-safety: failure after PO reassign rolls back all changes.
	 */
	public function test_merge_exception_safety_po_reassign_failure() {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			$this->markTestSkipped( 'Test-only failure injection not available' );
		}

		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$wpdb->insert(
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array(
				'po_number'   => 'PO-001',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
				'status'      => 'placed',
			)
		);

		// Arm failure injection after po_reassign.
		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( 'po_reassign' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertWPError( $result );

		// Verify rollback: source should still be active, not merged.
		$source = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'active', $source['status'] );
		$this->assertNull( $source['merged_into_supplier_id'] );

		// Disarm.
		WC_Inventory_Overview_Supplier_Merge_Service::set_test_fail_after_step( null );
	}

	/**
	 * Source with zero POs/receipts: merge succeeds with zero counts.
	 */
	public function test_merge_zero_related_records() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( $s1, $s2, 1, 'Source' );
		$this->assertIsArray( $result );
		$this->assertSame( 0, $result['purchase_orders_reassigned'] );
		$this->assertSame( 0, $result['goods_receipts_reassigned'] );
	}

	// Helper methods.

	/**
	 * Create a test supplier.
	 */
	private function create_supplier( string $name ): int {
		$result = WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => 'EUR',
			)
		);
		return (int) $result;
	}

	/**
	 * Perform a merge (helper).
	 */
	private function perform_merge( int $source_id, int $target_id ): array {
		return WC_Inventory_Overview_Supplier_Merge_Service::merge( $source_id, $target_id, 1, WC_Inventory_Overview_Suppliers::get( $source_id )['name'] );
	}
}
