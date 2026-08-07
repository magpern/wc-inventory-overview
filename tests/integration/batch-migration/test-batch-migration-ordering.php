<?php
/**
 * Invariant M6-2 (order independence): batches may be migrated in any order
 * — ascending id, descending id, or an arbitrary --batch=<id> subset — and
 * the result is identical regardless of order, because migration never
 * reads current stock, current cost, or any other batch's rows.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Batch_Migration_Ordering extends WC_Inventory_Overview_Test_Case {

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

	/**
	 * Snapshot the fields that must be order-independent for one product.
	 */
	private function snapshot( int $product_id ): array {
		return array(
			'stock'     => get_post_meta( $product_id, '_stock', true ),
			'avg_cost'  => get_post_meta( $product_id, '_wc_io_average_unit_cost', true ),
			'inv_value' => get_post_meta( $product_id, '_wc_io_inventory_value', true ),
		);
	}

	public function test_descending_migration_order_yields_same_per_batch_results_as_ascending() {
		$a = $this->create_legacy_batch(
			array(
				'qty'       => 3,
				'line_cost' => 30,
			)
		);
		$b = $this->create_legacy_batch(
			array(
				'qty'       => 5,
				'line_cost' => 50,
			)
		);
		$c = $this->create_legacy_batch(
			array(
				'qty'       => 2,
				'line_cost' => 20,
			)
		);

		$before = array(
			$a['product_id'] => $this->snapshot( $a['product_id'] ),
			$b['product_id'] => $this->snapshot( $b['product_id'] ),
			$c['product_id'] => $this->snapshot( $c['product_id'] ),
		);

		// Deliberately reverse (descending) order, not the order the batches
		// were created in.
		$order = array( $c['batch_id'], $b['batch_id'], $a['batch_id'] );
		foreach ( $order as $id ) {
			$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $id );
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		}

		foreach ( array( $a, $b, $c ) as $f ) {
			wc_delete_product_transients( $f['product_id'] );
			clean_post_cache( $f['product_id'] );
			$this->assertSame(
				$before[ $f['product_id'] ],
				$this->snapshot( $f['product_id'] ),
				"Product #{$f['product_id']} stock/cost must be unaffected by migration order."
			);

			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT migrated_receipt_id FROM ' . $wpdb->prefix . 'wc_io_purchase_batches WHERE id = %d', $f['batch_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->assertNotNull( $row['migrated_receipt_id'], "Batch #{$f['batch_id']} must be migrated regardless of order." );
		}
	}

	public function test_arbitrary_subset_migration_via_explicit_batch_id_does_not_affect_others() {
		$a = $this->create_legacy_batch();
		$b = $this->create_legacy_batch();
		$c = $this->create_legacy_batch();

		// Migrate only the middle one, out of creation order semantics.
		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $b['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$this->assertContains( $a['batch_id'], WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
		$this->assertContains( $c['batch_id'], WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
		$this->assertNotContains( $b['batch_id'], WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );

		// Now migrate the remaining two, in reverse-of-creation order.
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $c['batch_id'] );
		WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $a['batch_id'] );

		$this->assertSame( array(), WC_Inventory_Overview_Batch_Migration_Service::list_eligible_batch_ids() );
		$this->assertCount( 3, WC_Inventory_Overview_Batch_Migration_Service::list_migrated_batch_ids() );
	}
}
