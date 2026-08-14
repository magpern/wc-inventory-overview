<?php
/**
 * Integration tests for M23 WP-M23-2: persistence round-trip and item
 * independence for WC_Inventory_Overview_Replenishment_Defaults.
 *
 * Covers BR-M23-1 (independent, optional per-item settings), BR-M23-11
 * (no parent<->variation inheritance/rollup), BR-M23-12 (item identity
 * matches M22's own convention).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Persistence extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_round_trip_on_simple_product() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '12.5' );

		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
		$this->assertEqualsWithDelta( 12.5, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.0001 );
	}

	public function test_round_trip_on_variation() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );
		$variation_id = (int) $children[0];
		$supplier     = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $variation_id, (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $variation_id, '3' );

		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $variation_id ) );
		$this->assertEqualsWithDelta( 3.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $variation_id ), 0.0001 );
	}

	/**
	 * BR-M23-11: a variation's defaults must never be read from, or
	 * written to, its parent's post meta -- and vice versa.
	 */
	public function test_parent_and_variation_meta_are_independent() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$variation_id = (int) $children[0];
		$parent_id    = (int) $variable->get_id();
		$supplier     = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $variation_id, (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $variation_id, '7' );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $parent_id ), 'Parent must not inherit the variation\'s preferred supplier.' );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $parent_id ), 'Parent must not inherit the variation\'s default quantity.' );

		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $parent_id, '99' );
		$this->assertEqualsWithDelta( 7.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $variation_id ), 0.0001, 'Variation must not inherit a value later set on the parent.' );
	}

	/**
	 * BR-M23-11: two variations of the same parent are configured
	 * independently.
	 */
	public function test_two_variations_of_one_parent_are_independent() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'V1' ),
				array( 'name' => 'V2' ),
			)
		);
		$variable  = wc_get_product( $variable->get_id() );
		$children  = $variable->get_children();
		$this->assertCount( 2, $children );
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();

		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( (int) $children[0], (int) $supplier_a['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( (int) $children[0], '4' );

		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( (int) $children[1], (int) $supplier_b['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( (int) $children[1], '9' );

		$this->assertSame( (int) $supplier_a['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( (int) $children[0] ) );
		$this->assertSame( (int) $supplier_b['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( (int) $children[1] ) );
		$this->assertEqualsWithDelta( 4.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( (int) $children[0] ), 0.0001 );
		$this->assertEqualsWithDelta( 9.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( (int) $children[1] ), 0.0001 );
	}

	public function test_unset_item_returns_zero_defaults() {
		$product = $this->create_simple_product();

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
	}
}
