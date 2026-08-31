<?php
/**
 * M27: narrow v11 -> v12 upgrade test (Stock Adjustment tables only).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Schema_V12_Upgrade extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_stock_adjustment_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_stock_adjustments' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_v11_to_v12_upgrade_adds_stock_adjustment_tables() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		$sa_table  = $wpdb->prefix . 'wc_io_stock_adjustments';
		$line_table = $wpdb->prefix . 'wc_io_stock_adjustment_lines';

		$wpdb->query( "DROP TABLE IF EXISTS {$line_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$sa_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'wc_io_db_version', '11' );

		WC_Inventory_Overview_Install::maybe_upgrade();

		$this->assertSame( WC_Inventory_Overview_Install::DB_VERSION, get_option( 'wc_io_db_version' ) );
		$this->assertSame( $sa_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $sa_table ) ) ) );
		$this->assertSame( $line_table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $line_table ) ) ) );
		$this->assertTrue( WC_Inventory_Overview_Install::assert_schema_shape() );
	}

	public function test_dispatcher_routes_v12_to_v12_assertion_not_v11() {
		$dispatcher = new ReflectionMethod( 'WC_Inventory_Overview_Install', 'expected_schema' );
		$dispatcher->setAccessible( true );

		$v12 = $dispatcher->invoke( null, '12' );
		$this->assertContains( 'wc_io_stock_adjustments', $v12['tables'] );
		$this->assertContains( 'wc_io_stock_adjustment_lines', $v12['tables'] );

		$v11 = $dispatcher->invoke( null, '11' );
		$this->assertNotContains( 'wc_io_stock_adjustments', $v11['tables'] );
	}
}
