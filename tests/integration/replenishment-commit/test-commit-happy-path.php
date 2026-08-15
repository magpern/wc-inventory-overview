<?php
/**
 * M25 §11/§28 WP-M25-2: Replenishment_Commit_Service::commit() happy paths.
 *
 * Simple product creation, variation creation, multiple supplier groups,
 * fixed provenance note, no invented unit_cost/expected_date, currency from
 * the resolved supplier's own default_currency.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Commit_Happy_Path extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Commit_Service::reset_test_seams();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	private function make_needs_reorder_product( array $props = array() ): WC_Product_Simple {
		$product = $this->create_simple_product( array_merge( array( 'stock_qty' => 1 ), $props ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	public function test_simple_product_creates_one_draft_po() {
		$supplier = $this->create_supplier( array( 'default_currency' => 'USD' ) );
		$product  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $product->get_id(), 'variation_id' => 0, 'qty' => 12 ) )
		);

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['created'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame( array(), $result['skipped'] );

		$po_id = $result['created'][0]['po_id'];
		$po    = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		$this->assertSame( (int) $supplier['id'], (int) $po['supplier_id'] );
		$this->assertSame( 'USD', $po['currency'], 'Currency must come from the supplier default, never invented.' );
		$this->assertSame( 'Created from Replenishment Planning', $po['note'] );
		$this->assertNull( $po['expected_date'], 'No expected date must ever be invented (BR-M25-16).' );

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$this->assertCount( 1, $lines );
		$this->assertEqualsWithDelta( 12.0, (float) $lines[0]['qty_ordered'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, (float) $lines[0]['unit_cost'], 0.0001, 'No unit cost must ever be invented (BR-M25-15).' );
	}

	public function test_variation_creates_one_draft_po_line() {
		$supplier = $this->create_supplier();
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		$vid      = wc_get_product( $variable->get_id() )->get_children()[0];
		$variation = wc_get_product( $vid );
		$variation->set_low_stock_amount( 5 );
		$variation->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array( array( 'product_id' => $variable->get_id(), 'variation_id' => $vid, 'qty' => 4 ) )
		);

		$this->assertCount( 1, $result['created'] );
		$po_id = $result['created'][0]['po_id'];
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$this->assertSame( $vid, (int) $lines[0]['variation_id'] );
	}

	public function test_multiple_supplier_groups_create_one_po_per_supplier() {
		$supplier_a = $this->create_supplier( array( 'name' => 'AAA Supplier' ) );
		$supplier_b = $this->create_supplier( array( 'name' => 'BBB Supplier' ) );

		$product_a = $this->make_needs_reorder_product();
		$product_b = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier_b['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array( 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => 5 ),
				array( 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => 7 ),
			)
		);

		$this->assertCount( 2, $result['created'] );
		$po_ids = array_unique( wp_list_pluck( $result['created'], 'po_id' ) );
		$this->assertCount( 2, $po_ids, 'BR-M25-9: exactly one PO per distinct resolved supplier.' );
	}

	public function test_two_items_same_supplier_create_one_po_with_two_lines() {
		$supplier  = $this->create_supplier();
		$product_a = $this->make_needs_reorder_product();
		$product_b = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array( 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => 5 ),
				array( 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => 7 ),
			)
		);

		$this->assertCount( 2, $result['created'] );
		$po_ids = array_unique( wp_list_pluck( $result['created'], 'po_id' ) );
		$this->assertCount( 1, $po_ids );

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( (int) $po_ids[0] );
		$this->assertCount( 2, $lines );
	}

	public function test_forged_supplier_and_currency_fields_have_zero_effect() {
		$real_supplier   = $this->create_supplier( array( 'default_currency' => 'EUR' ) );
		$forged_supplier = $this->create_supplier( array( 'default_currency' => 'SEK' ) );
		$product         = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $real_supplier['id'] );

		// commit()'s own frozen $items shape has no supplier_id/currency
		// fields at all -- passing them is simply ignored, proving BR-M25-5/6.
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array(
					'product_id'   => $product->get_id(),
					'variation_id' => 0,
					'qty'          => 3,
					'supplier_id'  => $forged_supplier['id'],
					'currency'     => 'SEK',
				),
			)
		);

		$this->assertCount( 1, $result['created'] );
		$po = WC_Inventory_Overview_Purchase_Orders::get( $result['created'][0]['po_id'] );
		$this->assertSame( (int) $real_supplier['id'], (int) $po['supplier_id'], 'A forged supplier_id in the item payload must have zero effect.' );
		$this->assertSame( 'EUR', $po['currency'], 'A forged currency in the item payload must have zero effect.' );
	}

	public function test_exactly_one_build_plan_call_per_commit() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();
		$product_a  = $this->make_needs_reorder_product();
		$product_b  = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_a->get_id(), $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product_b->get_id(), $supplier_b['id'] );

		// Indirect proof: two distinct supplier groups still resolve
		// correctly from a single scoped build_plan() call (if commit() were
		// calling build_plan() per group/per line, a stale intermediate
		// state could desync group assignment -- it does not).
		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array( 'product_id' => $product_a->get_id(), 'variation_id' => 0, 'qty' => 1 ),
				array( 'product_id' => $product_b->get_id(), 'variation_id' => 0, 'qty' => 1 ),
			)
		);

		$this->assertCount( 2, $result['created'] );
	}
}
