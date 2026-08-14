<?php
/**
 * Integration tests for M23 WP-M23-4: preferred-supplier precedence in
 * WC_Inventory_Overview_Reorder_Prefill_Service::resolve().
 *
 * Covers BR-M23-2/3/4/5/8/9 and INV-M23-5/6/7/8/17/18: a valid, currently
 * eligible preferred supplier is used directly and the M22 history query
 * never runs; with no preference configured, behavior is byte-for-byte
 * M22; a stale (archived/merged/deleted) preferred supplier falls back to
 * the unchanged M22 history algorithm plus a distinct notice, and is
 * never silently cleared from storage.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Preferred_Supplier_Prefill extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	private function seed_committed_history( array $line_props, string $order_date = '2026-01-01', string $status = 'placed' ): array {
		$po = $this->create_purchase_order( array( 'order_date' => $order_date ) );
		$this->add_po_line( $po['id'], $line_props );
		$result = WC_Inventory_Overview_Purchase_Orders::update_fields(
			$po['id'],
			array(
				'status'     => $status,
				'order_date' => $order_date,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to force PO status: ' . $result->get_error_message() );
		}
		return array(
			'po_id'       => $po['id'],
			'supplier_id' => (int) $po['supplier_id'],
		);
	}

	// ---------------------------------------------------------------
	// BR-M23-2/8: valid preference wins, history never queried.
	// ---------------------------------------------------------------

	public function test_valid_preferred_supplier_wins_over_history() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		$historic  = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( (int) $preferred['id'], $result['supplier_id'] );
		$this->assertNotSame( $historic['supplier_id'], $result['supplier_id'] );
		$this->assertSame( array(), $result['notices'], 'No notice expected on the happy path.' );
	}

	public function test_valid_preferred_supplier_skips_history_query_entirely() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		// Seed 10 historical suppliers -- if the history query ran, it would show up here.
		for ( $i = 0; $i < 10; $i++ ) {
			$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-0' . ( 1 + ( $i % 9 ) ), 'closed_short' );
		}
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );

		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$hits        = array();
		$counter     = static function ( $query ) use ( $lines_table, &$hits ) {
			if ( false !== stripos( $query, 'SELECT' ) && false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'GROUP BY' ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );
		remove_filter( 'query', $counter );

		$this->assertSame( (int) $preferred['id'], $result['supplier_id'] );
		$this->assertSame( array(), $hits, 'The committed-history query (GROUP BY po.supplier_id) must never run when a valid preference is configured.' );
	}

	// ---------------------------------------------------------------
	// BR-M23-3: unconfigured path is byte-for-byte M22.
	// ---------------------------------------------------------------

	public function test_no_preference_configured_matches_m22_history_behavior() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( $seed['supplier_id'], $result['supplier_id'] );
		$this->assertSame( array(), $result['notices'] );
	}

	// ---------------------------------------------------------------
	// BR-M23-4/9: stale preference falls back to history + distinct notice.
	// ---------------------------------------------------------------

	public function test_archived_preferred_supplier_falls_back_to_history() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		$historic  = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $preferred['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( $historic['supplier_id'], $result['supplier_id'] );
		$this->assertCount( 1, $result['notices'] );
		$this->assertSame( 'warning', $result['notices'][0]['type'] );
		$this->assertStringContainsString( 'preferred supplier is no longer available', $result['notices'][0]['message'] );
	}

	public function test_preference_pointing_at_merge_source_falls_back_to_history() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		$target    = $this->create_supplier();
		$historic  = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Suppliers::mark_merged( $preferred['id'], $target['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( $historic['supplier_id'], $result['supplier_id'], 'A merged preference must never be preselected, and must never silently redirect to the merge target.' );
	}

	public function test_deleted_preferred_supplier_falls_back_to_history() {
		global $wpdb;
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		$historic  = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		// Direct DB manipulation to simulate a genuinely deleted supplier row (out of the normal archive/merge flow).
		$wpdb->delete( WC_Inventory_Overview_Suppliers::table_name(), array( 'id' => $preferred['id'] ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( $historic['supplier_id'], $result['supplier_id'] );
	}

	public function test_stale_preference_with_no_history_yields_no_supplier() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $preferred['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertCount( 2, $result['notices'], 'Expect both the stale-preference notice and the no-eligible-history notice.' );
	}

	/**
	 * BR-M23-17/INV-M23-6: rendering a stale preference must never write
	 * anything -- the stored (now-stale) value stays exactly as it was.
	 */
	public function test_rendering_stale_preference_never_mutates_storage() {
		$product   = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $preferred['id'] );

		WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame(
			(int) $preferred['id'],
			WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ),
			'A GET-triggered prefill resolution must never clear or rewrite a stale preferred-supplier value.'
		);
	}

	// ---------------------------------------------------------------
	// Variation identity.
	// ---------------------------------------------------------------

	public function test_preference_stored_on_variation_used_for_that_variation() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 0 ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$variation = wc_get_product( $children[0] );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $variation->get_id(), (int) $preferred['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id(), $variation->get_id() );

		$this->assertSame( (int) $preferred['id'], $result['supplier_id'] );
	}
}
