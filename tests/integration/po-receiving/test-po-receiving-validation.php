<?php
/**
 * M5: pre-transaction validation for PO-linked lines — non-receivable PO
 * statuses rejected with zero DB writes; product-mismatch rejected before any
 * draft is even saved.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Validation extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * @param string $status Desired PO status: draft|placed|cancelled|closed_short.
	 * @return array{po_id:int,line_id:int,product:WC_Product_Simple}
	 */
	private function po_with_line_in_status( string $status ): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 2 ) )
		);
		if ( 'placed' === $status || 'cancelled' === $status || 'closed_short' === $status ) {
			WC_Inventory_Overview_PO_Service::place( $po_id );
		}
		if ( 'cancelled' === $status ) {
			WC_Inventory_Overview_PO_Service::cancel( $po_id );
		}
		if ( 'closed_short' === $status ) {
			WC_Inventory_Overview_PO_Service::close_short( $po_id );
		}
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		return array( 'po_id' => $po_id, 'line_id' => (int) $lines[0]['id'], 'product' => $product );
	}

	/**
	 * @param int   $product_id Product id.
	 * @param int   $po_line_id PO line id.
	 * @param float $qty        Qty.
	 * @return int|WP_Error
	 */
	private function attempt_draft_against_po_line( int $product_id, int $po_line_id, float $qty = 3.0 ) {
		return WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => array( $product_id ),
				'wc_io_gr_line_qty'        => array( $qty ),
				'wc_io_gr_line_unit_cost'  => array( 2 ),
				'wc_io_gr_line_po_line_id' => array( $po_line_id ),
			)
		);
	}

	/**
	 * Draft creation itself (not just posting) is allowed against a draft PO's
	 * line only if the line/product match — but posting must still reject a
	 * draft-status PO as non-receivable. This test exercises the pre-transaction
	 * post()-time check specifically.
	 */
	public function test_draft_status_po_rejected_at_post_time() {
		$setup = $this->po_with_line_in_status( 'draft' );
		$id    = $this->attempt_draft_against_po_line( $setup['product']->get_id(), $setup['line_id'] );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_po_not_receivable', $result->get_error_code() );

		$product = wc_get_product( $setup['product']->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $product->get_stock_quantity(), 0.0001, 'Zero DB writes / stock effect on a rejected post.' );
	}

	/**
	 * Cancelled POs may never be received against.
	 */
	public function test_cancelled_po_rejected_at_post_time() {
		$setup = $this->po_with_line_in_status( 'cancelled' );
		$id    = $this->attempt_draft_against_po_line( $setup['product']->get_id(), $setup['line_id'] );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_po_not_receivable', $result->get_error_code() );
	}

	/**
	 * Closed-short POs may never be received against.
	 */
	public function test_closed_short_po_rejected_at_post_time() {
		$setup = $this->po_with_line_in_status( 'closed_short' );
		$id    = $this->attempt_draft_against_po_line( $setup['product']->get_id(), $setup['line_id'] );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_po_not_receivable', $result->get_error_code() );
	}

	/**
	 * A placed PO's line is receivable.
	 */
	public function test_placed_po_is_receivable() {
		$setup = $this->po_with_line_in_status( 'placed' );
		$id    = $this->attempt_draft_against_po_line( $setup['product']->get_id(), $setup['line_id'] );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Referencing a nonexistent PO line is rejected at draft-save time — before a
	 * draft is even created, not deferred to post time (assert_po_line_matches_product()
	 * runs during persist_draft_lines_and_costs(), inside create_draft_from_post()'s
	 * own transaction).
	 */
	public function test_nonexistent_po_line_rejected() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$result  = $this->attempt_draft_against_po_line( $product->get_id(), 999999999 );
		$this->assertWPError( $result );

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $count, 'No draft may be created when the referenced PO line does not exist.' );
	}

	/**
	 * A submitted product that doesn't match the referenced PO line's product is
	 * rejected at draft-save time — before any draft is even created.
	 */
	public function test_product_mismatch_rejected_at_draft_save_time() {
		$setup          = $this->po_with_line_in_status( 'placed' );
		$other_product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$result = $this->attempt_draft_against_po_line( $other_product->get_id(), $setup['line_id'] );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_po_line_product_mismatch', $result->get_error_code() );

		global $wpdb;
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $count, 'No draft may be created when the product mismatches.' );
	}
}
