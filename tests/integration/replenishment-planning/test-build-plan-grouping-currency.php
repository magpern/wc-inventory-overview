<?php
/**
 * M24 WP-M24-5: currency/grouping contract (§14, BR-M24-10).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Grouping_Currency extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_one_group_per_resolved_supplier() {
		$supplier_a = $this->create_supplier( array( 'default_currency' => 'EUR' ) );
		$supplier_b = $this->create_supplier( array( 'default_currency' => 'USD' ) );

		$p1 = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$p1->set_low_stock_amount( 5 );
		$p1->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p1->get_id(), $supplier_a['id'] );

		$p2 = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$p2->set_low_stock_amount( 5 );
		$p2->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p2->get_id(), $supplier_a['id'] );

		$p3 = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$p3->set_low_stock_amount( 5 );
		$p3->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $p3->get_id(), $supplier_b['id'] );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertArrayHasKey( $supplier_a['id'], $plan['groups'] );
		$this->assertArrayHasKey( $supplier_b['id'], $plan['groups'] );
		$this->assertCount( 2, $plan['groups'][ $supplier_a['id'] ]['lines'], 'Two items with the same resolved supplier must be in one group.' );
		$this->assertCount( 1, $plan['groups'][ $supplier_b['id'] ]['lines'] );

		$this->assertSame( 'EUR', $plan['groups'][ $supplier_a['id'] ]['currency'] );
		$this->assertSame( 'USD', $plan['groups'][ $supplier_b['id'] ]['currency'] );
	}

	public function test_currency_never_blended_or_converted() {
		$supplier = $this->create_supplier( array( 'default_currency' => 'SEK' ) );

		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertSame( 'SEK', $plan['groups'][ $supplier['id'] ]['currency'], 'Currency must be the supplier\'s own default_currency, no conversion.' );
	}
}
