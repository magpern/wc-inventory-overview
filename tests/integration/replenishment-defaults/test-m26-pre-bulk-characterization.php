<?php
/**
 * M26 WP-M26-0 characterization: pin M23 defaults contracts that bulk-apply
 * must preserve, BEFORE any M26 production bulk writer lands.
 *
 * These assertions are expected green against the pre-M26 (v1.42.0) code.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_M26_Pre_Bulk_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_simple_product_defaults_round_trip() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		$id       = $product->get_id();

		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $id, (int) $supplier['id'] ) );
		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $id, '12.5' ) );
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $id ) );
		$this->assertSame( 12.5, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $id ) );
	}

	public function test_variation_defaults_round_trip_independent_of_parent() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'Red', 'stock_qty' => 1 ),
			)
		);
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );
		$vid      = (int) $children[0];
		$supplier = $this->create_supplier();

		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier['id'] ) );
		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $vid, '7' ) );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $variable->get_id() ), 'No inheritance to parent.' );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $variable->get_id() ), 'No inheritance to parent.' );
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $vid ) );
	}

	public function test_single_item_stale_same_id_supplier_is_noop() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		$this->assertTrue( $result );
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_qty_normalization_blank_clears_zero_rejected() {
		$product = $this->create_simple_product();
		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '3' ) );
		$this->assertTrue( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '' ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
		$this->assertWPError( WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '0' ) );
	}

	public function test_field_isolation_supplier_only_leaves_qty() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		$id       = $product->get_id();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $id, '9' );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $id, (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $id, 0 );
		$this->assertSame( 9.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $id ) );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $id ) );
	}

	public function test_prefill_consumes_variation_defaults() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'Blue', 'stock_qty' => 0 ),
			)
		);
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );
		$vid      = (int) $children[0];
		$supplier = $this->create_supplier( array( 'default_currency' => 'EUR' ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $vid, (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $vid, '15' );

		$variation = wc_get_product( $vid );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 0 );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id(), $vid );
		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( (int) $supplier['id'], (int) $result['supplier_id'] );
		$this->assertSame( '15', $result['line']['qty_ordered'] );
	}
}
