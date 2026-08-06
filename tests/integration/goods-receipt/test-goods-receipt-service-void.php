<?php
/**
 * Integration tests for WC_Inventory_Overview_Goods_Receipt_Service::void() (M4).
 *
 * Happy-path reversal; the critical regression (post A, post B, void A, B's
 * contribution survives exactly); insufficient-stock rejection; multi-line
 * all-or-nothing rollback.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Service_Void extends WC_Inventory_Overview_Test_Case {

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
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Movements::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * @param array<int,array<string,mixed>> $lines Each: product_id, qty, unit_cost.
	 * @return int Posted receipt id.
	 */
	private function create_and_post( array $lines ): int {
		$product_ids = array();
		$qtys        = array();
		$costs       = array();
		foreach ( $lines as $line ) {
			$product_ids[] = $line['product_id'];
			$qtys[]        = $line['qty'];
			$costs[]       = $line['unit_cost'];
		}
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => $product_ids,
			'wc_io_gr_line_qty'       => $qtys,
			'wc_io_gr_line_unit_cost' => $costs,
		);
		$id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );

		return $id;
	}

	/**
	 * @param int    $id     Receipt id.
	 * @param string $reason Void reason.
	 * @return array<string,mixed>|WP_Error
	 */
	private function void_receipt( int $id, string $reason = 'test void' ) {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		return WC_Inventory_Overview_Goods_Receipt_Service::void( $id, $reason, $token );
	}

	/**
	 * Straightforward reversal: void exactly undoes the receipt's own contribution.
	 */
	public function test_void_straightforward_reversal() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$result = $this->void_receipt( $id, 'damaged' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'voided', $result['status'] );
		$this->assertSame( 'damaged', $result['void_reason'] );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh->get_stock_quantity(), 0.0001 );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d AND movement_type = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'goods_receipt',
				$id,
				'goods_receipt_void'
			)
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * THE critical regression: post A (10 @ 8.00), post B (5 @ 12.00, same
	 * product), void A. B's contribution must survive exactly.
	 */
	public function test_void_preserves_later_receipts_contribution() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );

		$receipt_a = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 10, 'unit_cost' => 8 ) ) );
		$receipt_b = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$mid = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 15.0, (float) $mid->get_stock_quantity(), 0.0001 );

		$void_result = $this->void_receipt( $receipt_a, 'wrong receipt' );
		$this->assertIsArray( $void_result, is_wp_error( $void_result ) ? $void_result->get_error_message() : '' );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 5.0, (float) $after->get_stock_quantity(), 0.0001, 'Only A\'s 10 units removed; B\'s 5 remain.' );
		$this->assertEqualsWithDelta( 60.0, $this->get_product_inventory_value( $after ), 0.0001 );
		$this->assertEqualsWithDelta( 12.0, $this->get_product_average_cost( $after ), 0.000001 );

		// B's own header/status must be completely unaffected.
		$b_row = WC_Inventory_Overview_Goods_Receipts::get( $receipt_b );
		$this->assertSame( 'posted', $b_row['status'] );
	}

	/**
	 * Void is rejected when units have since been sold below the receipt's
	 * contribution, with zero partial mutation.
	 */
	public function test_void_rejected_when_stock_insufficient() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 10, 'unit_cost' => 8 ) ) );

		// Simulate 8 units sold via a normal WooCommerce stock reduction, leaving 2.
		$p = wc_get_product( $product->get_id() );
		$p->set_stock_quantity( 2 );
		$p->save();

		$result = $this->void_receipt( $id, 'attempt after sale' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_void_insufficient_stock', $result->get_error_code() );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'posted', $receipt['status'], 'Rejected void must leave the receipt posted, not voided.' );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 2.0, (float) $fresh->get_stock_quantity(), 0.0001, 'Rejected void must not mutate stock.' );
	}

	/**
	 * Multi-line all-or-nothing: if the second line's reversal fails, the first
	 * line's already-applied reversal must also roll back.
	 */
	public function test_multi_line_void_all_or_nothing() {
		$p1 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$p2 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id = $this->create_and_post(
			array(
				array( 'product_id' => $p1->get_id(), 'qty' => 10, 'unit_cost' => 8 ),
				array( 'product_id' => $p2->get_id(), 'qty' => 10, 'unit_cost' => 8 ),
			)
		);

		// Sell down product 2's stock below the receipt's contribution.
		$sabotage = wc_get_product( $p2->get_id() );
		$sabotage->set_stock_quantity( 3 );
		$sabotage->save();

		$result = $this->void_receipt( $id, 'test' );
		$this->assertWPError( $result );

		$fresh1 = wc_get_product( $p1->get_id() );
		$this->assertEqualsWithDelta( 10.0, (float) $fresh1->get_stock_quantity(), 0.0001, 'Line 1\'s reversal must be rolled back when line 2 fails.' );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'posted', $receipt['status'] );
	}

	/**
	 * Voiding twice: the second attempt is rejected. As with double-post, a purely
	 * sequential double-void is caught by the cheap pre-transaction status check
	 * (see the equivalent comment on Test_WC_IO_Goods_Receipt_Service_Post::test_double_post_rejected());
	 * the deeper compare-and-swap layer is independently verified in
	 * Test_WC_IO_Goods_Receipts_Repository::test_compare_and_swap_void_is_conditional().
	 */
	public function test_double_void_rejected() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$first = $this->void_receipt( $id );
		$this->assertIsArray( $first );

		$second = $this->void_receipt( $id );
		$this->assertWPError( $second );
		$this->assertSame( 'wc_io_gr_not_posted', $second->get_error_code() );
	}

	/**
	 * A void reason is mandatory.
	 */
	public function test_void_requires_reason() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_and_post( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::void( $id, '   ', $token );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_void_reason', $result->get_error_code() );
	}

	/**
	 * Cannot void a draft.
	 */
	public function test_cannot_void_a_draft() {
		$product = $this->create_simple_product();
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '1' ),
			'wc_io_gr_line_unit_cost' => array( '1' ),
		);
		$id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $id );

		$result = $this->void_receipt( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_not_posted', $result->get_error_code() );
	}
}
