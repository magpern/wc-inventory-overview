<?php
/**
 * M5: narrow, dedicated v8 -> v9 upgrade test (qty_received column only) —
 * complements the broader v7-to-current test in
 * tests/integration/install/test-schema-shape-assertion.php.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Schema_V9_Upgrade extends WP_UnitTestCase {

	/**
	 * Starting from a fully-v8 schema (qty_received absent, everything else
	 * present), upgrading adds exactly the qty_received column and lifts the
	 * forbidden-column guard — nothing else changes.
	 */
	public function test_v8_to_v9_upgrade_adds_only_qty_received() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$table = $wpdb->prefix . 'wc_io_purchase_order_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN qty_received" );
		update_option( 'wc_io_db_version', '8' );

		$before_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotContains( 'qty_received', $before_columns );

		WC_Inventory_Overview_Install::maybe_upgrade();

		// maybe_upgrade() always targets the current DB_VERSION directly
		// (never stops partway at an intermediate version) — since M6, that's
		// '10', not '9'; qty_received (this test's own subject) still arrives
		// as part of that same single upgrade regardless.
		$this->assertSame( WC_Inventory_Overview_Install::DB_VERSION, get_option( 'wc_io_db_version' ) );
		$after_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'qty_received', $after_columns );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * The dispatcher trap M4 already flagged for v7/v8 applies identically at
	 * v8/v9: forgetting the version_compare( $version, '9', '>=' ) branch would
	 * silently route DB_VERSION 9 to expected_schema_v8(), which still lists
	 * qty_received as forbidden — verified here not to happen.
	 */
	public function test_dispatcher_routes_v9_to_v9_assertion_not_v8() {
		$dispatcher = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema' );
		$dispatcher->setAccessible( true );

		$v9 = $dispatcher->invoke( null, '9' );
		$this->assertContains( 'qty_received', $v9['columns']['purchase_order_lines'] );
		$this->assertSame( array(), $v9['forbidden_columns']['purchase_order_lines'] );

		$v8 = $dispatcher->invoke( null, '8' );
		$this->assertNotContains( 'qty_received', $v8['columns']['purchase_order_lines'] ?? array() );
		$this->assertContains( 'qty_received', $v8['forbidden_columns']['purchase_order_lines'], 'DB_VERSION 8 must still dispatch to expected_schema_v8(), unaffected by the v9 addition.' );
	}

	/**
	 * Fresh install at v9 and upgrade-from-v8 produce an identical final schema.
	 */
	public function test_fresh_install_and_upgrade_produce_identical_schema() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_order_lines';

		// Fresh install.
		WC_Inventory_Overview_Install::create_tables();
		$fresh_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $fresh_columns );

		// Simulate v8, then upgrade.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN qty_received" );
		update_option( 'wc_io_db_version', '8' );
		WC_Inventory_Overview_Install::maybe_upgrade();
		$upgraded_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $upgraded_columns );

		$this->assertSame( $fresh_columns, $upgraded_columns );
	}
}
