<?php
/**
 * rollback_batch() correctness (M6): undoes exactly one batch's migration —
 * deletes the migrated receipt/lines/costs, clears the movement reference
 * back to NULL, clears the batch's tracking columns — and never touches
 * current stock/cost (symmetric with the forward-migration golden test).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Rollback extends WC_Inventory_Overview_Test_Case {

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

	private function snapshot( int $product_id ): array {
		return array(
			'stock'     => get_post_meta( $product_id, '_stock', true ),
			'avg_cost'  => get_post_meta( $product_id, '_wc_io_average_unit_cost', true ),
			'inv_value' => get_post_meta( $product_id, '_wc_io_inventory_value', true ),
		);
	}

	public function test_rollback_deletes_receipt_lines_and_costs() {
		$fixture = $this->create_legacy_batch( array( 'landed_type' => 'shipping', 'landed_amount' => 5 ) );
		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$receipt_id = $result['receipt_id'];

		$rolled_back = WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );
		$this->assertTrue( $rolled_back, is_wp_error( $rolled_back ) ? $rolled_back->get_error_message() : '' );

		$this->assertWPError( WC_Inventory_Overview_Goods_Receipts::get( $receipt_id ) );
		$this->assertSame( array(), WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $receipt_id ) );
		$this->assertSame( array(), WC_Inventory_Overview_Receipt_Costs::list_for_receipt( $receipt_id ) );
	}

	public function test_rollback_clears_movement_reference_back_to_null() {
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		global $wpdb;
		$table = WC_Inventory_Overview_Movements::table_name();
		$before = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE movement_type = %s AND note LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::TYPE_PURCHASE_BATCH,
				$wpdb->esc_like( 'Batch ID: ' . $fixture['batch_id'] . "\n" ) . '%'
			),
			ARRAY_A
		);
		$this->assertNotNull( $before['reference_id'], 'Precondition: movement must carry a reference before rollback.' );

		WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );

		$after = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $before['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNull( $after['reference_type'] );
		$this->assertNull( $after['reference_id'] );
		$this->assertSame( $before['note'], $after['note'], 'Rollback must never touch the movement note.' );
	}

	public function test_rollback_clears_batch_tracking_columns() {
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_receipt_id, migrated_at FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $fixture['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNull( $row['migrated_receipt_id'] );
		$this->assertNull( $row['migrated_at'] );
	}

	public function test_rollback_never_touches_current_stock_or_cost() {
		$fixture = $this->create_legacy_batch( array( 'qty' => 8, 'line_cost' => 96 ) );

		$before_migration = $this->snapshot( $fixture['product_id'] );
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );

		wc_delete_product_transients( $fixture['product_id'] );
		clean_post_cache( $fixture['product_id'] );
		$after_rollback = $this->snapshot( $fixture['product_id'] );

		$this->assertSame( $before_migration, $after_rollback );
	}

	public function test_batch_is_eligible_again_after_rollback() {
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertNotContains( $fixture['batch_id'], WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );

		WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );
		$this->assertContains( $fixture['batch_id'], WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
	}

	public function test_rerun_after_rollback_migrates_again_successfully() {
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );

		$second = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $second, is_wp_error( $second ) ? $second->get_error_message() : '' );
	}

	public function test_rollback_rejects_a_never_migrated_batch() {
		$fixture = $this->create_legacy_batch();
		$result  = WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );
		$this->assertWPError( $result );
	}

	public function test_rollback_refuses_to_delete_a_non_migrated_receipt_even_if_pointed_at() {
		// Defense-in-depth: if a batch's migrated_receipt_id somehow pointed at
		// a live (non-migrated) receipt, rollback must refuse rather than
		// delete a real receipt.
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		$live_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$this->assertIsInt( $live_id, is_wp_error( $live_id ) ? $live_id->get_error_message() : '' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'wc_io_purchase_batches',
			array( 'migrated_receipt_id' => $live_id ),
			array( 'id' => $fixture['batch_id'] ),
			array( '%d' ),
			array( '%d' )
		);

		$result = WC_Inventory_Overview_Batch_Migration_Service::rollback_batch( $fixture['batch_id'] );
		$this->assertWPError( $result );

		$this->assertIsArray( WC_Inventory_Overview_Goods_Receipts::get( $live_id ), 'The live (non-migrated) receipt must survive.' );
	}
}
