<?php
/**
 * M17: narrow, dedicated v10 -> v11 upgrade test (merged_into_supplier_id column
 * and wc_io_supplier_merges table only) — complements the broader schema-shape test in
 * tests/integration/install/test-schema-shape-assertion.php.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Schema_V11_Upgrade extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_supplier_merges' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_suppliers' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Starting from a fully-v10 schema (merged_into_supplier_id column and
	 * wc_io_supplier_merges table absent, everything else present), upgrading
	 * adds exactly those new elements.
	 */
	public function test_v10_to_v11_upgrade_adds_only_merge_columns_and_table() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$merges_table    = $wpdb->prefix . 'wc_io_supplier_merges';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP COLUMN merged_into_supplier_id" );
		$wpdb->query( "DROP TABLE IF EXISTS {$merges_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'wc_io_db_version', '10' );

		$before_suppliers_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$suppliers_table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotContains( 'merged_into_supplier_id', $before_suppliers_columns );

		$merges_exists_before = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $merges_table ) ) );
		$this->assertNull( $merges_exists_before );

		WC_Inventory_Overview_Install::maybe_upgrade();

		$this->assertSame( '11', get_option( 'wc_io_db_version' ) );
		$after_suppliers_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$suppliers_table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'merged_into_supplier_id', $after_suppliers_columns );

		$merges_exists_after = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $merges_table ) ) );
		$this->assertSame( $merges_table, $merges_exists_after );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * New merged_into_supplier_id column defaults to NULL for existing
	 * (pre-M17) supplier rows — the upgrade never guesses a merge state
	 * for history it hasn't processed yet.
	 */
	public function test_existing_supplier_rows_get_null_merged_into_supplier_id_on_upgrade() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$table = $wpdb->prefix . 'wc_io_suppliers';

		$wpdb->insert(
			$table,
			array(
				'name'               => 'Pre-M17 Supplier',
				'normalized_name'    => 'pre-m17-supplier',
				'default_currency'   => 'EUR',
				'status'             => 'active',
			),
			array( '%s', '%s', '%s', '%s' )
		);
		$supplier_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN merged_into_supplier_id" );
		update_option( 'wc_io_db_version', '10' );

		WC_Inventory_Overview_Install::maybe_upgrade();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT merged_into_supplier_id FROM {$table} WHERE id = %d", $supplier_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNull( $row['merged_into_supplier_id'] );
	}

	/**
	 * The dispatcher trap M4/M5/M6 already flagged applies identically at
	 * v10/v11: forgetting the version_compare( $version, '11', '>=' ) branch
	 * would silently route DB_VERSION 11 to expected_schema_v10(), which
	 * never asserts the new column or table — verified here not to happen.
	 */
	public function test_dispatcher_routes_v11_to_v11_assertion_not_v10() {
		$dispatcher = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema' );
		$dispatcher->setAccessible( true );

		$v11 = $dispatcher->invoke( null, '11' );
		$this->assertContains( 'wc_io_supplier_merges', $v11['tables'] );
		$this->assertContains( 'merged_into_supplier_id', $v11['columns']['suppliers'] );

		$v10 = $dispatcher->invoke( null, '10' );
		$this->assertNotContains( 'wc_io_supplier_merges', $v10['tables'] );
		$this->assertArrayNotHasKey( 'suppliers', $v10['columns'], 'DB_VERSION 10 must not assert suppliers columns.' );
	}

	/**
	 * Fresh install at v11 and upgrade-from-v10 produce an identical final schema.
	 */
	public function test_fresh_install_and_upgrade_produce_identical_schema() {
		global $wpdb;

		$suppliers_table = $wpdb->prefix . 'wc_io_suppliers';
		$merges_table    = $wpdb->prefix . 'wc_io_supplier_merges';

		WC_Inventory_Overview_Install::create_tables();
		$fresh_suppliers_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$suppliers_table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $fresh_suppliers_columns );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$suppliers_table} DROP COLUMN merged_into_supplier_id" );
		$wpdb->query( "DROP TABLE IF EXISTS {$merges_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'wc_io_db_version', '10' );
		WC_Inventory_Overview_Install::maybe_upgrade();

		$upgraded_suppliers_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$suppliers_table}" ), 'Field' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		sort( $upgraded_suppliers_columns );

		$this->assertSame( $fresh_suppliers_columns, $upgraded_suppliers_columns );
	}

	/**
	 * Fresh install with zero suppliers and zero merges: DB_VERSION 11,
	 * schema-shape assertion passes.
	 */
	public function test_fresh_install_zero_suppliers_schema_assertion_passes() {
		WC_Inventory_Overview_Install::create_tables();
		update_option( 'wc_io_db_version', WC_Inventory_Overview_Install::DB_VERSION );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}
}
