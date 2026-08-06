<?php
/**
 * Integration tests for M4 idempotency: one-shot request tokens + compare-and-swap.
 *
 * The two mechanisms are tested independently: a reused/browser-resubmitted token
 * is rejected before any DB work; a status race (simulated by a bare
 * compare-and-swap call ahead of the service) is rejected with zero partial writes.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Idempotency extends WC_Inventory_Overview_Test_Case {

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
	 * @return int Draft receipt id with one line.
	 */
	private function create_draft_with_one_line(): int {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$src = array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '5' ),
			'wc_io_gr_line_unit_cost' => array( '12' ),
		);
		$id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * A reused token (browser refresh / double-submit simulation): the second
	 * post() call with the SAME token value must be rejected before any DB work,
	 * even though the receipt is still a draft at that point (single-use, not
	 * status-derived).
	 */
	public function test_reused_token_rejected_on_double_submit() {
		$id    = $this->create_draft_with_one_line();
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );

		$first = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		$this->assertIsArray( $first, is_wp_error( $first ) ? $first->get_error_message() : '' );

		// Simulate a second draft, then attempt to post it by resubmitting the SAME
		// (now-consumed) token — this must fail on the token, never reach the
		// compare-and-swap check at all.
		$id2 = $this->create_draft_with_one_line();
		$resubmit = WC_Inventory_Overview_Goods_Receipt_Service::post( $id2, $token );
		$this->assertWPError( $resubmit );
		$this->assertSame( 'wc_io_gr_token', $resubmit->get_error_code() );

		$receipt2 = WC_Inventory_Overview_Goods_Receipts::get( $id2 );
		$this->assertSame( 'draft', $receipt2['status'], 'A rejected-by-token post must perform zero DB work.' );
	}

	/**
	 * An unknown/expired token is rejected the same way.
	 */
	public function test_unknown_token_rejected() {
		$id     = $this->create_draft_with_one_line();
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, 'not-a-real-token' );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_token', $result->get_error_code() );
	}

	/**
	 * Simulated status race: an out-of-band status flip to 'posted' (simulating a
	 * concurrent request that won the race) is caught by the service's cheap
	 * pre-transaction status check with zero partial writes — independent of the
	 * token layer (token is valid and fresh here). The deeper compare-and-swap
	 * layer inside the transaction — which exists specifically for a request that
	 * *passes* the cheap check but loses a true concurrent race before its own
	 * UPDATE runs — is independently, directly verified at the repository level in
	 * Test_WC_IO_Goods_Receipts_Repository::test_compare_and_swap_post_is_conditional()
	 * (a synchronous PHPUnit test cannot reproduce genuine request concurrency).
	 */
	public function test_status_race_aborts_cleanly_with_zero_partial_writes() {
		$id = $this->create_draft_with_one_line();

		// Simulate a concurrent request that already posted this receipt.
		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, $this->admin_id );

		$token  = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_not_draft', $result->get_error_code() );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'goods_receipt',
				$id
			)
		);
		$this->assertSame( 0, $count, 'A status-race abort must produce zero movement rows (the concurrent winner already wrote its own).' );
	}

	/**
	 * Void's token and compare-and-swap are independently tested the same way.
	 */
	public function test_void_reused_token_and_status_race() {
		$id = $this->create_draft_with_one_line();
		$post_token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $post_token );

		$void_token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		$first = WC_Inventory_Overview_Goods_Receipt_Service::void( $id, 'first', $void_token );
		$this->assertIsArray( $first );

		// Reused token on a fresh posted receipt.
		$id2 = $this->create_draft_with_one_line();
		$post_token2 = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		WC_Inventory_Overview_Goods_Receipt_Service::post( $id2, $post_token2 );
		$resubmit = WC_Inventory_Overview_Goods_Receipt_Service::void( $id2, 'second', $void_token );
		$this->assertWPError( $resubmit );
		$this->assertSame( 'wc_io_gr_token', $resubmit->get_error_code() );

		// Status race on void — caught by the cheap pre-check (see the equivalent
		// post() comment above); the deeper layer is verified at the repository
		// level in test_compare_and_swap_void_is_conditional().
		$id3 = $this->create_draft_with_one_line();
		$post_token3 = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		WC_Inventory_Overview_Goods_Receipt_Service::post( $id3, $post_token3 );
		WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id3, $this->admin_id, 'concurrent winner' );
		$void_token3 = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' );
		$race = WC_Inventory_Overview_Goods_Receipt_Service::void( $id3, 'loser', $void_token3 );
		$this->assertWPError( $race );
		$this->assertSame( 'wc_io_gr_not_posted', $race->get_error_code() );
	}
}
