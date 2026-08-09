<?php
/**
 * End-to-end migrate_batch() correctness: a real legacy batch (built via
 * create_legacy_batch(), reproducing the historical apply-path row shape and
 * mutation) migrated into a Goods Receipt, verifying every field the M6
 * plan's mapping table specifies.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Service extends WC_Inventory_Overview_Test_Case {

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

	public function test_migrate_batch_returns_summary_and_creates_posted_migrated_receipt() {
		$fixture = $this->create_legacy_batch(
			array(
				'qty'       => 4,
				'line_cost' => 40,
			)
		);

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $fixture['batch_id'], $result['batch_id'] );
		$this->assertSame( 1, $result['lines_migrated'] );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $result['receipt_id'] );
		$this->assertIsArray( $receipt );
		$this->assertSame( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED, $receipt['status'] );
		$this->assertSame( WC_Inventory_Overview_Goods_Receipts::SOURCE_MIGRATED, $receipt['source'] );
		$this->assertNull( $receipt['supplier_id'] );
		$this->assertSame( 'Legacy Supplier', $receipt['supplier_name_snapshot'] );
	}

	public function test_migrated_receipt_line_has_no_po_linkage() {
		$fixture = $this->create_legacy_batch();
		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $result['receipt_id'] );
		$this->assertCount( 1, $lines );
		$this->assertNull( $lines[0]['po_line_id'] );
	}

	public function test_receipt_number_allocated_in_batch_original_year() {
		$fixture = $this->create_legacy_batch();
		$this->backdate_legacy_batch( $fixture['batch_id'], '2022-06-15 10:00:00' );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertStringStartsWith( 'GR-2022-', $result['receipt_number'] );
	}

	public function test_receipt_provenance_reference_names_the_source_batch() {
		$fixture = $this->create_legacy_batch();
		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $result['receipt_id'] );
		$this->assertSame( sprintf( 'Migrated from legacy Batch #%d', $fixture['batch_id'] ), $receipt['reference'] );
	}

	public function test_receipt_timestamps_match_batch_original_created_at_not_migration_time() {
		$fixture = $this->create_legacy_batch();
		$this->backdate_legacy_batch( $fixture['batch_id'], '2021-01-10 08:30:00' );

		$result  = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $result['receipt_id'] );

		$this->assertSame( '2021-01-10 08:30:00', $receipt['posted_at'] );
		$this->assertSame( '2021-01-10 08:30:00', $receipt['created_at'] );

		global $wpdb;
		$batch = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_at FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $fixture['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertNotSame( '2021-01-10 08:30:00', $batch['migrated_at'], 'migrated_at must record when migration ran, not the historical event time.' );
		$this->assertNotNull( $batch['migrated_at'] );
	}

	public function test_migrate_batch_with_landed_cost_maps_cost_row() {
		$fixture = $this->create_legacy_batch(
			array(
				'qty'           => 5,
				'line_cost'     => 100,
				'landed_type'   => 'shipping',
				'landed_amount' => 10,
			)
		);

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 1, $result['costs_migrated'] );

		$costs = WC_Inventory_Overview_Receipt_Costs::list_for_receipt( $result['receipt_id'] );
		$this->assertCount( 1, $costs );
		$this->assertSame( 'shipping', $costs[0]['cost_type'] );
		$this->assertSame( '0', $costs[0]['post_hoc'] );
	}

	public function test_migrate_batch_rejects_already_migrated_batch() {
		$fixture = $this->create_legacy_batch();
		$first   = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );

		$second = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertWPError( $second );
	}

	public function test_migrate_batch_rejects_unknown_batch_id() {
		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( 999999999 );
		$this->assertWPError( $result );
	}

	public function test_list_eligible_batch_ids_excludes_already_migrated() {
		$a = $this->create_legacy_batch();
		$b = $this->create_legacy_batch();

		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $a['batch_id'] );

		$eligible = WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids();
		$this->assertNotContains( $a['batch_id'], $eligible );
		$this->assertContains( $b['batch_id'], $eligible );
	}

	public function test_list_migrated_batch_ids_includes_only_migrated() {
		$a = $this->create_legacy_batch();
		$b = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $a['batch_id'] );

		$migrated = WC_Inventory_Overview_Batch_Migration_Service::list_migrated_batch_ids();
		$this->assertContains( $a['batch_id'], $migrated );
		$this->assertNotContains( $b['batch_id'], $migrated );
	}
}
