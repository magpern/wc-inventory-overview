<?php
/**
 * M25 §11 steps 7-9: cross-reference/skip contract.
 *
 * Variable-parent rejection, unresolved-item skip (no_supplier/multiple_suppliers),
 * no-longer-needs-reorder skip, not_found skip -- all BR-M25-7/11/12.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Eligibility extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();

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

	private function skip_reasons( array $result ): array {
		return wp_list_pluck( $result['skipped'], 'reason' );
	}

	/**
	 * BR-M25-12: a variable parent product id can never appear as a
	 * submittable line -- it is never a needs-reorder candidate itself
	 * (only its variations are), so it never resolves in the fresh plan.
	 */
	public function test_variable_parent_id_never_creates_a_line() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 100 ) ) );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $variable->get_id(), 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertCount( 1, $result['skipped'] );
	}

	public function test_unresolved_no_supplier_item_is_skipped() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		// No preferred supplier, no purchase history -> unresolved (no_supplier).

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertContains( 'no_supplier', $this->skip_reasons( $result ) );
	}

	public function test_unresolved_multiple_suppliers_item_is_skipped() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();
		$product    = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		foreach ( array( $supplier_a, $supplier_b ) as $supplier ) {
			$po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
			$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
			WC_Inventory_Overview_PO_Service::place( $po['id'] );
		}

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertContains( 'multiple_suppliers', $this->skip_reasons( $result ) );
	}

	/**
	 * BR-M25-7: an item that no longer needs reordering in the fresh
	 * rebuild is silently skipped, not a request failure.
	 */
	public function test_no_longer_needs_reorder_item_is_skipped() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 100 ) ); // Well above threshold.
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertContains( 'no_longer_needs_reorder', $this->skip_reasons( $result ) );
	}

	public function test_deleted_or_nonexistent_product_id_skipped_as_not_found() {
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => 999999999, 'variation_id' => 0, 'qty' => 1 ) )
		);

		$this->assertSame( array(), $result['created'] );
		$this->assertContains( 'not_found', $this->skip_reasons( $result ) );
	}

	/**
	 * BR-M25-10: a stale-preferred-supplier line is commit-eligible on
	 * exactly the same terms as any other resolved line.
	 */
	public function test_stale_preferred_supplier_falls_back_to_history_and_still_commits() {
		$stale_supplier = $this->create_supplier();
		$history_supplier = $this->create_supplier();
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $stale_supplier['id'] );

		$po = $this->create_purchase_order( array( 'supplier_id' => $history_supplier['id'] ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		WC_Inventory_Overview_PO_Service::place( $po['id'] );
		// Force the history-establishing PO to 'received' (still counts as
		// committed purchase history, but -- unlike 'placed' -- is outside
		// the new §16/Amendment A conflict-status set, so it does not
		// itself trip the conflicting-open-line check this test is not
		// about).
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ), array( 'id' => $po['id'] ) );
		WC_Inventory_Overview_Suppliers::archive( $stale_supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 4 ) )
		);

		$this->assertCount( 1, $result['created'] );
		$po_after = WC_Inventory_Overview_Purchase_Orders::get( $result['created'][0]['po_id'] );
		$this->assertSame( (int) $history_supplier['id'], (int) $po_after['supplier_id'] );
	}
}
