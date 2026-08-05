<?php
/**
 * Integration tests for schema shape assertion (M1 + M2-A generalization).
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Schema assertion tests.
 */
class Test_WC_IO_Schema_Assertion extends WP_UnitTestCase {

	/**
	 * Test assertion passes when schema is correct.
	 */
	public function test_assertion_passes_on_correct_schema() {
		WC_Inventory_Overview_Install::create_tables();

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertTrue( $result );

		$canonical = get_option( 'wc_io_schema_assertion' );
		$this->assertIsArray( $canonical );
		$this->assertTrue( $canonical['ok'] );
		$this->assertEquals( WC_Inventory_Overview_Install::DB_VERSION, $canonical['version'] );

		$versioned = get_option( 'wc_io_schema_v' . WC_Inventory_Overview_Install::DB_VERSION . '_assertion' );
		$this->assertIsArray( $versioned );
		$this->assertTrue( $versioned['ok'] );

		// Legacy mirror remains populated for runbook compatibility.
		$legacy = get_option( 'wc_io_schema_v6_assertion' );
		$this->assertIsArray( $legacy );
		$this->assertTrue( $legacy['ok'] );
	}

	/**
	 * Test assertion fails when a core table is missing.
	 */
	public function test_assertion_fails_when_table_missing() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$backup_table    = $suppliers_table . '_m2a_bak';

		// RENAME (DDL) is reliable under WP_UnitTestCase transaction wrapping; DROP was a no-op here.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix tables.
		$wpdb->query( "RENAME TABLE {$suppliers_table} TO {$backup_table}" );

		$still_there = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $suppliers_table ) ) );
		$this->assertNull( $still_there, 'Precondition: suppliers table must be renamed away before asserting' );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );
		$this->assertEquals( 'wc_io_schema_v' . WC_Inventory_Overview_Install::DB_VERSION, $result->get_error_code() );

		$assertion = get_option( 'wc_io_schema_assertion' );
		$this->assertFalse( $assertion['ok'] );

		// Restore so later tests see a clean schema.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix tables.
		$wpdb->query( "RENAME TABLE {$backup_table} TO {$suppliers_table}" );
	}

	/**
	 * Test assertion fails when column is missing.
	 */
	public function test_assertion_fails_when_column_missing() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP COLUMN email" );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'email', $message );
	}

	/**
	 * Test assertion fails when unique index is missing.
	 */
	public function test_assertion_fails_when_unique_index_missing() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP INDEX normalized_name" );

		$result = WC_Inventory_Overview_Install::assert_schema_shape();
		$this->assertWPError( $result );

		$message = $result->get_error_message();
		$this->assertStringContainsString( 'normalized_name', $message );
	}

	/**
	 * M2-A: PO tables exist after create_tables / upgrade.
	 */
	public function test_v7_purchase_order_tables_exist() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		update_option( 'wc_io_db_version', WC_Inventory_Overview_Install::DB_VERSION );

		$tables = array(
			$wpdb->prefix . 'wc_io_purchase_orders',
			$wpdb->prefix . 'wc_io_purchase_order_lines',
			$wpdb->prefix . 'wc_io_po_events',
		);

		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $exists, "Expected table {$table}" );
		}

		$this->assertEquals( '7', WC_Inventory_Overview_Install::DB_VERSION );
		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * M2-A: qty_received must not exist (receiving is M5).
	 */
	public function test_no_qty_received_column_on_po_lines() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_purchase_order_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		$fields  = array_column( $columns, 'Field' );

		$this->assertNotContains( 'qty_received', $fields );
		$this->assertContains( 'qty_ordered', $fields );
		$this->assertContains( 'qty_cancelled', $fields );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * M2-A: unique po_number index exists.
	 */
	public function test_po_number_unique_index() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_purchase_orders';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW INDEX against known prefix table.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Column_name='po_number' AND Non_unique=0" );
		$this->assertNotEmpty( $indexes );
	}

	/**
	 * M2-A: reason_code column exists on events.
	 */
	public function test_po_events_have_reason_code() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_po_events';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		$fields  = array_column( $columns, 'Field' );

		$this->assertContains( 'reason_code', $fields );
		$this->assertContains( 'summary', $fields );
		$this->assertContains( 'data', $fields );
	}
}
