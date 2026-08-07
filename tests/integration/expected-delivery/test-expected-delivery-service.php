<?php
/**
 * Integration tests for WC_Inventory_Overview_Expected_Delivery_Service (M7, API v1).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Service extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $po_id PO id.
	 * @return array<string,mixed>
	 */
	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * @param WC_Product_Variable $parent Variable product.
	 * @return int[]
	 */
	private function variation_ids( WC_Product_Variable $parent ): array {
		wc_delete_product_transients( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		return $fresh instanceof WC_Product_Variable ? $fresh->get_children() : array();
	}

	/**
	 * Sets a product out of stock via WooCommerce's own field, independent
	 * of stock_quantity plumbing.
	 *
	 * @param WC_Product $product Product.
	 */
	private function set_out_of_stock( WC_Product $product ): void {
		$product->set_stock_status( 'outofstock' );
		$product->save();
	}

	/**
	 * A variable parent's own _stock_status is recomputed from its children
	 * by WC_Product_Variable::validate_props() whenever the parent does not
	 * manage its own stock (the house configuration here) -- so a parent's
	 * availability is controlled through its children, then explicitly
	 * synced, never by setting the parent's own stock_status directly. And a
	 * stock-managed child (create_variable_product()'s default) has its own
	 * validate_props() recompute stock_status from stock_quantity on every
	 * save(), so a manual set_stock_status() on the child is equally
	 * futile -- quantity is the only lever that sticks.
	 *
	 * @param int    $parent_id     Variable parent product ID.
	 * @param int[]  $child_ids     Variation IDs.
	 * @param string $stock_status  'instock'|'outofstock' to apply to every child.
	 * @return WC_Product_Variable Freshly re-fetched, synced parent.
	 */
	private function sync_parent_stock_from_children( int $parent_id, array $child_ids, string $stock_status ): WC_Product_Variable {
		foreach ( $child_ids as $child_id ) {
			$variation = wc_get_product( $child_id );
			$variation->set_manage_stock( true );
			$variation->set_stock_quantity( 'instock' === $stock_status ? 5 : 0 );
			$variation->save();
		}

		// get_children() is transient-cached and may still hold the
		// pre-variation empty list (the same quirk variation_ids() works
		// around) -- must be cleared before sync_stock_status() re-fetches
		// the parent, not after.
		wc_delete_product_transients( $parent_id );

		// child_has_stock_status() reads $wpdb->wc_product_meta_lookup,
		// which is only populated asynchronously -- not by $variation->save()
		// in a test process. This WooCommerce-documented escape hatch makes
		// it read _stock_status postmeta directly instead, which save() does
		// update synchronously.
		update_option( 'woocommerce_product_lookup_table_is_generating', 'yes' );
		WC_Product_Variable::sync_stock_status( $parent_id );
		delete_option( 'woocommerce_product_lookup_table_is_generating' );

		wc_delete_product_transients( $parent_id );

		return wc_get_product( $parent_id );
	}

	public function test_simple_product_end_to_end_customer_safe_date() {
		$product = $this->create_simple_product();
		$this->set_out_of_stock( $product );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => '2026-09-01',
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$this->place_po( $po['id'] );

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result->state() );
		$this->assertSame( '2026-09-01', $result->expected_date() );
		$this->assertSame( 'exact', $result->confidence() );
		$this->assertFalse( $result->available_now() );
		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Service::API_VERSION, $result->api_version() );
	}

	public function test_variation_end_to_end_customer_safe_date() {
		$parent   = $this->create_variable_product( array(), array( array( 'name' => 'Only Variation' ) ) );
		$children = $this->variation_ids( $parent );
		$variation_id = (int) $children[0];
		$variation    = wc_get_product( $variation_id );
		$this->set_out_of_stock( $variation );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => '2026-09-15',
				'expected_confidence' => 'estimated',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $parent->get_id(),
				'variation_id' => $variation_id,
				'qty_ordered'  => 2,
			)
		);
		$this->place_po( $po['id'] );

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $variation_id );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result->state() );
		$this->assertSame( '2026-09-15', $result->expected_date() );
		$this->assertSame( 'estimated', $result->confidence() );
	}

	/**
	 * Invariant M7-2: an out-of-stock variable parent with a customer-safe
	 * incoming child presents STATE_EXPECTED_SOON, never a date.
	 */
	public function test_variable_parent_with_customer_safe_child_yields_expected_soon_never_a_date() {
		$parent       = $this->create_variable_product(
			array(),
			array( array( 'name' => 'A' ), array( 'name' => 'B' ) )
		);
		$children     = $this->variation_ids( $parent );
		$variation_id = (int) $children[0];
		$parent       = $this->sync_parent_stock_from_children( $parent->get_id(), $children, 'outofstock' );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => '2026-09-01',
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $parent->get_id(),
				'variation_id' => $variation_id,
				'qty_ordered'  => 1,
			)
		);
		$this->place_po( $po['id'] );

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $parent );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result->state() );
		$this->assertNull( $result->expected_date() );
		$this->assertNull( $result->confidence() );
	}

	public function test_variable_parent_in_stock_is_state_in_stock() {
		$parent   = $this->create_variable_product( array(), array( array( 'name' => 'A' ) ) );
		$children = $this->variation_ids( $parent );
		$parent   = $this->sync_parent_stock_from_children( $parent->get_id(), $children, 'instock' );

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $parent );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK, $result->state() );
	}

	/**
	 * get_for_product() is exactly get_for_products_bulk() returning the
	 * single element -- single and bulk can never disagree.
	 */
	public function test_single_and_bulk_return_identical_results() {
		$product = $this->create_simple_product();
		$this->set_out_of_stock( $product );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => '2026-09-01',
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 1,
			)
		);
		$this->place_po( $po['id'] );

		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		$single = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		$bulk = WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( array( $product ) );

		$this->assertSame( $single->state(), $bulk[ $product->get_id() ]->state() );
		$this->assertSame( $single->expected_date(), $bulk[ $product->get_id() ]->expected_date() );
		$this->assertSame( $single->confidence(), $bulk[ $product->get_id() ]->confidence() );
	}

	/**
	 * Memoization returns the exact same instance within a request -- no
	 * defensive copying, no further queries.
	 */
	public function test_memoization_returns_the_same_instance_within_a_request() {
		$product = $this->create_simple_product();

		$first  = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );
		$second = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		$this->assertSame( $first, $second );
	}

	/**
	 * The memo is flushed on wc_io_purchase_order_placed so a same-request
	 * write is reflected (belt-and-braces; storefront requests do not post
	 * receipts and the memo dies at end of request regardless).
	 */
	public function test_memo_flushes_on_purchase_order_placed() {
		$product = $this->create_simple_product();
		$this->set_out_of_stock( $product );

		$before = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );
		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE, $before->state() );

		$po = $this->create_purchase_order(
			array(
				'expected_date'       => '2026-09-01',
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 1,
			)
		);
		$this->place_po( $po['id'] ); // Fires wc_io_purchase_order_placed.

		$after = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $after->state() );
	}

	public function test_negative_stock_handled_without_fatal() {
		$product = $this->create_simple_product();
		$product->set_manage_stock( true );
		$product->set_stock_quantity( -5 );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		$this->assertFalse( $result->available_now() );
	}

	public function test_unmanaged_stock_handled_without_fatal() {
		$product = $this->create_simple_product();
		$product->set_manage_stock( false );
		$product->set_stock_status( 'outofstock' );
		$product->save();

		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );

		$this->assertFalse( $result->available_now() );
	}

	public function test_deleted_missing_product_id_handled_without_fatal() {
		$result = WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( 999999999 );

		$this->assertInstanceOf( WC_Inventory_Overview_Expected_Delivery_Result_Interface::class, $result );
		$this->assertFalse( $result->available_now() );
		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE, $result->state() );
	}
}
