<?php
/**
 * Headline test of Milestone M6: migrating legacy Batch Intake history into
 * Goods Receipts must leave current WooCommerce stock and cost BYTE-FOR-BYTE
 * unchanged. This is the golden/characterization test for the M6 plan's
 * central architectural decision (§Migration model — "migration is record
 * materialization, not receiving") and is a release blocker in its own
 * right, distinct from the general suite.
 *
 * @package WC_Inventory_Overview_Tests
 * @group characterization
 */

class Test_WC_IO_Batch_Migration_Historical_Integrity extends WC_Inventory_Overview_Test_Case {

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
	 * Snapshot the three fields migration must never touch.
	 *
	 * @param int $product_id Product id.
	 * @return array{stock:string, avg_cost:mixed, inv_value:mixed}
	 */
	private function snapshot( int $product_id ): array {
		$product = wc_get_product( $product_id );
		return array(
			'stock'     => get_post_meta( $product_id, '_stock', true ),
			'avg_cost'  => get_post_meta( $product_id, '_wc_io_average_unit_cost', true ),
			'inv_value' => get_post_meta( $product_id, '_wc_io_inventory_value', true ),
		);
	}

	/**
	 * A single simple-product batch, EUR, no landed cost — the minimal case.
	 */
	public function test_simple_product_eur_batch_stock_and_cost_unchanged() {
		$fixture = $this->create_legacy_batch(
			array(
				'qty'       => 10,
				'line_cost' => 155,
			)
		);

		$before = $this->snapshot( $fixture['product_id'] );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		wc_delete_product_transients( $fixture['product_id'] );
		clean_post_cache( $fixture['product_id'] );
		$after = $this->snapshot( $fixture['product_id'] );

		$this->assertSame( $before, $after, 'Stock/cost/value must be byte-for-byte identical before and after migration.' );
	}

	/**
	 * A batch with a landed cost allocation (USD, non-1 FX rate) — the case
	 * most likely to reveal a rounding/derivation regression if migration
	 * ever recomputed rather than copied.
	 */
	public function test_usd_batch_with_landed_cost_stock_and_cost_unchanged() {
		$fixture = $this->create_legacy_batch(
			array(
				'currency'      => 'USD',
				'fx_rate'       => '0.90000000',
				'qty'           => 7,
				'line_cost'     => 210,
				'landed_type'   => 'customs_vat',
				'landed_amount' => 15,
			)
		);

		$before = $this->snapshot( $fixture['product_id'] );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		wc_delete_product_transients( $fixture['product_id'] );
		clean_post_cache( $fixture['product_id'] );
		$after = $this->snapshot( $fixture['product_id'] );

		$this->assertSame( $before, $after );
	}

	/**
	 * Blending into pre-existing stock/average (not a fresh-from-zero
	 * product) — exercises the weighted-average-carrying-forward case.
	 */
	public function test_batch_blending_into_existing_stock_unchanged_after_migration() {
		$product = $this->create_simple_product( array( 'stock_qty' => 20 ) );
		$this->set_product_average_cost( $product, 5.0 );
		$this->set_product_inventory_value( $product, 100.0 );

		$fixture = $this->create_legacy_batch(
			array(
				'product'   => $product,
				'qty'       => 10,
				'line_cost' => 80,
			)
		);

		$before = $this->snapshot( $fixture['product_id'] );

		$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $fixture['batch_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		wc_delete_product_transients( $fixture['product_id'] );
		clean_post_cache( $fixture['product_id'] );
		$after = $this->snapshot( $fixture['product_id'] );

		$this->assertSame( $before, $after );
	}

	/**
	 * A representative multi-batch fixture (mixed currencies, mixed products)
	 * migrated in one run — every affected product unchanged, not just one.
	 */
	public function test_full_migration_run_across_multiple_products_leaves_all_unchanged() {
		$fixtures = array(
			$this->create_legacy_batch(
				array(
					'qty'       => 3,
					'line_cost' => 30,
				)
			),
			$this->create_legacy_batch(
				array(
					'currency'  => 'USD',
					'fx_rate'   => '0.9',
					'qty'       => 6,
					'line_cost' => 120,
				)
			),
			$this->create_legacy_batch(
				array(
					'currency'      => 'SEK',
					'fx_rate'       => '0.09',
					'qty'           => 2,
					'line_cost'     => 500,
					'landed_type'   => 'bank_fee',
					'landed_amount' => 25,
				)
			),
		);

		$before = array();
		foreach ( $fixtures as $f ) {
			$before[ $f['product_id'] ] = $this->snapshot( $f['product_id'] );
		}

		foreach ( $fixtures as $f ) {
			$result = WC_Inventory_Overview_Batch_Migration_Service::migrate_batch( $f['batch_id'] );
			$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		}

		foreach ( $fixtures as $f ) {
			wc_delete_product_transients( $f['product_id'] );
			clean_post_cache( $f['product_id'] );
			$this->assertSame( $before[ $f['product_id'] ], $this->snapshot( $f['product_id'] ), "Product #{$f['product_id']} stock/cost changed after migration." );
		}
	}
}
