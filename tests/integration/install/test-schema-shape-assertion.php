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

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * M5 (schema v9): qty_received now exists as a real, maintained column.
	 * Superseded from the pre-M5 "qty_received must not exist" claim — M5
	 * implementation plan §Testing "M4 guard-revision audit".
	 */
	public function test_qty_received_column_exists_on_po_lines() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_purchase_order_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );
		$fields  = array_column( $columns, 'Field' );

		$this->assertContains( 'qty_received', $fields );
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

	/**
	 * M4: schema v8 new tables exist after create_tables (fresh install).
	 */
	public function test_v8_goods_receipt_tables_exist_fresh_install() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$tables = array(
			$wpdb->prefix . 'wc_io_goods_receipts',
			$wpdb->prefix . 'wc_io_receipt_lines',
			$wpdb->prefix . 'wc_io_receipt_costs',
		);
		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $exists, "Expected table {$table}" );
		}

		$this->assertSame( '10', WC_Inventory_Overview_Install::DB_VERSION );
		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * Upgrade path from schema v7 all the way to the current DB_VERSION (v9, M5)
	 * creates every intervening version's new tables/columns — v8's Goods Receipt
	 * tables/movement columns AND v9's qty_received column — in one upgrade,
	 * since maybe_upgrade() always targets the current DB_VERSION directly, never
	 * stopping partway at an intermediate version. Renamed and extended from the
	 * pre-M5 "v7 to v8" test, which asserted upgrading landed at DB_VERSION '8' —
	 * that assertion is no longer true now that DB_VERSION is '9' (M5 implementation
	 * plan §Testing "M4 guard-revision audit"); a dedicated, narrower v8→v9-only
	 * upgrade test lives in tests/integration/po-receiving/test-schema-v9-upgrade.php.
	 */
	public function test_v7_to_current_upgrade_path() {
		global $wpdb;

		// Simulate a site sitting at v7: create only the v7-era tables/columns.
		WC_Inventory_Overview_Install::create_tables();
		$movements_table = $wpdb->prefix . 'wc_io_inventory_movements';
		$po_lines_table   = $wpdb->prefix . 'wc_io_purchase_order_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$movements_table} DROP COLUMN reference_type, DROP COLUMN reference_id, DROP COLUMN supplier_id" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test DDL against known prefix table.
		$wpdb->query( "ALTER TABLE {$po_lines_table} DROP COLUMN qty_received" );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wc_io_goods_receipts' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wc_io_receipt_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wc_io_receipt_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( 'wc_io_db_version', '7' );

		// A v7-scoped assertion must not see the (currently absent) v8/v9 additions as missing.
		$v7_expected = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema_v7' );
		$v7_expected->setAccessible( true );
		$this->assertNotEmpty( $v7_expected->invoke( null ) );

		// Now upgrade.
		WC_Inventory_Overview_Install::maybe_upgrade();

		$this->assertSame( WC_Inventory_Overview_Install::DB_VERSION, get_option( 'wc_io_db_version' ) );

		$tables = array(
			$wpdb->prefix . 'wc_io_goods_receipts',
			$wpdb->prefix . 'wc_io_receipt_lines',
			$wpdb->prefix . 'wc_io_receipt_costs',
		);
		foreach ( $tables as $table ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertEquals( $table, $exists, "Upgrade must create {$table}" );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$movement_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$movements_table}" ), 'Field' );
		$this->assertContains( 'reference_type', $movement_columns );
		$this->assertContains( 'reference_id', $movement_columns );
		$this->assertContains( 'supplier_id', $movement_columns );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$po_lines_columns = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$po_lines_table}" ), 'Field' );
		$this->assertContains( 'qty_received', $po_lines_columns, 'Upgrade must add qty_received (v9, M5).' );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	/**
	 * M4: the expected_schema() dispatcher must route DB_VERSION 8 to
	 * expected_schema_v8(), not silently fall back to v7's assertion (which would
	 * never check the new tables/columns exist — a false-green schema gate).
	 */
	public function test_dispatcher_routes_v8_to_v8_assertion_not_v7() {
		$dispatcher = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema' );
		$dispatcher->setAccessible( true );

		$v8 = $dispatcher->invoke( null, '8' );
		$this->assertArrayHasKey( 'goods_receipts', $v8['columns'], 'DB_VERSION 8 must dispatch to expected_schema_v8(), which declares goods_receipts columns.' );
		$this->assertContains( 'wc_io_goods_receipts', $v8['tables'] );

		$v7 = $dispatcher->invoke( null, '7' );
		$this->assertArrayNotHasKey( 'goods_receipts', $v7['columns'], 'DB_VERSION 7 must still dispatch to expected_schema_v7(), unaffected by the v8 addition.' );
	}

	/**
	 * M4: unique receipt_number index exists.
	 */
	public function test_receipt_number_unique_index() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_goods_receipts';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW INDEX against known prefix table.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Column_name='receipt_number' AND Non_unique=0" );
		$this->assertNotEmpty( $indexes );
	}

	/**
	 * M5 (schema v9): qty_received is no longer forbidden — the M2/M4
	 * forbidden-column guard is intentionally lifted, the one entry M5 is
	 * permitted to change (M5 implementation plan §Database — "forbidden_columns
	 * — removed"). Superseded from the pre-M5 "still forbidden at v8" claim — M5
	 * implementation plan §Testing "M4 guard-revision audit".
	 */
	public function test_qty_received_no_longer_forbidden_at_v9() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_purchase_order_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$fields = array_column( $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ), 'Field' );
		$this->assertContains( 'qty_received', $fields );

		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );

		$v9_expected = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema_v9' );
		$v9_expected->setAccessible( true );
		$v9 = $v9_expected->invoke( null );
		$this->assertSame( array(), $v9['forbidden_columns']['purchase_order_lines'] );
	}

	/**
	 * M4: po_line_id exists on receipt_lines and is indexed, but is not a required/NOT NULL column.
	 */
	public function test_receipt_lines_po_line_id_nullable_and_indexed() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();

		$table = $wpdb->prefix . 'wc_io_receipt_lines';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW COLUMNS against known prefix table.
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table} WHERE Field='po_line_id'" );
		$this->assertNotEmpty( $columns );
		$this->assertSame( 'YES', $columns[0]->Null );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW INDEX against known prefix table.
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Column_name='po_line_id'" );
		$this->assertNotEmpty( $indexes );
	}
}
