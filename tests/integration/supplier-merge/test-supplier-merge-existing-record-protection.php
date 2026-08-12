<?php
/**
 * WP3 remediation (M17-F1, WP2 audit finding): a merged (permanently
 * dissolved) supplier must never become newly associated with an EXISTING
 * Purchase Order or Goods Receipt via its update paths, not just at draft
 * creation time. Closes the gap the WP2 audit found in
 * PO_Service::update_draft()/update_placed() and
 * Goods_Receipt_Service::update_draft_from_post().
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Merge_Existing_Record_Protection extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Supplier_Merges::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Goods_Receipts::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * A merged supplier's ID, ready to use as a crafted update target.
	 */
	private function create_merged_supplier_id(): int {
		$source = $this->create_supplier( array( 'name' => 'Merge Source-' . uniqid() ) );
		$target = $this->create_supplier( array( 'name' => 'Merge Target-' . uniqid() ) );
		$result = WC_Inventory_Overview_Supplier_Merge_Service::merge( (int) $source['id'], (int) $target['id'], 1, $source['name'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		return (int) $source['id'];
	}

	/**
	 * (a) Updating a DRAFT PO's supplier to a merged supplier is rejected.
	 */
	public function test_update_draft_po_rejects_merged_supplier() {
		$original_supplier = $this->create_supplier();
		$merged_supplier_id = $this->create_merged_supplier_id();
		$product            = $this->create_simple_product();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $original_supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 5,
					'unit_cost'   => 3,
				),
			)
		);
		$this->assertIsInt( $po_id );

		$result = WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array( 'supplier_id' => $merged_supplier_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_supplier_inactive', $result->get_error_code() );

		// Verify no mutation occurred.
		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( (int) $original_supplier['id'], (int) $po['supplier_id'] );
	}

	/**
	 * (b) Updating a PLACED PO's supplier to a merged supplier is rejected.
	 */
	public function test_update_placed_po_rejects_merged_supplier() {
		$original_supplier  = $this->create_supplier();
		$merged_supplier_id = $this->create_merged_supplier_id();
		$product             = $this->create_simple_product( array( 'stock_qty' => 7 ) );

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $original_supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 5,
					'unit_cost'   => 3,
				),
			)
		);
		$placed = WC_Inventory_Overview_PO_Service::place( $po_id );
		$this->assertSame( 'placed', $placed['status'] );

		$result = WC_Inventory_Overview_PO_Service::update_placed(
			$po_id,
			array( 'supplier_id' => $merged_supplier_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_po_supplier_inactive', $result->get_error_code() );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( (int) $original_supplier['id'], (int) $po['supplier_id'] );
	}

	/**
	 * (c) Updating a draft Goods Receipt's supplier to a merged supplier is rejected.
	 */
	public function test_update_draft_gr_rejects_merged_supplier() {
		$original_supplier  = $this->create_supplier();
		$merged_supplier_id = $this->create_merged_supplier_id();
		$product             = $this->create_simple_product();

		$src = array(
			'wc_io_gr_supplier_id'    => (int) $original_supplier['id'],
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '5' ),
			'wc_io_gr_line_unit_cost' => array( '10' ),
		);
		$receipt_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $receipt_id, is_wp_error( $receipt_id ) ? $receipt_id->get_error_message() : '' );

		$src['wc_io_gr_supplier_id'] = $merged_supplier_id;
		$result = WC_Inventory_Overview_Goods_Receipt_Service::update_draft_from_post( $receipt_id, $src );

		$this->assertWPError( $result );
		$this->assertSame( 'wc_io_gr_supplier_inactive', $result->get_error_code() );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $receipt_id );
		$this->assertSame( (int) $original_supplier['id'], (int) $receipt['supplier_id'] );
	}

	/**
	 * (d) Ordinary (non-merged, active-to-active) supplier reassignment on an
	 * existing draft PO still works -- regression proof this fix doesn't
	 * block legitimate supplier corrections.
	 */
	public function test_update_draft_po_ordinary_supplier_change_still_works() {
		$original_supplier = $this->create_supplier();
		$new_supplier       = $this->create_supplier( array( 'name' => 'Corrected Supplier-' . uniqid() ) );
		$product            = $this->create_simple_product();

		$po_id = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $original_supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 5,
					'unit_cost'   => 3,
				),
			)
		);

		$result = WC_Inventory_Overview_PO_Service::update_draft(
			$po_id,
			array( 'supplier_id' => (int) $new_supplier['id'] )
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( (int) $new_supplier['id'], (int) $result['supplier_id'] );
	}

	/**
	 * (e) Ordinary Goods Receipt draft supplier correction still works.
	 */
	public function test_update_draft_gr_ordinary_supplier_change_still_works() {
		$original_supplier = $this->create_supplier();
		$new_supplier       = $this->create_supplier( array( 'name' => 'Corrected GR Supplier-' . uniqid() ) );
		$product            = $this->create_simple_product();

		$src = array(
			'wc_io_gr_supplier_id'    => (int) $original_supplier['id'],
			'wc_io_gr_currency'       => 'EUR',
			'wc_io_gr_line_product'   => array( $product->get_id() ),
			'wc_io_gr_line_qty'       => array( '5' ),
			'wc_io_gr_line_unit_cost' => array( '10' ),
		);
		$receipt_id = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
		$this->assertIsInt( $receipt_id, is_wp_error( $receipt_id ) ? $receipt_id->get_error_message() : '' );

		$src['wc_io_gr_supplier_id'] = (int) $new_supplier['id'];
		$result = WC_Inventory_Overview_Goods_Receipt_Service::update_draft_from_post( $receipt_id, $src );

		$this->assertIsInt( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $receipt_id );
		$this->assertSame( (int) $new_supplier['id'], (int) $receipt['supplier_id'] );
	}
}
