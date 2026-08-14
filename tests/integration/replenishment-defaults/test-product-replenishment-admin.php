<?php
/**
 * Integration tests for M23 WP-M23-3:
 * WC_Inventory_Overview_Product_Replenishment_Admin's product/variation
 * configuration UI and save orchestration.
 *
 * Covers BR-M23-18/19 (single new mutation surface, capability parity),
 * INV-M23-20 (render/save gate parity), the index-arrayed variation field
 * convention (§15 of the M23 plan), the "(unavailable)" stored-value
 * dropdown injection and no-op-resubmit silent-clobber guard (§8), and
 * the >20-supplier dropdown-truncation regression (§4 of the M23 plan).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Product_Replenishment_Admin extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		$_POST = array();
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

	private function render_variation( int $variation_id, int $loop ): string {
		$variation = get_post( $variation_id );

		ob_start();
		WC_Inventory_Overview_Product_Replenishment_Admin::render_variation_fields( $loop, array(), $variation );
		return ob_get_clean();
	}

	// ---------------------------------------------------------------
	// Simple product: render + save round trip.
	// ---------------------------------------------------------------

	public function test_simple_product_fields_render_with_option_list() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier( array( 'name' => 'Acme Supply Co' ) );

		$output = $this->render_simple( $product->get_id() );

		$this->assertStringContainsString( 'name="_wc_io_preferred_supplier_id"', $output );
		$this->assertStringContainsString( 'name="_wc_io_default_replenishment_qty"', $output );
		$this->assertStringContainsString( 'Acme Supply Co', $output );
		$this->assertStringContainsString( 'Applies to this product only.', $output );
	}

	public function test_simple_product_save_round_trip() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$_POST['_wc_io_preferred_supplier_id']  = (string) $supplier['id'];
		$_POST['_wc_io_default_replenishment_qty'] = '8.5';

		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( (int) $supplier['id'], WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
		$this->assertEqualsWithDelta( 8.5, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ), 0.0001 );
	}

	// ---------------------------------------------------------------
	// Variation: render + save, index-arrayed field convention.
	// ---------------------------------------------------------------

	private function make_variable_with_variations( int $count ): array {
		$variations = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$variations[] = array( 'name' => 'V' . $i );
		}
		$variable = $this->create_variable_product( array(), $variations );
		$variable = wc_get_product( $variable->get_id() );
		return $variable->get_children();
	}

	public function test_variation_fields_render_with_scope_note() {
		$children = $this->make_variable_with_variations( 1 );

		$output = $this->render_variation( (int) $children[0], 0 );

		$this->assertStringContainsString( 'name="_wc_io_preferred_supplier_id[0]"', $output );
		$this->assertStringContainsString( 'name="_wc_io_default_replenishment_qty[0]"', $output );
		$this->assertStringContainsString( 'Applies to this variation only.', $output );
	}

	/**
	 * §15: variation fields must use WooCommerce's own index-arrayed
	 * naming convention so a 3+ variation save writes each row to its own
	 * item, never collapsing to the last row's value.
	 */
	public function test_three_plus_variations_save_independently_via_indexed_fields() {
		$children  = $this->make_variable_with_variations( 3 );
		$suppliers = array( $this->create_supplier(), $this->create_supplier(), $this->create_supplier() );

		$_POST['_wc_io_preferred_supplier_id']     = array();
		$_POST['_wc_io_default_replenishment_qty'] = array();
		foreach ( $children as $i => $variation_id ) {
			$_POST['_wc_io_preferred_supplier_id'][ $i ]     = (string) $suppliers[ $i ]['id'];
			$_POST['_wc_io_default_replenishment_qty'][ $i ] = (string) ( $i + 1 );
		}

		foreach ( $children as $i => $variation_id ) {
			WC_Inventory_Overview_Product_Replenishment_Admin::save_variation_fields( $variation_id, $i );
		}

		foreach ( $children as $i => $variation_id ) {
			$this->assertSame(
				(int) $suppliers[ $i ]['id'],
				WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( (int) $variation_id ),
				"Variation row {$i} must carry its own supplier, not the last row's."
			);
			$this->assertEqualsWithDelta(
				(float) ( $i + 1 ),
				WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( (int) $variation_id ),
				0.0001,
				"Variation row {$i} must carry its own quantity, not the last row's."
			);
		}
	}

	// ---------------------------------------------------------------
	// WP_Error surfaces without persisting.
	// ---------------------------------------------------------------

	public function test_invalid_supplier_submission_does_not_persist() {
		$product = $this->create_simple_product();

		$_POST['_wc_io_preferred_supplier_id'] = '999999';

		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}

	public function test_invalid_quantity_submission_does_not_persist() {
		$product = $this->create_simple_product();

		$_POST['_wc_io_default_replenishment_qty'] = '-5';

		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( 0.0, WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $product->get_id() ) );
	}

	// ---------------------------------------------------------------
	// Silent-clobber regression guard (§8).
	// ---------------------------------------------------------------

	public function test_unavailable_supplier_option_injected_and_resubmission_preserves_it() {
		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier( array( 'name' => 'Soon Archived Co' ) );
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), (int) $supplier['id'] );
		WC_Inventory_Overview_Suppliers::archive( $supplier['id'] );

		$output = $this->render_simple( $product->get_id() );
		$this->assertStringContainsString( 'Soon Archived Co (unavailable)', $output );
		$this->assertMatchesRegularExpression(
			'/<option value="' . preg_quote( (string) $supplier['id'], '/' ) . '"[^>]*selected/',
			$output,
			'The stale stored supplier must still be the selected option.'
		);

		// Simulate the browser resubmitting the form with the field
		// untouched: the still-selected (now "(unavailable)") option's
		// value is submitted verbatim.
		$_POST['_wc_io_preferred_supplier_id'] = (string) $supplier['id'];
		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame(
			(int) $supplier['id'],
			WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ),
			'An unrelated-field save must never silently clobber a stale preferred supplier.'
		);
	}

	// ---------------------------------------------------------------
	// >20-supplier dropdown regression (Suppliers::list() per_page default is 20).
	// ---------------------------------------------------------------

	public function test_dropdown_not_truncated_beyond_twenty_suppliers() {
		$product = $this->create_simple_product();
		for ( $i = 0; $i < 25; $i++ ) {
			$this->create_supplier( array( 'name' => 'Supplier Number ' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) ) );
		}

		$output = $this->render_simple( $product->get_id() );

		$this->assertStringContainsString( 'Supplier Number 24', $output, 'The dropdown must not silently truncate to 20 suppliers.' );
	}

	// ---------------------------------------------------------------
	// Capability gating: render is silent, save is a no-op, when the
	// current user lacks edit_product.
	// ---------------------------------------------------------------

	public function test_render_and_save_are_silent_without_edit_product_capability() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$product  = $this->create_simple_product();
		$supplier = $this->create_supplier();

		$output = $this->render_simple( $product->get_id() );
		$this->assertStringNotContainsString( '_wc_io_preferred_supplier_id', $output );

		$_POST['_wc_io_preferred_supplier_id'] = (string) $supplier['id'];
		WC_Inventory_Overview_Product_Replenishment_Admin::save_simple_fields( $product->get_id() );

		$this->assertSame( 0, WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $product->get_id() ) );
	}
}
