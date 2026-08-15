<?php
/**
 * M25 WP-M25-1: characterization tests, zero production files touched.
 *
 * Freezes PO_Service::create_draft()'s exact fallback behavior that
 * Replenishment_Commit_Service::commit() depends on (header without
 * currency -> supplier's default_currency; header without expected_date ->
 * null; line without unit_cost -> 0.0), and PO_Request_Token's
 * context-isolation (a token issued for one context must not validate
 * under a different context) -- both entirely pre-existing, unmodified
 * behavior this milestone relies on but never re-implements.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Po_Service_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_header_without_currency_falls_back_to_supplier_default_currency() {
		$supplier = $this->create_supplier( array( 'default_currency' => 'USD' ) );
		$product  = $this->create_simple_product();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) )
		);

		$this->assertIsInt( $po_id );
		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( 'USD', $po['currency'] );
	}

	public function test_header_without_expected_date_is_null() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) )
		);

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertNull( $po['expected_date'] );
	}

	public function test_line_without_unit_cost_is_zero() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) )
		);

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$this->assertEqualsWithDelta( 0.0, (float) $lines[0]['unit_cost'], 0.0001 );
	}

	/**
	 * A token issued for one context must never validate under a different
	 * context -- the exact isolation Replenishment_Commit_Admin relies on
	 * to keep 'replenishment_commit' tokens from being satisfied by a token
	 * meant for 'save' (or vice versa).
	 */
	public function test_request_token_context_isolation() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'save' );

		$this->assertFalse(
			WC_Inventory_Overview_PO_Request_Token::consume( $token, 'replenishment_commit' ),
			'A token issued for context "save" must not validate under "replenishment_commit".'
		);
	}

	public function test_request_token_matching_context_succeeds_once() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'replenishment_commit' );

		$this->assertTrue( WC_Inventory_Overview_PO_Request_Token::consume( $token, 'replenishment_commit' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Request_Token::consume( $token, 'replenishment_commit' ), 'A token is one-shot -- a second consume() must fail.' );
	}
}
