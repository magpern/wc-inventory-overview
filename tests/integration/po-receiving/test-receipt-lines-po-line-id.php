<?php
/**
 * M5: WC_Inventory_Overview_Receipt_Lines::create() now correctly persists a
 * non-null po_line_id; update() still refuses to change it post-creation.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Receipt_Lines_PO_Line_Id extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Receipt_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_Goods_Receipt_Numbering::OPTION_KEY );
	}

	/**
	 * create() persists a positive po_line_id when supplied.
	 */
	public function test_create_persists_po_line_id() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$this->assertIsInt( $receipt_id );

		$line_id = WC_Inventory_Overview_Receipt_Lines::create(
			$receipt_id,
			array( 'product_id' => 5, 'qty' => 2, 'po_line_id' => 77 )
		);
		$this->assertIsInt( $line_id );

		$line = WC_Inventory_Overview_Receipt_Lines::get( $line_id );
		$this->assertSame( 77, (int) $line['po_line_id'] );
	}

	/**
	 * create() persists NULL when po_line_id is absent or zero (M4 direct-line
	 * behavior, unchanged).
	 */
	public function test_create_persists_null_po_line_id_when_absent_or_zero() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );

		$line_id_absent = WC_Inventory_Overview_Receipt_Lines::create( $receipt_id, array( 'product_id' => 5, 'qty' => 2 ) );
		$line_absent    = WC_Inventory_Overview_Receipt_Lines::get( $line_id_absent );
		$this->assertNull( $line_absent['po_line_id'] );

		$line_id_zero = WC_Inventory_Overview_Receipt_Lines::create( $receipt_id, array( 'product_id' => 5, 'qty' => 2, 'po_line_id' => 0 ) );
		$line_zero    = WC_Inventory_Overview_Receipt_Lines::get( $line_id_zero );
		$this->assertNull( $line_zero['po_line_id'] );
	}

	/**
	 * update() silently ignores po_line_id — it is not in the allowed whitelist,
	 * so a caller attempting to change it post-creation has no effect.
	 */
	public function test_update_never_changes_po_line_id() {
		$receipt_id = WC_Inventory_Overview_Goods_Receipts::create_draft( array( 'currency' => 'EUR' ) );
		$line_id    = WC_Inventory_Overview_Receipt_Lines::create(
			$receipt_id,
			array( 'product_id' => 5, 'qty' => 2, 'po_line_id' => 77 )
		);

		$updated = WC_Inventory_Overview_Receipt_Lines::update( $line_id, array( 'po_line_id' => 999, 'qty' => 3 ) );
		$this->assertTrue( $updated );

		$line = WC_Inventory_Overview_Receipt_Lines::get( $line_id );
		$this->assertSame( 77, (int) $line['po_line_id'], 'po_line_id must remain unchanged — update() has no path to it.' );
		$this->assertEqualsWithDelta( 3.0, (float) $line['qty'], 0.0001, 'Other fields in the same call must still update normally.' );
	}
}
