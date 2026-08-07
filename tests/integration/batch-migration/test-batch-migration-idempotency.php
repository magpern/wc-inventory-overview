<?php
/**
 * Idempotency/resumability (M6): running migration twice performs zero
 * additional writes on the second pass; a batch already carrying
 * migrated_receipt_id is never re-processed.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Idempotency extends WC_Inventory_Overview_Test_Case {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function test_second_migrate_call_is_a_no_op_error_not_a_duplicate_write() {
		$fixture = $this->create_legacy_batch();

		$first = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );

		$count_before = WC_Inventory_Overview_Goods_Receipts::count();

		$second = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertWPError( $second );

		$this->assertSame( $count_before, WC_Inventory_Overview_Goods_Receipts::count(), 'A second migrate_batch() call on an already-migrated batch must write zero new receipts.' );
	}

	public function test_a_cli_style_run_over_all_eligible_batches_twice_produces_no_duplicates() {
		$a = $this->create_legacy_batch();
		$b = $this->create_legacy_batch();
		$c = $this->create_legacy_batch();

		foreach ( WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() as $id ) {
			WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $id );
		}
		$this->assertSame( 3, WC_Inventory_Overview_Goods_Receipts::count() );

		// Simulated rerun: eligibility list is now empty, so nothing is
		// (re)migrated — this is the actual resumability mechanism, not a
		// special-cased "skip if migrated" branch inside migrate_batch() alone.
		$this->assertSame( array(), WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
		$this->assertSame( 3, WC_Inventory_Overview_Goods_Receipts::count(), 'A rerun over an empty eligibility list must create zero additional receipts.' );
	}

	public function test_migrated_receipt_id_is_the_idempotency_marker() {
		$fixture = $this->create_legacy_batch();
		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_receipt_id FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $fixture['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( (string) $result['receipt_id'], (string) $row['migrated_receipt_id'] );
	}
}
