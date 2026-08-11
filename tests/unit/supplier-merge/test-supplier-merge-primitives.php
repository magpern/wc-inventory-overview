<?php
/**
 * WP-M17-2: Unit tests for supplier merge repository primitives.
 *
 * Tests the low-level read/write methods with explicit SQL-failure-vs-zero-rows contracts.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Primitives extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Suppliers::get_for_update() returns the row and is valid inside a transaction.
	 */
	public function test_get_for_update_returns_row_inside_transaction() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Test Supplier 1' );

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		$txn->begin();

		$row = WC_Inventory_Overview_Suppliers::get_for_update( $s1 );
		$this->assertIsArray( $row );
		$this->assertSame( $s1, (int) $row['id'] );

		$txn->commit();
	}

	/**
	 * Suppliers::get_for_update() returns WP_Error for missing supplier.
	 */
	public function test_get_for_update_not_found() {
		global $wpdb;

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		$txn->begin();

		$result = WC_Inventory_Overview_Suppliers::get_for_update( 99999 );
		$this->assertWPError( $result );

		$txn->commit();
	}

	/**
	 * Suppliers::reactivate() returns false for a merged supplier.
	 */
	public function test_reactivate_rejects_merged_supplier() {
		$s1 = $this->create_supplier( 'Supplier 1' );
		$s2 = $this->create_supplier( 'Supplier 2' );

		// Manually mark s1 as merged (simulating a prior merge).
		global $wpdb;
		$wpdb->update(
			WC_Inventory_Overview_Suppliers::table_name(),
			array(
				'status'                  => 'archived',
				'merged_into_supplier_id' => $s2,
			),
			array( 'id' => $s1 )
		);

		// Attempt to reactivate the merged supplier.
		$result = WC_Inventory_Overview_Suppliers::reactivate( $s1 );
		$this->assertFalse( $result );
	}

	/**
	 * Suppliers::reactivate() still works for ordinary archived suppliers.
	 */
	public function test_reactivate_works_for_ordinary_archived() {
		$s1 = $this->create_supplier( 'Test Supplier' );
		WC_Inventory_Overview_Suppliers::archive( $s1 );

		$result = WC_Inventory_Overview_Suppliers::reactivate( $s1 );
		$this->assertTrue( $result );

		$supplier = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'active', $supplier['status'] );
	}

	/**
	 * Suppliers::mark_merged() updates the row correctly.
	 */
	public function test_mark_merged_updates_row() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
		$txn->begin();

		WC_Inventory_Overview_Suppliers::get_for_update( $s1 );
		$result = WC_Inventory_Overview_Suppliers::mark_merged( $s1, $s2 );
		$this->assertTrue( $result );

		$txn->commit();

		$supplier = WC_Inventory_Overview_Suppliers::get( $s1 );
		$this->assertSame( 'archived', $supplier['status'] );
		$this->assertSame( $s2, (int) $supplier['merged_into_supplier_id'] );
	}

	/**
	 * Suppliers::get_names_bulk() returns ID => name map in one query.
	 */
	public function test_get_names_bulk_returns_map() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Supplier One' );
		$s2 = $this->create_supplier( 'Supplier Two' );
		$s3 = $this->create_supplier( 'Supplier Three' );

		$before_queries = $wpdb->num_queries;

		$map = WC_Inventory_Overview_Suppliers::get_names_bulk( array( $s1, $s2, $s3 ) );

		$after_queries = $wpdb->num_queries;
		$this->assertSame( 1, $after_queries - $before_queries, 'get_names_bulk should execute exactly 1 query' );

		$this->assertSame( array( $s1 => 'Supplier One', $s2 => 'Supplier Two', $s3 => 'Supplier Three' ), $map );
	}

	/**
	 * Suppliers::get_names_bulk() handles empty ID list.
	 */
	public function test_get_names_bulk_empty_list() {
		$map = WC_Inventory_Overview_Suppliers::get_names_bulk( array() );
		$this->assertSame( array(), $map );
	}

	/**
	 * Purchase_Orders::reassign_supplier_bulk() updates all matching rows.
	 */
	public function test_po_reassign_supplier_bulk() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		// Create test POs with s1 as supplier.
		$po1 = $wpdb->insert_id;
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
			WC_Inventory_Overview_Purchase_Orders::table_name(),
			array(
				'po_number'   => 'PO-002',
				'supplier_id' => $s1,
				'currency'    => 'EUR',
				'status'      => 'received',
			)
		);
		$po2 = (int) $wpdb->insert_id;

		// Reassign both to s2.
		$count = WC_Inventory_Overview_Purchase_Orders::reassign_supplier_bulk( $s1, $s2 );
		$this->assertSame( 2, $count );

		// Verify both now point to s2.
		$po1_row = WC_Inventory_Overview_Purchase_Orders::get( $po1 );
		$this->assertSame( $s2, (int) $po1_row['supplier_id'] );

		$po2_row = WC_Inventory_Overview_Purchase_Orders::get( $po2 );
		$this->assertSame( $s2, (int) $po2_row['supplier_id'] );
	}

	/**
	 * Purchase_Orders::reassign_supplier_bulk() returns 0 when no rows match.
	 */
	public function test_po_reassign_supplier_bulk_no_rows() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$count = WC_Inventory_Overview_Purchase_Orders::reassign_supplier_bulk( $s1, $s2 );
		$this->assertSame( 0, $count );
	}

	/**
	 * Goods_Receipts::reassign_supplier_bulk() updates all matching rows.
	 */
	public function test_gr_reassign_supplier_bulk() {
		global $wpdb;

		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		// Create test receipts with s1 as supplier.
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

		$wpdb->insert(
			WC_Inventory_Overview_Goods_Receipts::table_name(),
			array(
				'receipt_number' => 'GR-002',
				'supplier_id'    => $s1,
				'currency'       => 'EUR',
				'status'         => 'draft',
			)
		);
		$gr2 = (int) $wpdb->insert_id;

		// Reassign both to s2.
		$count = WC_Inventory_Overview_Goods_Receipts::reassign_supplier_bulk( $s1, $s2 );
		$this->assertSame( 2, $count );

		// Verify both now point to s2.
		$gr1_row = WC_Inventory_Overview_Goods_Receipts::get( $gr1 );
		$this->assertSame( $s2, (int) $gr1_row['supplier_id'] );

		$gr2_row = WC_Inventory_Overview_Goods_Receipts::get( $gr2 );
		$this->assertSame( $s2, (int) $gr2_row['supplier_id'] );
	}

	/**
	 * Supplier_Merges::add() inserts a record and returns the new ID.
	 */
	public function test_supplier_merges_add() {
		$s1 = $this->create_supplier( 'Source' );
		$s2 = $this->create_supplier( 'Target' );

		$result = WC_Inventory_Overview_Supplier_Merges::add(
			array(
				'source_supplier_id'            => $s1,
				'source_supplier_name_snapshot' => 'Source',
				'target_supplier_id'            => $s2,
				'target_supplier_name_snapshot' => 'Target',
				'purchase_orders_reassigned'    => 5,
				'goods_receipts_reassigned'     => 3,
				'performed_by'                  => 1,
			)
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		// Verify the record was inserted.
		$row = WC_Inventory_Overview_Supplier_Merges::get( $result );
		$this->assertSame( $s1, (int) $row['source_supplier_id'] );
		$this->assertSame( $s2, (int) $row['target_supplier_id'] );
		$this->assertSame( 5, (int) $row['purchase_orders_reassigned'] );
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
}
