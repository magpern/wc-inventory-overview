<?php
/**
 * Integration tests for supplier CRUD operations.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_CRUD extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Test creating a supplier.
	 */
	public function test_create_supplier() {
		$result = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		// Verify it was actually created.
		$supplier = WC_Inventory_Overview_Suppliers::get( $result );
		$this->assertIsArray( $supplier );
		$this->assertEquals( 'Test Supplier', $supplier['name'] );
		$this->assertEquals( 'test supplier', $supplier['normalized_name'] );
		$this->assertEquals( 'active', $supplier['status'] );
	}

	/**
	 * Test duplicate name detection.
	 */
	public function test_duplicate_detection() {
		$first = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );
		$this->assertIsInt( $first );

		$second = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );
		$this->assertWPError( $second );
		$this->assertEquals( 'wc_io_supplier_duplicate', $second->get_error_code() );
	}

	/**
	 * Test get_by_normalized_name.
	 */
	public function test_get_by_normalized_name() {
		$id = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Nature  Supply',
			'default_currency' => 'USD',
		) );

		$supplier = WC_Inventory_Overview_Suppliers::get_by_normalized_name( 'nature supply' );
		$this->assertIsArray( $supplier );
		$this->assertEquals( $id, $supplier['id'] );
	}

	/**
	 * Test get_by_normalized_name returns null for missing.
	 */
	public function test_get_by_normalized_name_not_found() {
		$result = WC_Inventory_Overview_Suppliers::get_by_normalized_name( 'nonexistent' );
		$this->assertNull( $result );
	}

	/**
	 * Test updating a supplier.
	 */
	public function test_update_supplier() {
		$id = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );

		$result = WC_Inventory_Overview_Suppliers::update( $id, array(
			'name'                   => 'Updated Name',
			'default_lead_time_days' => 7,
			'email'                  => 'test@example.com',
		) );

		$this->assertTrue( $result );

		$supplier = WC_Inventory_Overview_Suppliers::get( $id );
		$this->assertEquals( 'Updated Name', $supplier['name'] );
		$this->assertEquals( 7, $supplier['default_lead_time_days'] );
		$this->assertEquals( 'test@example.com', $supplier['email'] );
	}

	/**
	 * Test archiving a supplier.
	 */
	public function test_archive_supplier() {
		$id = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );

		$result = WC_Inventory_Overview_Suppliers::archive( $id );
		$this->assertTrue( $result );

		$supplier = WC_Inventory_Overview_Suppliers::get( $id );
		$this->assertEquals( 'archived', $supplier['status'] );
	}

	/**
	 * Test reactivating a supplier.
	 */
	public function test_reactivate_supplier() {
		$id = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );

		WC_Inventory_Overview_Suppliers::archive( $id );
		$result = WC_Inventory_Overview_Suppliers::reactivate( $id );
		$this->assertTrue( $result );

		$supplier = WC_Inventory_Overview_Suppliers::get( $id );
		$this->assertEquals( 'active', $supplier['status'] );
	}

	/**
	 * Test list with status filter.
	 */
	public function test_list_with_status_filter() {
		WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Active Supplier',
			'default_currency' => 'EUR',
		) );

		$id_archived = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Archived Supplier',
			'default_currency' => 'EUR',
		) );
		WC_Inventory_Overview_Suppliers::archive( $id_archived );

		$active = WC_Inventory_Overview_Suppliers::list( array( 'status' => 'active' ) );
		$this->assertCount( 1, $active );
		$this->assertEquals( 'Active Supplier', $active[0]['name'] );

		$archived = WC_Inventory_Overview_Suppliers::list( array( 'status' => 'archived' ) );
		$this->assertCount( 1, $archived );
		$this->assertEquals( 'Archived Supplier', $archived[0]['name'] );
	}

	/**
	 * Test wc_io_supplier_created action fires.
	 */
	public function test_supplier_created_action_fires() {
		$action_called = false;
		$action_data   = null;

		add_action( 'wc_io_supplier_created', function( $id, $supplier ) use ( &$action_called, &$action_data ) {
			$action_called = true;
			$action_data   = array( 'id' => $id, 'supplier' => $supplier );
		}, 10, 2 );

		$id = WC_Inventory_Overview_Suppliers::create( array(
			'name'             => 'Test Supplier',
			'default_currency' => 'EUR',
		) );

		$this->assertTrue( $action_called );
		$this->assertEquals( $id, $action_data['id'] );
		$this->assertEquals( 'Test Supplier', $action_data['supplier']['name'] );
	}
}
