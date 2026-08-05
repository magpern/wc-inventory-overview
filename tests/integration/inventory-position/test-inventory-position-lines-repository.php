<?php
/**
 * Integration tests for the M3 bulk open-line repository reads on
 * WC_Inventory_Overview_Purchase_Order_Lines.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Inventory_Position_Lines_Repository extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema/sequence and clear PO + supplier rows for isolation.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
	}

	/**
	 * Truncate PO aggregate tables and suppliers so numbering/sequence resets stay unique.
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Place a draft PO, failing the test on error.
	 *
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
	 * Variation IDs for a just-created variable product. create_variable_product()
	 * saves the parent before its variations exist, which primes WooCommerce's
	 * `wc_product_children_{id}` transient to an empty list; the parent object's
	 * own get_children() then serves that stale in-memory/transient cache
	 * (pre-existing WooCommerce-version behavior, unrelated to M3). Clearing
	 * the transient and re-fetching a fresh product instance works around it
	 * locally within these tests only.
	 *
	 * @param WC_Product_Variable $parent Variable product.
	 * @return int[]
	 */
	private function variation_ids( WC_Product_Variable $parent ): array {
		wc_delete_product_transients( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		return $fresh instanceof WC_Product_Variable ? $fresh->get_children() : array();
	}

	/**
	 * Draft POs are excluded from open-line results.
	 */
	public function test_draft_po_lines_excluded() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 5,
			)
		);

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertSame( array(), $rows );
	}

	/**
	 * Placed POs are included, with the M2 outstanding formula applied.
	 */
	public function test_placed_po_lines_included() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$line    = $this->add_po_line(
			$po['id'],
			array(
				'product_id'    => $product->get_id(),
				'qty_ordered'   => 5,
				'qty_cancelled' => 1,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );

		$this->assertCount( 1, $rows );
		$this->assertSame( (int) $line['id'], (int) $rows[0]['line_id'] );
		$this->assertSame( (int) $po['id'], (int) $rows[0]['po_id'] );
		$this->assertSame( $po['po_number'], $rows[0]['po_number'] );
		$this->assertSame( (int) $product->get_id(), (int) $rows[0]['product_id'] );
		$this->assertSame( 0, (int) $rows[0]['variation_id'] );
		$this->assertEqualsWithDelta( 4.0, (float) $rows[0]['outstanding'], 0.0001 );
	}

	/**
	 * Cancelled POs (header status) are excluded even though their lines still exist.
	 */
	public function test_cancelled_po_excluded() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 5,
			)
		);
		$this->place_po( $po['id'] );

		$cancelled = WC_Inventory_Overview_PO_Service::cancel( $po['id'] );
		$this->assertFalse( is_wp_error( $cancelled ) );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertSame( array(), $rows );
	}

	/**
	 * Closed-short POs are excluded.
	 */
	public function test_closed_short_po_excluded() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 5,
			)
		);
		$this->place_po( $po['id'] );

		$closed = WC_Inventory_Overview_PO_Service::close_short( $po['id'] );
		$this->assertFalse( is_wp_error( $closed ) );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertSame( array(), $rows );
	}

	/**
	 * Two independent lines for the same product remain two separate rows (INV-1).
	 */
	public function test_multiple_independent_lines_preserved() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 7,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );

		$this->assertCount( 2, $rows );
		$outstanding = array_map( static fn( $r ) => (float) $r['outstanding'], $rows );
		sort( $outstanding );
		$this->assertEqualsWithDelta( array( 3.0, 7.0 ), $outstanding, 0.0001 );
		$this->assertNotSame( $rows[0]['line_id'], $rows[1]['line_id'] );
	}

	/**
	 * Simple-product and variation lines are correctly isolated by keying (INV-8).
	 */
	public function test_simple_product_and_variation_keying_are_isolated() {
		$simple   = $this->create_simple_product();
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'Variation A' ) ) );
		$children = $this->variation_ids( $variable );
		$this->assertNotEmpty( $children );
		$variation_id = (int) $children[0];

		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $simple->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => $variation_id,
				'qty_ordered'  => 6,
			)
		);
		$this->place_po( $po['id'] );

		$product_rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $simple->get_id() ) );
		$this->assertCount( 1, $product_rows );
		$this->assertSame( (int) $simple->get_id(), (int) $product_rows[0]['product_id'] );
		$this->assertSame( 0, (int) $product_rows[0]['variation_id'] );

		$variation_rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_variation_ids( array( $variation_id ) );
		$this->assertCount( 1, $variation_rows );
		$this->assertSame( $variation_id, (int) $variation_rows[0]['variation_id'] );
		$this->assertEqualsWithDelta( 6.0, (float) $variation_rows[0]['outstanding'], 0.0001 );

		// Querying variations must not surface the simple-product line and vice versa.
		$this->assertSame( 4.0, (float) $product_rows[0]['outstanding'] );
	}

	/**
	 * The SQL is_delayed flag matches WC_Inventory_Overview_PO_Delay's PHP calculation.
	 */
	public function test_delayed_flag_matches_php_delay_calculation() {
		$product   = $this->create_simple_product();
		$past_date = gmdate( 'Y-m-d', strtotime( '-10 days' ) );
		$po        = $this->create_purchase_order(
			array(
				'expected_date'       => $past_date,
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 2,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertCount( 1, $rows );

		$expected_delayed = WC_Inventory_Overview_PO_Delay::is_line_delayed(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			2.0,
			$past_date,
			WC_Inventory_Overview_PO_Confidence::EXACT,
			WC_Inventory_Overview_PO_Delay::grace_days_from_option()
		);

		$this->assertTrue( $expected_delayed );
		$this->assertEquals( $expected_delayed, (bool) $rows[0]['is_delayed'] );
	}

	/**
	 * A future expected date is never flagged as delayed.
	 */
	public function test_future_expected_date_is_not_delayed() {
		$product = $this->create_simple_product();
		$future  = gmdate( 'Y-m-d', strtotime( '+10 days' ) );
		$po      = $this->create_purchase_order(
			array(
				'expected_date'       => $future,
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 2,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertFalse( (bool) $rows[0]['is_delayed'] );
	}

	/**
	 * Empty ID lists return an empty result without issuing malformed SQL.
	 */
	public function test_empty_id_lists_return_empty_result() {
		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array() ) );
		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_variation_ids( array() ) );
	}

	/**
	 * Returned line rows never carry a qty_received key.
	 */
	public function test_no_qty_received_dependency() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 2,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'qty_received', $rows[0] );
	}

	/**
	 * A fully-cancelled line (outstanding = 0) on an otherwise placed PO does not qualify as open.
	 */
	public function test_fully_cancelled_line_excluded() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'    => $product->get_id(),
				'qty_ordered'   => 5,
				'qty_cancelled' => 5,
			)
		);
		$this->place_po( $po['id'] );

		$rows = WC_Inventory_Overview_Purchase_Order_Lines::list_open_lines_for_product_ids( array( $product->get_id() ) );
		$this->assertSame( array(), $rows );
	}
}
