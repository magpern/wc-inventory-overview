<?php
/**
 * Unit/validation tests for M23 WP-M23-2:
 * WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier()
 * / save_default_qty().
 *
 * Covers BR-M23-6 (save-time supplier eligibility), BR-M23-7 (stale-resubmit
 * no-op), BR-M23-10 (supplier clear), BR-M23-14 (quantity numeric/>0/4dp/no
 * upper bound), BR-M23-15 (blank quantity clears).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Validation extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// ---------------------------------------------------------------
	// save_preferred_supplier()
	// ---------------------------------------------------------------

	public function test_save_zero_clears() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), 0 );

		$this->assertTrue( $result );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_save_valid_active_supplier_succeeds() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );

		$this->assertTrue( $result );
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_save_nonexistent_supplier_rejected() {
		$product = $this->create_simple_product();

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), 999999 );

		$this->assertWPError( $result );
		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_save_archived_supplier_rejected() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );

		$this->assertWPError( $result );
	}

	public function test_save_merged_supplier_rejected() {
		$product = $this->create_simple_product();
		$source  = $this->create_supplier();
		$target  = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::mark_merged( $source['id'], $target['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $source['id'] );

		$this->assertWPError( $result );
	}

	/**
	 * BR-M23-7: resubmitting the currently stored value is always a
	 * no-op, even once that supplier has become ineligible -- the
	 * silent-clobber guard.
	 */
	public function test_resubmitting_stored_stale_supplier_is_a_no_op() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );

		$this->assertTrue( $result );
		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ), 'The stale value must remain stored, not be cleared.' );
	}

	/**
	 * Choosing a genuinely NEW ineligible supplier (not the one already
	 * stored) must still be rejected -- the no-op exception is narrow.
	 */
	public function test_choosing_a_new_ineligible_supplier_is_rejected() {
		$product          = $this->create_simple_product();
		$already_archived = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::archive( $already_archived['id'] );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $already_archived['id'] );

		$this->assertWPError( $result );
	}

	// ---------------------------------------------------------------
	// save_default_qty()
	// ---------------------------------------------------------------

	public function test_save_qty_blank_clears() {
		$product = $this->create_simple_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '10' );

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '' );

		$this->assertTrue( $result );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
	}

	/**
	 * @dataProvider provide_valid_quantities
	 */
	public function test_save_qty_valid_values_accepted( $raw, float $expected ) {
		$product = $this->create_simple_product();

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), $raw );

		$this->assertTrue( $result );
		$this->assertEqualsWithDelta( $expected, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.00001 );
	}

	public function provide_valid_quantities(): array {
		return array(
			'integer string'       => array( '10', 10.0 ),
			'small decimal'        => array( '0.0001', 0.0001 ),
			'large, no upper bound' => array( '100000', 100000.0 ),
			'three decimals'       => array( '2.5', 2.5 ),
			'numeric int type'     => array( 5, 5.0 ),
		);
	}

	/**
	 * @dataProvider provide_invalid_quantities
	 */
	public function test_save_qty_invalid_values_rejected( $raw ) {
		$product = $this->create_simple_product();

		$result = WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), $raw );

		$this->assertWPError( $result );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
	}

	public function provide_invalid_quantities(): array {
		return array(
			'zero'        => array( '0' ),
			'negative'    => array( '-5' ),
			'non-numeric' => array( 'abc' ),
		);
	}

	public function test_save_qty_rounds_to_four_decimals() {
		$product = $this->create_simple_product();

		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '1.23456789' );

		$this->assertEqualsWithDelta( 1.2346, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.00001 );
	}

	// ---------------------------------------------------------------
	// Getters on unset items.
	// ---------------------------------------------------------------

	public function test_unset_getters_return_zero() {
		$product = $this->create_simple_product();

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
	}
}
