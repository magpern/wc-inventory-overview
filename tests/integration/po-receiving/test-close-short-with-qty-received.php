<?php
/**
 * M5 audit remediation WP4: PO_Service::close_short() must account for
 * qty_received when computing qty_cancelled for a still-outstanding line.
 *
 * Before this fix, close_short() unconditionally set qty_cancelled = qty_ordered
 * for any line with outstanding > 0, ignoring qty_received entirely — on a
 * partially-received line this produced qty_received + qty_cancelled > qty_ordered
 * (a misleading "Cancelled" figure, though outstanding still floored correctly to
 * zero via GREATEST()). The fix cancels exactly what remains unreceived, so the
 * invariant qty_received + qty_cancelled == qty_ordered always holds after a line
 * is closed short.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Close_Short_With_Qty_Received extends WC_Inventory_Overview_Test_Case {

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
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Places a PO with one 10-unit line, posts a receipt for 6 units against it
	 * (leaving 4 outstanding), then closes the PO short.
	 *
	 * @return array{po_id:int,line_id:int}
	 */
	private function partially_received_po(): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$line_id = (int) WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id )[0]['id'];

		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => array( $product->get_id() ),
				'wc_io_gr_line_qty'        => array( 6 ),
				'wc_io_gr_line_unit_cost'  => array( 3 ),
				'wc_io_gr_line_po_line_id' => array( $line_id ),
			)
		);
		$this->assertIsInt( $draft_id, is_wp_error( $draft_id ) ? $draft_id->get_error_message() : '' );

		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );

		return array( 'po_id' => $po_id, 'line_id' => $line_id );
	}

	/**
	 * Closing a partially-received PO short must cancel exactly the unreceived
	 * remainder, not the full ordered quantity — preserving
	 * qty_received + qty_cancelled == qty_ordered.
	 */
	public function test_close_short_preserves_received_plus_cancelled_equals_ordered() {
		$setup = $this->partially_received_po();

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $setup['line_id'] );
		$this->assertEquals( 6.0, (float) $line['qty_received'] );
		$this->assertEquals( 0.0, (float) $line['qty_cancelled'] );
		$this->assertEquals( 4.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ) );

		$closed = WC_Inventory_Overview_PO_Service::close_short( $setup['po_id'] );
		$this->assertSame( 'closed_short', $closed['status'] );

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $setup['line_id'] );
		$this->assertEquals( 6.0, (float) $line['qty_received'], 'qty_received must be untouched by close_short().' );
		$this->assertEquals( 4.0, (float) $line['qty_cancelled'], 'qty_cancelled must equal only the unreceived remainder (4), not the full ordered qty (10).' );
		$this->assertEquals(
			(float) $line['qty_ordered'],
			(float) $line['qty_received'] + (float) $line['qty_cancelled'],
			'Invariant qty_received + qty_cancelled == qty_ordered must hold after closing short.'
		);
		$this->assertEquals( 0.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ) );
	}

	/**
	 * A line with zero qty_received behaves exactly as before this fix — the
	 * pre-existing, unmodified close_short() behaviour for lines never received
	 * against must not regress.
	 */
	public function test_close_short_with_zero_received_still_cancels_full_ordered_qty() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$line_id = (int) WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id )[0]['id'];

		WC_Inventory_Overview_PO_Service::close_short( $po_id );

		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertEquals( 0.0, (float) $line['qty_received'] );
		$this->assertEquals( 5.0, (float) $line['qty_cancelled'] );
		$this->assertEquals( 5.0, (float) $line['qty_ordered'] );
	}

	/**
	 * A fully-received line (outstanding already 0) is skipped by the
	 * close_short() loop entirely — qty_cancelled stays 0 for that line, never
	 * driving received + cancelled above ordered — while a second, still-
	 * outstanding line on the same PO is correctly closed short around it.
	 */
	public function test_close_short_skips_fully_received_line_but_closes_others() {
		$supplier   = $this->create_supplier();
		$product_a  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product_b  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id      = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array( 'product_id' => $product_a->get_id(), 'qty_ordered' => 5, 'unit_cost' => 3 ),
				array( 'product_id' => $product_b->get_id(), 'qty_ordered' => 10, 'unit_cost' => 3 ),
			)
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$po_lines  = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$line_a_id = (int) $po_lines[0]['id'];
		$line_b_id = (int) $po_lines[1]['id'];

		// Fully receive line A (5 of 5), partially receive line B (6 of 10) —
		// PO lands on 'partially_received' (a valid close_short source status).
		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => array( $product_a->get_id(), $product_b->get_id() ),
				'wc_io_gr_line_qty'        => array( 5, 6 ),
				'wc_io_gr_line_unit_cost'  => array( 3, 3 ),
				'wc_io_gr_line_po_line_id' => array( $line_a_id, $line_b_id ),
			)
		);
		$this->assertIsInt( $draft_id, is_wp_error( $draft_id ) ? $draft_id->get_error_message() : '' );
		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );
		$po_after_receiving = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( 'partially_received', $po_after_receiving['status'] );

		$closed = WC_Inventory_Overview_PO_Service::close_short( $po_id );
		$this->assertSame( 'closed_short', $closed['status'] );

		$line_a = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_a_id );
		$this->assertEquals( 5.0, (float) $line_a['qty_received'] );
		$this->assertEquals( 0.0, (float) $line_a['qty_cancelled'], 'A fully-received line must be skipped by close_short() (qty_cancelled stays 0).' );

		$line_b = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_b_id );
		$this->assertEquals( 6.0, (float) $line_b['qty_received'] );
		$this->assertEquals( 4.0, (float) $line_b['qty_cancelled'], 'The still-outstanding line must be closed for exactly its unreceived remainder (4).' );
		$this->assertEquals(
			(float) $line_b['qty_ordered'],
			(float) $line_b['qty_received'] + (float) $line_b['qty_cancelled']
		);
	}
}
