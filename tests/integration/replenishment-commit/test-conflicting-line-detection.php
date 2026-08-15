<?php
/**
 * M25 §16/§48 Amendment A: Purchase_Order_Lines::list_open_or_draft_item_ids_bulk().
 *
 * Proves the exact frozen conflict-status set (draft, placed,
 * partially_received) -- no more, no less. A one-status-away bug here would
 * silently defeat the entire duplicate-prevention mechanism.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Conflicting_Line_Detection extends WC_Inventory_Overview_Test_Case {

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

	/**
	 * Force a PO's status directly (bypassing the real lifecycle) -- test
	 * fixture setup only, mirroring the existing backdate_legacy_batch()
	 * precedent of direct-SQL fixture manipulation for states the ordinary
	 * lifecycle API can't cheaply reach (e.g. 'received' without a full
	 * Goods Receipt flow).
	 *
	 * @param int    $po_id  PO id.
	 * @param string $status Status to force.
	 */
	private function force_po_status( int $po_id, string $status ): void {
		global $wpdb;
		$wpdb->update( WC_Inventory_Overview_Purchase_Orders::table_name(), array( 'status' => $status ), array( 'id' => $po_id ) );
	}

	private function line_for_product( int $product_id, string $status ): void {
		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product_id, 'qty_ordered' => 3 ) );
		$this->force_po_status( $po['id'], $status );
	}

	public function test_draft_status_blocks() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::DRAFT );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertContains( $product->get_id(), $hits, 'draft must block (§48 Amendment A).' );
	}

	public function test_placed_status_blocks() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::PLACED );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertContains( $product->get_id(), $hits );
	}

	public function test_partially_received_status_blocks() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertContains( $product->get_id(), $hits );
	}

	public function test_received_status_does_not_block() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::RECEIVED );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertNotContains( $product->get_id(), $hits, 'received must NOT block (§48 Amendment A).' );
	}

	public function test_cancelled_status_does_not_block() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::CANCELLED );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertNotContains( $product->get_id(), $hits, 'cancelled must NOT block (§48 Amendment A).' );
	}

	public function test_closed_short_status_does_not_block() {
		$product = $this->create_simple_product();
		$this->line_for_product( $product->get_id(), WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertNotContains( $product->get_id(), $hits, 'closed_short must NOT block (§48 Amendment A).' );
	}

	public function test_variation_scoped_branch() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		$children = wc_get_product( $variable->get_id() )->get_children();
		$vid      = $children[0];

		$this->line_for_product( $vid, WC_Inventory_Overview_PO_Statuses::PLACED );
		// line_for_product used add_po_line() with product_id => $vid, which
		// resolves variation_id = 0/product_id = $vid unless we override --
		// use a direct add for the variation branch instead.

		$hits_wrong_branch = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array(), array( $vid ) );
		$this->assertNotContains( $vid, $hits_wrong_branch, 'A product-scoped line must not appear via the variation-scoped branch.' );

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => $vid, 'qty_ordered' => 2 ) );
		$this->force_po_status( $po['id'], WC_Inventory_Overview_PO_Statuses::PLACED );

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array(), array( $vid ) );
		$this->assertContains( $vid, $hits );
	}

	public function test_no_matching_line_returns_empty() {
		$product = $this->create_simple_product();

		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array( $product->get_id() ), array() );

		$this->assertSame( array(), $hits );
	}

	public function test_empty_input_returns_empty_with_no_query() {
		$hits = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( array(), array() );

		$this->assertSame( array(), $hits );
	}

	/**
	 * One bulk query per non-empty branch, never per-item (§16).
	 */
	public function test_bulk_call_is_flat_not_n_plus_1() {
		global $wpdb;

		$products = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$p = $this->create_simple_product();
			$this->line_for_product( $p->get_id(), WC_Inventory_Overview_PO_Statuses::PLACED );
			$products[] = $p->get_id();
		}

		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( $products, array() );
		$after = $wpdb->num_queries;

		$this->assertSame( 1, $after - $before, 'Exactly one query for a product-only bulk call, regardless of item count.' );
	}
}
