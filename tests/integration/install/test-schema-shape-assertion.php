<?php
/**
 * Integration tests for schema shape assertion (M1.16).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Schema_Assertion extends WP_UnitTestCase {

	/**
	 * Test assertion passes when schema is correct.
	 */
	public function test_assertion_passes_on_correct_schema() {
		// Ensure schema is created.
		WC_Inventory_Overview_Install::create_tables();

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertTrue( $result );

		// Check option was persisted.
		$assertion = get_option( 'wc_io_schema_v6_assertion' );
		$this->assertIsArray( $assertion );
		$this->assertTrue( $assertion['ok'] );
	}

	/**
	 * Test assertion fails when table is missing.
	 */
	public function test_assertion_fails_when_table_missing() {
		global $wpdb;

		// Create schema first.
		WC_Inventory_Overview_Install::create_tables();

		// Drop the suppliers table.
		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$wpdb->query( "DROP TABLE IF EXISTS {$suppliers_table}" );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );
		$this->assertEquals( 'wc_io_schema_v6', $result->get_error_code() );

		// Check option reflects failure.
		$assertion = get_option( 'wc_io_schema_v6_assertion' );
		$this->assertFalse( $assertion['ok'] );
	}

	/**
	 * Test assertion fails when column is missing.
	 */
	public function test_assertion_fails_when_column_missing() {
		global $wpdb;

		// Create schema first.
		WC_Inventory_Overview_Install::create_tables();

		// Drop a column.
		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP COLUMN email" );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );

		// Check error message mentions the missing column.
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'email', $message );
	}

	/**
	 * Test assertion fails when unique index is missing.
	 */
	public function test_assertion_fails_when_unique_index_missing() {
		global $wpdb;

		// Create schema first.
		WC_Inventory_Overview_Install::create_tables();

		// Drop the unique index.
		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP INDEX normalized_name" );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );

		// Check error message mentions the missing index.
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'normalized_name', $message );
	}
}
