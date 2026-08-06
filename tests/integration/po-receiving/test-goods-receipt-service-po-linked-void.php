<?php
/**
 * M5: WC_Inventory_Overview_Goods_Receipt_Service::void() with po_line_id-bearing
 * lines. The critical regression tests: post A / post B / void A (survives), and
 * post A / post B / void B / void A (order-independence).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Service_PO_Linked_Void extends WC_Inventory_Overview_Test_Case {

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
	 * @param float $ordered Qty ordered.
	 * @return array{po:array,line:array,product:WC_Product_Simple}
	 */
	private function placed_po_with_line( float $ordered ): array {
		$supplier = $this->create_supplier();
		$product  = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array( array( 'product_id' => $product->get_id(), 'qty_ordered' => $ordered, 'unit_cost' => 3 ) )
		);
		$placed = WC_Inventory_Overview_PO_Service::place( $po_id );
		$lines  = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		return array( 'po' => $placed, 'line' => $lines[0], 'product' => $product );
	}

	/**
	 * @param int    $product_id Product id.
	 * @param float  $qty        Qty.
	 * @param int    $po_line_id PO line id.
	 * @return int Draft receipt id.
	 */
	private function create_draft_against_po_line( int $product_id, float $qty, int $po_line_id ): int {
		$payload = array(
			'wc_io_gr_currency'        => 'EUR',
			'wc_io_gr_line_product'    => array( $product_id ),
			'wc_io_gr_line_qty'        => array( $qty ),
			'wc_io_gr_line_unit_cost'  => array( 3 ),
			'wc_io_gr_line_po_line_id' => array( $po_line_id ),
		);
		$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $payload );
		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return $result;
	}

	/**
	 * @param int $id Receipt id.
	 * @return array|WP_Error
	 */
	private function post( int $id ) {
		return WC_Inventory_Overview_Goods_Receipt_Service::post( $id, WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) );
	}

	/**
	 * @param int $id Receipt id.
	 * @return array|WP_Error
	 */
	private function void( int $id ) {
		return WC_Inventory_Overview_Goods_Receipt_Service::void( $id, 'test void', WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' ) );
	}

	/**
	 * Scenario A — post A, post B, void A: B's contribution and the correct
	 * downgraded status survive A's void, regardless of what posted in between.
	 */
	public function test_scenario_a_post_a_post_b_void_a() {
		$setup = $this->placed_po_with_line( 10.0 );
		$line_id = (int) $setup['line']['id'];
		$product_id = $setup['product']->get_id();

		$a = $this->create_draft_against_po_line( $product_id, 6, $line_id );
		$this->post( $a );
		$b = $this->create_draft_against_po_line( $product_id, 4, $line_id );
		$this->post( $b );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertEqualsWithDelta( 10.0, (float) $fresh_line['qty_received'], 0.0001 );
		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'received', $fresh_po['status'] );

		$result = $this->void( $a );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertEqualsWithDelta( 4.0, (float) $fresh_line['qty_received'], 0.0001, "B's contribution (4) must survive A's void — not reset to 0." );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'partially_received', $fresh_po['status'] );
	}

	/**
	 * Scenario B — post A, post B, void B, void A: qty_received/outstanding/PO
	 * status remain correct at every one of the four steps, proving the
	 * current-state-relative delta composes correctly regardless of which
	 * receipt is voided first (not just the single order Scenario A covers).
	 */
	public function test_scenario_b_post_a_post_b_void_b_void_a() {
		$setup = $this->placed_po_with_line( 10.0 );
		$line_id = (int) $setup['line']['id'];
		$product_id = $setup['product']->get_id();
		$po_id = (int) $setup['po']['id'];

		$a = $this->create_draft_against_po_line( $product_id, 6, $line_id );
		$this->post( $a );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$po   = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertEqualsWithDelta( 6.0, (float) $line['qty_received'], 0.0001 );
		$this->assertEqualsWithDelta( 4.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ), 0.0001 );
		$this->assertSame( 'partially_received', $po['status'] );

		$b = $this->create_draft_against_po_line( $product_id, 4, $line_id );
		$this->post( $b );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$po   = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertEqualsWithDelta( 10.0, (float) $line['qty_received'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ), 0.0001 );
		$this->assertSame( 'received', $po['status'] );

		$this->void( $b );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$po   = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertEqualsWithDelta( 6.0, (float) $line['qty_received'], 0.0001, "Voiding B must leave exactly A's contribution (6), not zero." );
		$this->assertEqualsWithDelta( 4.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ), 0.0001 );
		$this->assertSame( 'partially_received', $po['status'] );

		$this->void( $a );
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$po   = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertEqualsWithDelta( 0.0, (float) $line['qty_received'], 0.0001, 'Voiding A after B must return to exactly the pre-receiving state (0).' );
		$this->assertEqualsWithDelta( 10.0, WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ), 0.0001 );
		$this->assertSame( 'placed', $po['status'] );
	}

	/**
	 * Voiding a PO-linked receipt is blocked (and the whole void rolls back) if
	 * intervening stock consumption makes the underlying stock reversal
	 * impossible — the same inherent non-lot-tracked-costing limitation M4
	 * already established, now verified to also protect qty_received/PO status
	 * from a partial reversal in that scenario.
	 */
	public function test_void_blocked_by_insufficient_stock_leaves_qty_received_unchanged() {
		$setup = $this->placed_po_with_line( 10.0 );
		$line_id = (int) $setup['line']['id'];
		$product_id = $setup['product']->get_id();

		$a = $this->create_draft_against_po_line( $product_id, 6, $line_id );
		$this->post( $a );

		// Simulate stock consumption (e.g. a sale) that makes voiding impossible.
		$product = wc_get_product( $product_id );
		$product->set_stock_quantity( 2 ); // Only 2 left, but the void needs to reverse 6.
		$product->save();

		$result = $this->void( $a );
		$this->assertTrue( is_wp_error( $result ), 'Void must be rejected when reversing it would drive stock negative.' );

		$fresh_line = WC_Inventory_Overview_Purchase_Order_Lines::get( $line_id );
		$this->assertEqualsWithDelta( 6.0, (float) $fresh_line['qty_received'], 0.0001, 'A blocked void must leave qty_received completely unchanged.' );

		$fresh_po = WC_Inventory_Overview_Purchase_Orders::get( (int) $setup['po']['id'] );
		$this->assertSame( 'partially_received', $fresh_po['status'], 'A blocked void must leave PO status completely unchanged.' );
	}
}
