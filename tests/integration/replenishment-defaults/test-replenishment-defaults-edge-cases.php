<?php
/**
 * Integration tests for M23 WP-M23-5: edge cases not otherwise covered by
 * WP-M23-2/3/4's own tests -- non-stock-managed items, variable parents,
 * and deleted-product identity boundaries.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Edge_Cases extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	/**
	 * A non-stock-managed product fails PO_Product_Validator regardless of
	 * whether replenishment defaults are configured for it -- configured
	 * defaults must never bypass identity validation.
	 */
	public function test_non_stock_managed_product_stays_invalid_even_with_defaults_configured() {
		$product = $this->create_simple_product();
		$product->set_manage_stock( false );
		$product->save();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '10' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertNull( $result['line'] );
		$this->assertSame( 0, $result['supplier_id'] );
	}

	/**
	 * A variable parent is structurally non-purchasable (INV-M22-12,
	 * preserved by M23) -- even if defaults happen to be stored under its
	 * post id (e.g. via direct API misuse), resolve() never reaches the
	 * point where they would be consulted, because identity validation
	 * rejects the parent before supplier/quantity resolution runs.
	 */
	public function test_variable_parent_defaults_never_consulted() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $variable->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $variable->get_id(), '10' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id() );

		$this->assertSame( 'invalid', $result['status'] );
	}

	/**
	 * A malformed/deleted-product resolve() call must never touch
	 * Replenishment_Defaults at all (there's no valid item id to key on).
	 */
	public function test_deleted_product_id_never_consults_defaults() {
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 999999 );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertNull( $result['line'] );
	}

	/**
	 * Configuring defaults on one product must never affect an unrelated
	 * product's prefill resolution.
	 */
	public function test_defaults_configured_on_one_product_do_not_leak_to_another() {
		$configured = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$configured->set_low_stock_amount( 5 );
		$configured->save();

		$other = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$other->set_low_stock_amount( 5 );
		$other->save();

		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $configured->get_id(), (int) $preferred['id'] );
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $configured->get_id(), '42' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $other->get_id() );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertArrayNotHasKey( 'qty_ordered', $result['line'] );
	}
}
