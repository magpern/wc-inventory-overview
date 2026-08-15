<?php
/**
 * M25 §16/BR-M25-23/BR-M25-24: duplicate-replenishment prevention through
 * commit(), end to end.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Duplicate_Prevention extends WC_Inventory_Overview_Test_Case {

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
	 * BR-M25-14/BR-M25-24: an immediate retry of a successfully committed
	 * item is skipped (already_has_open_po_line), never creating a second
	 * line, no timing dependency.
	 */
	public function test_immediate_retry_is_skipped_no_duplicate_line() {
		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$items = array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 6 ) );

		$first = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );
		$this->assertCount( 1, $first['created'] );

		$second = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );
		$this->assertSame( array(), $second['created'] );
		$this->assertCount( 1, $second['skipped'] );
		$this->assertSame( 'already_has_open_po_line', $second['skipped'][0]['reason'] );

		// Confirm no second line was actually created anywhere.
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product->get_id()
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * BR-M25-23: two commits selecting an overlapping item are serialized.
	 * Genuine dual-connection test: a real second MySQL session holds the
	 * item's advisory lock for the whole duration of commit()'s own
	 * processing, proving the losing commit's item is skipped
	 * (concurrent_commit_in_progress) rather than racing to create a
	 * duplicate line.
	 */
	public function test_concurrent_commit_on_same_item_is_serialized_dual_connection() {
		$second_conn = $this->open_second_db_connection();
		if ( null === $second_conn ) {
			$this->markTestSkipped( 'Test harness does not support opening a genuine second DB connection.' );
			return;
		}

		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$lock_name = 'wc_io_replen_item_' . $product->get_id();
		$result    = $second_conn->query( "SELECT GET_LOCK('" . $second_conn->real_escape_string( $lock_name ) . "', 5)" );
		$row       = $result ? $result->fetch_row() : null;
		$this->assertSame( '1', (string) ( $row[0] ?? null ), 'Second connection failed to acquire the item lock -- test setup broken.' );

		try {
			$commit_result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 3 ) )
			);
		} finally {
			$second_conn->query( "SELECT RELEASE_LOCK('" . $second_conn->real_escape_string( $lock_name ) . "')" );
			$second_conn->close();
		}

		$this->assertSame( array(), $commit_result['created'], 'The losing commit must not create a PO while the item is locked elsewhere.' );
		$this->assertCount( 1, $commit_result['skipped'] );
		$this->assertSame( 'concurrent_commit_in_progress', $commit_result['skipped'][0]['reason'] );

		// The item must never end up unlocked/retried within the same
		// request -- confirmed indirectly: no PO line exists for it.
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() . ' WHERE product_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$product->get_id()
			)
		);
		$this->assertSame( 0, $count );
	}

	/**
	 * A lock-timeout on one item never blocks or excludes an unrelated,
	 * uncontended item in the same commit (item-scoped, §48 Amendment E).
	 */
	public function test_one_locked_item_does_not_prevent_other_items_from_committing() {
		$second_conn = $this->open_second_db_connection();
		if ( null === $second_conn ) {
			$this->markTestSkipped( 'Test harness does not support opening a genuine second DB connection.' );
			return;
		}

		$supplier    = $this->create_supplier();
		$locked_item = $this->make_needs_reorder_product();
		$free_item   = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $locked_item->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $free_item->get_id(), $supplier['id'] );

		$lock_name = 'wc_io_replen_item_' . $locked_item->get_id();
		$second_conn->query( "SELECT GET_LOCK('" . $second_conn->real_escape_string( $lock_name ) . "', 5)" );

		try {
			$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
				array(
					array( 'product_id' => $locked_item->get_id(), 'variation_id' => 0, 'qty' => 1 ),
					array( 'product_id' => $free_item->get_id(), 'variation_id' => 0, 'qty' => 1 ),
				)
			);
		} finally {
			$second_conn->query( "SELECT RELEASE_LOCK('" . $second_conn->real_escape_string( $lock_name ) . "')" );
			$second_conn->close();
		}

		$this->assertCount( 1, $result['created'], 'The uncontended item must still be created.' );
		$created_ids = array( $result['created'][0]['product_id'] );
		$this->assertContains( $free_item->get_id(), $created_ids );

		$skipped_ids = wp_list_pluck( $result['skipped'], 'product_id' );
		$this->assertContains( $locked_item->get_id(), $skipped_ids );
	}

	/**
	 * A deliberate, later, unrelated re-order of an item whose earlier draft
	 * has since left the conflict-status set is correctly allowed
	 * (BR-M25-25) -- provided the fresh needs-reorder classification
	 * independently confirms the item still needs reordering.
	 */
	public function test_reorder_after_prior_po_received_is_allowed() {
		$supplier = $this->create_supplier();
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$prior_po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		$this->add_po_line( $prior_po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 2 ) );
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ), array( 'id' => $prior_po['id'] ) );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 5 ) )
		);

		$this->assertCount( 1, $result['created'], 'A prior received PO must not block a genuinely fresh reorder.' );
	}
}
