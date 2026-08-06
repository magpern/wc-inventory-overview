<?php
/**
 * Integration tests for WC_Inventory_Overview_Receipt_Lines repository (M4).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Receipt_Lines_Repository extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * create() persists a line with po_line_id always NULL, regardless of what's passed.
	 */
	public function test_create_always_persists_null_po_line_id() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$product    = $this->create_simple_product();

		$line_id = WC_Inventory_Overview_Receipt_Lines::create(
			$receipt_id,
			array(
				'product_id'        => $product->get_id(),
				'qty'               => 5,
				'entered_unit_cost' => 10,
				'true_unit_cost'    => 10,
			)
		);
		$this->assertIsInt( $line_id );

		$line = WC_Inventory_Overview_Receipt_Lines::get( $line_id );
		$this->assertNull( $line['po_line_id'] );
	}

	/**
	 * The create() API accepts no po_line_id parameter at all — a structural
	 * guarantee, not a runtime-ignored optional arg.
	 */
	public function test_create_signature_has_no_po_line_id_parameter() {
		$ref = new ReflectionMethod( 'WC_Inventory_Overview_Receipt_Lines', 'create' );
		$names = array_map(
			static function ( $p ) {
				return $p->getName();
			},
			$ref->getParameters()
		);
		$this->assertNotContains( 'po_line_id', $names );
	}

	/**
	 * list_for_receipt() orders by line_index.
	 */
	public function test_list_for_receipt_orders_by_line_index() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$p1 = $this->create_simple_product();
		$p2 = $this->create_simple_product();

		$id2 = WC_Inventory_Overview_Receipt_Lines::create( $receipt_id, array( 'line_index' => 1, 'product_id' => $p2->get_id(), 'qty' => 1, 'true_unit_cost' => 1 ) );
		$id1 = WC_Inventory_Overview_Receipt_Lines::create( $receipt_id, array( 'line_index' => 0, 'product_id' => $p1->get_id(), 'qty' => 1, 'true_unit_cost' => 1 ) );

		$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $receipt_id );
		$this->assertSame( $id1, (int) $lines[0]['id'] );
		$this->assertSame( $id2, (int) $lines[1]['id'] );
	}

	/**
	 * Draft line CRUD has zero stock effect — creating/updating/deleting lines never
	 * touches WooCommerce stock or costing meta.
	 */
	public function test_draft_line_crud_has_zero_stock_effect() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$product    = $this->create_simple_product( array( 'stock_qty' => 3 ) );

		$line_id = WC_Inventory_Overview_Receipt_Lines::create(
			$receipt_id,
			array( 'product_id' => $product->get_id(), 'qty' => 100, 'true_unit_cost' => 50 )
		);
		WC_Inventory_Overview_Receipt_Lines::update( $line_id, array( 'qty' => 200 ) );
		WC_Inventory_Overview_Receipt_Lines::delete( $line_id );

		$fresh = wc_get_product( $product->get_id() );
		$this->assertEquals( 3, $fresh->get_stock_quantity(), 'Draft line mutations must never change WooCommerce stock.' );
		$this->assertNull( $this->get_product_average_cost( $fresh ), 'Draft line mutations must never write costing meta.' );
	}

	/**
	 * persist_posting_snapshot() persists the before/after fields for one line.
	 */
	public function test_persist_posting_snapshot() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$product    = $this->create_simple_product();
		$line_id    = WC_Inventory_Overview_Receipt_Lines::create( $receipt_id, array( 'product_id' => $product->get_id(), 'qty' => 5, 'true_unit_cost' => 10 ) );

		$ok = WC_Inventory_Overview_Receipt_Lines::persist_posting_snapshot(
			$line_id,
			array(
				'old_stock'             => 0,
				'new_stock'             => 5,
				'old_average_unit_cost' => null,
				'new_average_unit_cost' => 10,
				'old_inventory_value'   => 0,
				'new_inventory_value'   => 50,
			)
		);
		$this->assertTrue( $ok );

		$line = WC_Inventory_Overview_Receipt_Lines::get( $line_id );
		$this->assertEquals( 5, $line['new_stock'] );
		$this->assertEquals( 10, $line['new_average_unit_cost'] );
		$this->assertNull( $line['old_average_unit_cost'] );
	}
}
