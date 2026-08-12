<?php
/**
 * M6: narrow, dedicated v9 -> v10 upgrade test (migrated_receipt_id/
 * migrated_at columns only) — complements the broader schema-shape test in
 * tests/integration/install/test-schema-shape-assertion.php.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Schema_V10_Upgrade extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Starting from a fully-v9 schema (migration tracking columns absent,
	 * everything else present), upgrading adds exactly those two columns.
	 */
	public function test_v9_to_v10_upgrade_adds_only_tracking_columns() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$table = $wpdb->prefix . 'wc_io_purchase_batches';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN migrated_receipt_id, DROP COLUMN migrated_at" );
		update_option( 'wc_io_db_version', '9' );

		$before_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotContains( 'migrated_receipt_id', $before_columns );
		$this->assertNotContains( 'migrated_at', $before_columns );

		WC_Inventory_Overview_Install::maybe_upgrade();

		$this->assertSame( WC_Inventory_Overview_Install::DB_VERSION, get_option( 'wc_io_db_version' ) );
		$after_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'migrated_receipt_id', $after_columns );
		$this->assertContains( 'migrated_at', $after_columns );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * Tracking columns default to NULL for existing (pre-M6) batch rows —
	 * the upgrade never guesses a migration state for history it hasn't
	 * processed yet.
	 */
	public function test_existing_rows_get_null_tracking_columns_on_upgrade() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$table = $wpdb->prefix . 'wc_io_purchase_batches';

		$wpdb->insert(
			$table,
			array(
				'supplier_name' => 'Pre-M6 Supplier',
				'batch_total'   => '10.0000',
				'user_id'       => 1,
			),
			array( '%s', '%s', '%d' )
		);
		$batch_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN migrated_receipt_id, DROP COLUMN migrated_at" );
		update_option( 'wc_io_db_version', '9' );

		WC_Inventory_Overview_Install::maybe_upgrade();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT migrated_receipt_id, migrated_at FROM {$table} WHERE id = %d", $batch_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNull( $row['migrated_receipt_id'] );
		$this->assertNull( $row['migrated_at'] );
	}

	/**
	 * The dispatcher trap M4/M5 already flagged applies identically at
	 * v9/v10: forgetting the version_compare( $version, '10', '>=' ) branch
	 * would silently route DB_VERSION 10 to expected_schema_v9(), which
	 * never asserts the two new columns — verified here not to happen.
	 */
	public function test_dispatcher_routes_v10_to_v10_assertion_not_v9() {
		$dispatcher = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema' );
		$dispatcher->setAccessible( true );

		$v10 = $dispatcher->invoke( null, '10' );
		$this->assertContains( 'migrated_receipt_id', $v10['columns']['purchase_batches'] );
		$this->assertContains( 'migrated_at', $v10['columns']['purchase_batches'] );

		$v9 = $dispatcher->invoke( null, '9' );
		$this->assertArrayNotHasKey( 'purchase_batches', $v9['columns'], 'DB_VERSION 9 must not assert the v10-only tracking columns.' );
	}

	/**
	 * Fresh install at v10 and upgrade-from-v9 produce an identical final schema.
	 */
	public function test_fresh_install_and_upgrade_produce_identical_schema() {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_io_purchase_batches';

		WC_Inventory_Overview_Install::create_tables();
		$fresh_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $fresh_columns );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN migrated_receipt_id, DROP COLUMN migrated_at" );
		update_option( 'wc_io_db_version', '9' );
		WC_Inventory_Overview_Install::maybe_upgrade();
		$upgraded_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $upgraded_columns );

		$this->assertSame( $fresh_columns, $upgraded_columns );
	}

	/**
	 * Fresh install with zero batches: DB_VERSION 10, schema-shape assertion
	 * passes, and there is nothing to migrate.
	 */
	public function test_fresh_install_zero_batches_nothing_to_migrate() {
		WC_Inventory_Overview_Install::create_tables();
		update_option( 'wc_io_db_version', WC_Inventory_Overview_Install::DB_VERSION );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
		$this->assertSame( array(), WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
		$this->assertSame( 0, WC_Inventory_Overview_Batch_Migration_Service::count_all_batches() );
	}
}
