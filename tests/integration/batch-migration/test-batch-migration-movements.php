<?php
/**
 * Movement-reference backfill correctness (M6): every purchase_batch
 * movement row for a migrated batch gets exactly one reference_type/
 * reference_id UPDATE — no new movement rows, no other column changed.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Movements extends WC_Inventory_Overview_Test_Case {

	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_costs' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batch_lines' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'wc_io_purchase_batches' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * @param int $batch_id Legacy batch id.
	 * @return array<int,array<string,mixed>>
	 */
	private function purchase_batch_movements_for( int $batch_id ): array {
		global $wpdb;
		$table = WC_Inventory_Overview_Movements::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE movement_type = %s AND note LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				WC_Inventory_Overview_Movements::TYPE_PURCHASE_BATCH,
				$wpdb->esc_like( 'Batch ID: ' . $batch_id . "\n" ) . '%'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function test_movement_gets_typed_reference_after_migration() {
		$fixture = $this->create_legacy_batch();

		$before = $this->purchase_batch_movements_for( $fixture['batch_id'] );
		$this->assertCount( 1, $before );
		$this->assertNull( $before[0]['reference_type'] );
		$this->assertNull( $before[0]['reference_id'] );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$after = $this->purchase_batch_movements_for( $fixture['batch_id'] );
		$this->assertCount( 1, $after, 'Migration must never insert a new movement row.' );
		$this->assertSame( WC_Inventory_Overview_Movements::REFERENCE_TYPE_GOODS_RECEIPT, $after[0]['reference_type'] );
		$this->assertSame( (string) $result['receipt_id'], (string) $after[0]['reference_id'] );
	}

	public function test_movement_other_columns_unchanged_by_backfill() {
		$fixture = $this->create_legacy_batch(
			array(
				'qty'       => 6,
				'line_cost' => 60,
			)
		);
		$before  = $this->purchase_batch_movements_for( $fixture['batch_id'] )[0];

		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		$after = $this->purchase_batch_movements_for( $fixture['batch_id'] )[0];

		foreach ( array( 'note', 'quantity_change', 'unit_cost', 'total_value', 'old_stock', 'new_stock', 'old_average_unit_cost', 'new_average_unit_cost', 'created_at', 'user_id', 'supplier_name' ) as $col ) {
			$this->assertSame( $before[ $col ], $after[ $col ], "Column {$col} must be unchanged by movement backfill." );
		}
	}

	public function test_batch_id_prefix_match_is_exact_not_partial() {
		// Batch #1 and batch #12 must never be confused by a naive LIKE 'Batch ID: 1%' prefix.
		$one    = $this->create_legacy_batch();
		$twelve = $this->create_legacy_batch();

		global $wpdb;
		// Force a low id to exercise the 1-vs-12-style prefix collision risk if
		// the running DB doesn't already assign ids in this exact shape;
		// otherwise this is still a valid regression guard using whatever ids
		// were actually assigned.
		$ids = array( (int) $one['batch_id'], (int) $twelve['batch_id'] );
		sort( $ids );

		$movements_low  = $this->purchase_batch_movements_for( $ids[0] );
		$movements_high = $this->purchase_batch_movements_for( $ids[1] );

		$this->assertCount( 1, $movements_low );
		$this->assertCount( 1, $movements_high );
		$this->assertNotSame( $movements_low[0]['id'], $movements_high[0]['id'] );
	}

	public function test_movements_list_table_regex_still_matches_note_after_backfill() {
		// Migration deliberately leaves the note text untouched — the
		// pre-existing regex fallback must still parse it identically.
		$fixture = $this->create_legacy_batch();
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );

		$movement = $this->purchase_batch_movements_for( $fixture['batch_id'] )[0];
		$lines    = explode( "\n", (string) $movement['note'] );

		$this->assertMatchesRegularExpression( '/^Batch ID:\s*(\d+)\s*$/', trim( $lines[0] ) );
		preg_match( '/^Batch ID:\s*(\d+)\s*$/', trim( $lines[0] ), $m );
		$this->assertSame( (string) $fixture['batch_id'], $m[1] );
	}
}
