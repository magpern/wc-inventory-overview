<?php
/**
 * Integration tests for M22 WP-M22-1:
 * WC_Inventory_Overview_Suppliers::is_eligible_for_selection() and
 * WC_Inventory_Overview_Suppliers::list_by_ids().
 *
 * Verifies the supplier-eligibility predicate (active, not merged) and,
 * critically, that list_by_ids() issues exactly 1 query regardless of how
 * many ids are requested (INV-M22-16) -- the mechanism that keeps M22's
 * New-PO supplier resolution from becoming an N+1 Suppliers::get() loop.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_Eligibility_And_Bulk_Fetch extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_active_unmerged_supplier_is_eligible() {
		$supplier = $this->create_supplier();
		$this->assertTrue( WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier ) );
	}

	public function test_archived_supplier_is_ineligible() {
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );
		$refetched = WC_Inventory_Overview_Suppliers::get( $supplier['id'] );

		$this->assertFalse( WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $refetched ) );
	}

	public function test_merged_supplier_is_ineligible_regardless_of_status() {
		$source = $this->create_supplier();
		$target = $this->create_supplier();

		WC_Inventory_Overview_Suppliers::mark_merged( $source['id'], $target['id'] );
		$refetched = WC_Inventory_Overview_Suppliers::get( $source['id'] );

		$this->assertSame( WC_Inventory_Overview_Suppliers::STATUS_ARCHIVED, $refetched['status'] );
		$this->assertFalse( WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $refetched ) );
	}

	public function test_malformed_input_is_ineligible_no_exception() {
		$this->assertFalse( WC_Inventory_Overview_Suppliers::is_eligible_for_selection( array() ) );
		$this->assertFalse( WC_Inventory_Overview_Suppliers::is_eligible_for_selection( array( 'status' => 'bogus' ) ) );
	}

	public function test_list_by_ids_returns_rows_keyed_by_id() {
		$a = $this->create_supplier();
		$b = $this->create_supplier();

		$result = WC_Inventory_Overview_Suppliers::list_by_ids( array( $a['id'], $b['id'] ) );

		$this->assertArrayHasKey( $a['id'], $result );
		$this->assertArrayHasKey( $b['id'], $result );
		$this->assertSame( $a['name'], $result[ $a['id'] ]['name'] );
	}

	public function test_list_by_ids_missing_ids_simply_absent() {
		$a = $this->create_supplier();

		$result = WC_Inventory_Overview_Suppliers::list_by_ids( array( $a['id'], 999999 ) );

		$this->assertArrayHasKey( $a['id'], $result );
		$this->assertArrayNotHasKey( 999999, $result );
	}

	public function test_list_by_ids_empty_input_returns_empty_no_query() {
		$this->assertSame( array(), WC_Inventory_Overview_Suppliers::list_by_ids( array() ) );
	}

	private function count_bulk_fetch_queries( array $ids ): int {
		$table   = WC_Inventory_Overview_Suppliers::table_name();
		$queries = array();
		$counter = static function ( $query ) use ( $table, &$queries ) {
			if ( false !== strpos( $query, $table ) && false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Suppliers::list_by_ids( $ids );
		remove_filter( 'query', $counter );

		return count( $queries );
	}

	/**
	 * INV-M22-16: list_by_ids() issues exactly 1 query (0 for an empty
	 * list) regardless of how many ids are requested.
	 */
	public function test_query_count_fixed_at_0_1_10_50_ids() {
		$this->assertSame( 0, $this->count_bulk_fetch_queries( array() ) );

		$ids = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$supplier = $this->create_supplier();
			$ids[]    = $supplier['id'];
		}

		$this->assertSame( 1, $this->count_bulk_fetch_queries( array( $ids[0] ) ) );
		$this->assertSame( 1, $this->count_bulk_fetch_queries( array_slice( $ids, 0, 10 ) ) );
		$this->assertSame( 1, $this->count_bulk_fetch_queries( $ids ) );
	}
}
