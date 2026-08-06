<?php
/**
 * M5 required regression: M3's Inventory Position "Incoming" figure must
 * correctly decrease as receipts post against PO lines — the exact behavior
 * M3's own plan explicitly deferred to M5 ("Incoming is untouched... only
 * changes once qty_received exists, in M5").
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_M3_Incoming_Regression extends WC_Inventory_Overview_Test_Case {

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
	 * Posting a partial PO-linked receipt decreases Incoming by exactly the
	 * received quantity; voiding it restores Incoming.
	 */
	public function test_incoming_decreases_on_partial_receipt_and_restores_on_void() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 10, 'unit_cost' => 2 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$line_id = (int) WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id )[0]['id'];

		$before = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			0.0
		);
		$this->assertEqualsWithDelta( 10.0, $before['incoming'], 0.0001 );

		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => array( $product->get_id() ),
				'wc_io_gr_line_qty'        => array( 4 ),
				'wc_io_gr_line_unit_cost'  => array( 2 ),
				'wc_io_gr_line_po_line_id' => array( $line_id ),
			)
		);
		$posted = WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
		$this->assertIsArray( $posted, is_wp_error( $posted ) ? $posted->get_error_message() : '' );

		$after_post = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			4.0 // On Hand now reflects the posted receipt too.
		);
		$this->assertEqualsWithDelta( 6.0, $after_post['incoming'], 0.0001, 'Incoming must decrease by exactly the received quantity.' );
		$this->assertEqualsWithDelta( 10.0, $after_post['position'], 0.0001, 'Position (On Hand + Incoming) is conserved across a partial receipt.' );

		$voided = WC_Inventory_Overview_Goods_Receipt_Service::void( $draft_id, 'test', WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' ) );
		$this->assertIsArray( $voided, is_wp_error( $voided ) ? $voided->get_error_message() : '' );

		$after_void = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			0.0
		);
		$this->assertEqualsWithDelta( 10.0, $after_void['incoming'], 0.0001, 'Voiding must restore Incoming to its pre-receipt value.' );
	}

	/**
	 * A fully-received PO line drops Incoming to exactly zero (the PO's new
	 * 'received' status is included in query_open_lines()'s WHERE clause for
	 * completeness, but its HAVING outstanding > 0 clause still excludes it).
	 */
	public function test_incoming_reaches_zero_on_full_receipt() {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => 5, 'unit_cost' => 2 ) )
		);
		WC_Inventory_Overview_PO_Service::place( $po_id );
		$line_id = (int) WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id )[0]['id'];

		$draft_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post(
			array(
				'wc_io_gr_currency'        => 'EUR',
				'wc_io_gr_line_product'    => array( $product->get_id() ),
				'wc_io_gr_line_qty'        => array( 5 ),
				'wc_io_gr_line_unit_cost'  => array( 2 ),
				'wc_io_gr_line_po_line_id' => array( $line_id ),
			)
		);
		WC_Inventory_Overview_Goods_Receipt_Service::post( $draft_id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( 'received', $po['status'] );

		$position = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			5.0
		);
		$this->assertEqualsWithDelta( 0.0, $position['incoming'], 0.0001 );
	}
}
