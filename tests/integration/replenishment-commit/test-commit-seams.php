<?php
/**
 * M25 WP-M25-3/§48 Amendment B: the two-seam failure-injection model.
 *
 * Seam A proves BR-M25-8 (one group's genuine create_draft() failure never
 * prevents/reverses other groups' successful creation). Seam B proves
 * catastrophic-interruption safety (a thrown exception mid-batch still
 * leaves already-created POs intact and releases every acquired lock).
 * Kept strictly distinct, per §48 Amendment B.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Seams extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Commit_Service::reset_test_seams();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function make_needs_reorder_product( array $props = array() ): WC_Product_Simple {
		$product = $this->create_simple_product( array_merge( array( 'stock_qty' => 1 ), $props ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	/**
	 * Seam A, the genuine empirical proof of BR-M25-8: three supplier
	 * groups A, B, C. Seam A archives group B's supplier at the exact
	 * moment its group is about to be processed -- AFTER the fresh plan
	 * already resolved B to that supplier (so this is not caught by the
	 * plan rebuild itself, per §48 Amendment B's correction). create_draft()
	 * must independently discover the now-inactive supplier and return its
	 * own genuine WP_Error; groups A and C must be unaffected.
	 */
	public function test_seam_a_one_group_failure_does_not_affect_others() {
		$supplier_a = $this->create_supplier( array( 'name' => 'Group A Supplier' ) );
		$supplier_b = $this->create_supplier( array( 'name' => 'Group B Supplier' ) );
		$supplier_c = $this->create_supplier( array( 'name' => 'Group C Supplier' ) );

		$product_a = $this->make_needs_reorder_product();
		$product_b = $this->make_needs_reorder_product();
		$product_c = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier_b['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_c->get_id(), $supplier_c['id'] );

		WC_Inventory_Overview_Replenishment_Commit_Service::set_test_seam_a(
			$supplier_b['id'],
			static function ( int $supplier_id ) {
				// Mutates fixture state only -- never fabricates the WP_Error.
				WC_Inventory_Overview_Suppliers::archive( $supplier_id );
			}
		);

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array( 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => 1 ),
				array( 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => 1 ),
				array( 'product_id' => $product_c->get_id(), 'variation_id' => 0, 'qty' => 1 ),
			)
		);

		$created_suppliers = array_map( 'intval', wp_list_pluck( $result['created'], 'supplier_id' ) );
		$this->assertContains( (int) $supplier_a['id'], $created_suppliers, 'Group A must succeed, unaffected by group B failing.' );
		$this->assertContains( (int) $supplier_c['id'], $created_suppliers, 'Group C must still be attempted and succeed after group B failed.' );
		$this->assertNotContains( (int) $supplier_b['id'], $created_suppliers );

		$this->assertCount( 1, $result['failed'], 'Group B must land in failed[], not skipped[] or a top-level WP_Error.' );
		$this->assertSame( (int) $supplier_b['id'], (int) $result['failed'][0]['supplier_id'] );
		$this->assertSame( 'wc_io_po_supplier_inactive', $result['failed'][0]['error_code'], 'create_draft() must discover the invalid state on its own and return its own genuine error code.' );
	}

	/**
	 * Seam B: a catastrophic RuntimeException immediately after group A's
	 * create_draft() has already returned success, before group B begins.
	 * Group A's PO must persist unaffected; B/C must never be attempted;
	 * every acquired lock must still be released.
	 *
	 * Lock-release proof uses a genuine second DB connection + IS_FREE_LOCK()
	 * (WP2 F-02). Same-connection GET_LOCK() is session-reentrant and cannot
	 * prove RELEASE_LOCK ran via finally.
	 */
	public function test_seam_b_catastrophic_interruption_preserves_prior_success_and_releases_locks() {
		$second_conn = $this->open_second_db_connection();
		if ( null === $second_conn ) {
			$this->markTestSkipped( 'Test harness does not support opening a genuine second DB connection for IS_FREE_LOCK proof.' );
			return;
		}

		$supplier_a = $this->create_supplier( array( 'name' => 'AAA' ) );
		$supplier_b = $this->create_supplier( array( 'name' => 'BBB' ) );

		$product_a = $this->make_needs_reorder_product();
		$product_b = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier_b['id'] );

		// Both items are lock-acquired for the whole commit (ascending
		// item_post_id), even though only group index 0 creates a PO before
		// the interrupt. Release proof must cover every acquired name.
		$item_ids = array( $product_a->get_id(), $product_b->get_id() );
		sort( $item_ids, SORT_NUMERIC );

		// Group processing order is ascending supplier_id; interrupt after
		// group index 0 (whichever supplier sorts first) has already
		// succeeded.
		WC_Inventory_Overview_Replenishment_Commit_Service::set_test_interrupt_after_group_index( 0 );

		$exception_thrown = false;
		try {
			WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array( 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => 1 ),
					array( 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => 1 ),
				)
			);
		} catch ( RuntimeException $e ) {
			$exception_thrown = true;
			$this->assertStringContainsString( 'wc_io_test_injected_interrupt', $e->getMessage() );
		}
		$this->assertTrue( $exception_thrown, 'Seam B must actually propagate the injected exception to the caller.' );

		// Exactly one PO must exist (the first group's, created before the
		// interruption) -- the second group must never have been attempted.
		global $wpdb;
		$po_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 1, $po_count, 'Exactly one group must have committed before the interruption.' );

		try {
			foreach ( $item_ids as $item_post_id ) {
				$lock_name = 'wc_io_replen_item_' . $item_post_id;
				$result    = $second_conn->query(
					"SELECT IS_FREE_LOCK('" . $second_conn->real_escape_string( $lock_name ) . "')"
				);
				$row = $result ? $result->fetch_row() : null;
				$this->assertSame(
					'1',
					(string) ( $row[0] ?? null ),
					'Lock ' . $lock_name . ' must be free to an independent connection after commit() finally released it (session-reentrant same-connection GET_LOCK is not acceptable evidence).'
				);
			}
		} finally {
			$second_conn->close();
		}
	}

	public function test_reset_test_seams_disarms_both() {
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Commit_Service::set_test_seam_a(
			$supplier['id'],
			static function () {
				throw new RuntimeException( 'should never fire' );
			}
		);
		WC_Inventory_Overview_Replenishment_Commit_Service::set_test_interrupt_after_group_index( 0 );

		WC_Inventory_Overview_Replenishment_Commit_Service::reset_test_seams();

		$product = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertCount( 1, $result['created'], 'After reset_test_seams(), no seam should fire.' );
	}
}
