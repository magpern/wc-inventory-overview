<?php
/**
 * Integration tests for WC_Inventory_Overview_Goods_Receipt_Service::post() (M4).
 *
 * Happy paths (single/multi-line, exact formula output, exact movement rows) and
 * forced-failure rollback (full SQL rollback: stock/avg/value unchanged, zero
 * partial movement rows, receipt stays draft).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Service_Post extends WC_Inventory_Overview_Test_Case {

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
	 * @return array<string,mixed> POST-shaped payload.
	 */
	private function build_post_payload( array $lines ): array {
		$product_ids = array();
		$qtys        = array();
		$costs       = array();
		foreach ( $lines as $line ) {
			$product_ids[] = $line['product_id'];
			$qtys[]        = $line['qty'];
			$costs[]       = $line['unit_cost'];
		}
		return array(
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => $product_ids,
			'wc_io_gr_line_qty'       => $qtys,
			'wc_io_gr_line_unit_cost' => $costs,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $lines Draft lines.
	 * @return int Draft receipt id.
	 */
	private function create_draft( array $lines ): int {
		$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $this->build_post_payload( $lines ) );
		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * @param int $id Receipt id.
	 * @return array<string,mixed>|WP_Error
	 */
	private function post_receipt( int $id ) {
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' );
		return WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
	}

	/**
	 * Single line: exact stock/average/value/movement results.
	 */
	public function test_post_single_line_exact_results() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'posted', $result['status'] );
		$this->assertNotEmpty( $result['posted_at'] );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 5.0, (float) $fresh->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 12.0, $this->get_product_average_cost( $fresh ), 0.000001 );
		$this->assertEqualsWithDelta( 60.0, $this->get_product_inventory_value( $fresh ), 0.0001 );

		global $wpdb;
		$movements = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'goods_receipt',
				$id
			),
			ARRAY_A
		);
		$this->assertCount( 1, $movements, 'Exactly one goods_receipt movement per posted line.' );
		$this->assertSame( 'goods_receipt', $movements[0]['movement_type'] );
		$this->assertEquals( $product->get_id(), $movements[0]['product_id'] );
	}

	/**
	 * Multiple lines, different products: each computed independently.
	 */
	public function test_post_multiple_different_products() {
		$p1 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$p2 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id = $this->create_draft(
			array(
				array( 'product_id' => $p1->get_id(), 'qty' => 3, 'unit_cost' => 5 ),
				array( 'product_id' => $p2->get_id(), 'qty' => 2, 'unit_cost' => 20 ),
			)
		);

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$f1 = wc_get_product( $p1->get_id() );
		$f2 = wc_get_product( $p2->get_id() );
		$this->assertEqualsWithDelta( 3.0, (float) $f1->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 2.0, (float) $f2->get_stock_quantity(), 0.0001 );
	}

	/**
	 * Duplicate product lines within one receipt compose sequentially.
	 */
	public function test_post_duplicate_product_lines_compose_sequentially() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id = $this->create_draft(
			array(
				array( 'product_id' => $product->get_id(), 'qty' => 10, 'unit_cost' => 8 ),
				array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ),
			)
		);

		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 15.0, (float) $fresh->get_stock_quantity(), 0.0001 );
		$this->assertEqualsWithDelta( 9.333333, $this->get_product_average_cost( $fresh ), 0.000001 );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'goods_receipt',
				$id
			)
		);
		$this->assertSame( 2, $count, 'Two lines for the same product must produce two separate movement rows.' );
	}

	/**
	 * A variation line posts against the variation, never the parent.
	 */
	public function test_post_against_variation() {
		$parent = $this->create_variable_product(
			array(),
			array( array( 'name' => 'Var A', 'stock_qty' => 0 ) )
		);
		wc_delete_product_transients( $parent->get_id() );
		$fresh_parent = wc_get_product( $parent->get_id() );
		$variation_id = $fresh_parent->get_children()[0];
		wc_get_product( $variation_id )->set_manage_stock( true );
		wc_get_product( $variation_id )->save();

		$id = $this->create_draft( array( array( 'product_id' => $variation_id, 'qty' => 4, 'unit_cost' => 7 ) ) );
		$result = $this->post_receipt( $id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$variation = wc_get_product( $variation_id );
		$this->assertEqualsWithDelta( 4.0, (float) $variation->get_stock_quantity(), 0.0001 );

		$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $id );
		$this->assertEquals( $variation_id, $lines[0]['variation_id'] );
		$this->assertEquals( $parent->get_id(), $lines[0]['product_id'] );
	}

	/**
	 * Posting twice: the second attempt (same already-posted receipt, fresh token)
	 * is rejected. In a purely sequential double-post, the service's cheap
	 * pre-transaction status check ("must be draft") catches this before a
	 * transaction is even opened — the deeper compare-and-swap layer inside the
	 * transaction exists specifically for the case this cheap check cannot see
	 * (two requests racing between the check and the transaction), which is
	 * independently verified at the repository level in
	 * Test_WC_IO_Goods_Receipts_Repository::test_compare_and_swap_post_is_conditional().
	 * Both layers must reject double-posting; only the specific error code differs
	 * by which layer caught it first.
	 */
	public function test_double_post_rejected() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id      = $this->create_draft( array( array( 'product_id' => $product->get_id(), 'qty' => 5, 'unit_cost' => 12 ) ) );

		$first = $this->post_receipt( $id );
		$this->assertIsArray( $first );

		$second = $this->post_receipt( $id );
		$this->assertWPError( $second );
		$this->assertSame( 'wc_io_gr_not_draft', $second->get_error_code() );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( 5.0, (float) $fresh->get_stock_quantity(), 0.0001, 'Second post must not double-apply.' );
	}

	/**
	 * Forced mid-loop failure: the second line's product has stock management
	 * disabled after the draft was created (simulating a real-world edge case),
	 * causing apply_purchase_line_change() to fail on line 2. The whole
	 * transaction must roll back, including line 1's already-applied mutation.
	 */
	public function test_forced_second_line_failure_rolls_back_first_line() {
		$p1 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$p2 = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$id = $this->create_draft(
			array(
				array( 'product_id' => $p1->get_id(), 'qty' => 10, 'unit_cost' => 8 ),
				array( 'product_id' => $p2->get_id(), 'qty' => 5, 'unit_cost' => 12 ),
			)
		);

		// Sabotage line 2's product after draft creation: disable stock management.
		$sabotage = wc_get_product( $p2->get_id() );
		$sabotage->set_manage_stock( false );
		$sabotage->save();

		$result = $this->post_receipt( $id );
		$this->assertWPError( $result, 'Posting must fail when a line product can no longer be mutated.' );

		$fresh1 = wc_get_product( $p1->get_id() );
		$this->assertEqualsWithDelta( 0.0, (float) $fresh1->get_stock_quantity(), 0.0001, 'Line 1\'s mutation must be fully rolled back.' );
		$this->assertNull( $this->get_product_average_cost( $fresh1 ), 'Line 1\'s cost meta must be unset after rollback.' );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		$this->assertSame( 'draft', $receipt['status'], 'Receipt must remain draft after a failed post.' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Movements::table_name() . ' WHERE reference_type = %s AND reference_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'goods_receipt',
				$id
			)
		);
		$this->assertSame( 0, $count, 'Zero partial movement rows after a failed post.' );
	}

	/**
	 * Posting a receipt with no lines is rejected before any transaction opens.
	 */
	public function test_post_rejects_empty_receipt() {
		$id     = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$result = $this->post_receipt( $id );
		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_no_lines', $result->get_error_code() );
	}
}
