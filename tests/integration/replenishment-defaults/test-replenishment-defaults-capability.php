<?php
/**
 * Integration tests for M23 WP-M23-3: capability matrix for
 * WC_Inventory_Overview_Product_Replenishment_Admin.
 *
 * Covers BR-M23-19 (render/save gated identically by WooCommerce's own
 * edit_product capability) and INV-M23-20 (no render/save asymmetry, no
 * Purchasing_Caps dependency -- changing its filterable capability map
 * has zero effect on this surface).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Replenishment_Defaults_Capability extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function tearDown(): void {
		$_POST = array();
		remove_all_filters( 'wc_io_purchasing_capability_map' );
		parent::tearDown();
	}

	private function render_simple( int $product_id ): string {
		global $thepostid;
		$thepostid = $product_id;

		ob_start();
		WC_Inventory_Overview_Product_Replenishment_Admin::render_simple_fields();
		$output = ob_get_clean();

		$thepostid = null;
		return $output;
	}

	public function test_administrator_can_render_and_save() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$output = $this->render_simple( $product->get_id() );
		$this->assertStringContainsString( '_wc_io_preferred_supplier_id', $output );

		$_POST['_wc_io_preferred_supplier_id'] = (string) $supplier['id'];
		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_subscriber_can_neither_render_nor_save() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$output = $this->render_simple( $product->get_id() );
		$this->assertStringNotContainsString( '_wc_io_preferred_supplier_id', $output, 'A user without edit_product must never see the fields.' );

		$_POST['_wc_io_preferred_supplier_id'] = (string) $supplier['id'];
		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ), 'A user without edit_product must never be able to save.' );
	}

	public function test_variation_render_and_save_also_gated() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$variation_id = (int) $children[0];
		$supplier      = $this->create_supplier();

		$variation = get_post( $variation_id );
		ob_start();
		WC_Inventory_Overview_Product_Replenishment_Admin::render_variation_fields( 0, array(), $variation );
		$output = ob_get_clean();
		$this->assertStringNotContainsString( '_wc_io_preferred_supplier_id', $output );

		$_POST['_wc_io_preferred_supplier_id'] = array( 0 => (string) $supplier['id'] );
		WC_Inventory_Overview_Product_Replenishment_Admin::save_variation_fields( $variation_id, 0 );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $variation_id ) );
	}

	/**
	 * INV-M23-20: narrowing Purchasing_Caps::EDIT_PO to an unassigned
	 * capability (as M22's own capability tests do) must have zero effect
	 * on this surface -- an administrator, who never held that capability
	 * under the filtered map, can still configure replenishment defaults.
	 */
	public function test_purchasing_caps_filter_has_no_effect_on_this_surface() {
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( array $map ): array {
				foreach ( $map as $action => $cap ) {
					$map[ $action ] = 'wc_io_test_cap_unassigned_to_anyone';
				}
				return $map;
			}
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->assertFalse(
			WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ),
			'Sanity check: the filtered map must actually strip EDIT_PO from the administrator.'
		);

		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$output = $this->render_simple( $product->get_id() );
		$this->assertStringContainsString( '_wc_io_preferred_supplier_id', $output, 'Replenishment defaults must remain configurable even when Purchasing_Caps::EDIT_PO is unavailable.' );

		$_POST['_wc_io_preferred_supplier_id'] = (string) $supplier['id'];
		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}
}
