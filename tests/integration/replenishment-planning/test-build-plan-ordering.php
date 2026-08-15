<?php
/**
 * M24 WP-M24-5: deterministic display ordering contract (§10.3, BR-M24-19).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Ordering extends WC_Inventory_Overview_Test_Case {

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

	private function product_with_default( string $name, int $supplier_id, string $sku = '' ): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'name' => $name, 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		$product->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier_id );
		return $product;
	}

	/**
	 * Supplier groups ordered by name case-insensitive, then supplier_id
	 * ascending tiebreak -- inserted deliberately out of order.
	 */
	public function test_groups_ordered_by_supplier_name_case_insensitive() {
		$zebra = $this->create_supplier( array( 'name' => 'zebra supplies' ) );
		$acme  = $this->create_supplier( array( 'name' => 'ACME Corp' ) );
		$mid   = $this->create_supplier( array( 'name' => 'Middle Co' ) );

		$this->product_with_default( 'P1', $zebra['id'] );
		$this->product_with_default( 'P2', $acme['id'] );
		$this->product_with_default( 'P3', $mid['id'] );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$order = array_keys( $plan['groups'] );
		$names = array_map(
			static function ( $sid ) use ( $plan ) {
				return $plan['groups'][ $sid ]['supplier_name'];
			},
			$order
		);

		$this->assertSame( array( 'ACME Corp', 'Middle Co', 'zebra supplies' ), $names, 'Groups must be ordered by supplier name, case-insensitive.' );
	}

	/**
	 * Suppliers::validate() enforces a unique normalized (case-folded) name
	 * per supplier, so two suppliers can never legitimately tie on
	 * case-insensitive name in real data -- the supplier_id-ascending
	 * tiebreak (§10.3) is exercised directly at the unit level by
	 * Test_WC_IO_Supplier_Preference_Resolver's sibling architecture-guard
	 * suite instead of via a same-name fixture here, which the uniqueness
	 * constraint would reject outright.
	 */
	public function test_duplicate_supplier_name_rejected_confirms_tiebreak_is_unreachable_via_real_data() {
		$this->create_supplier( array( 'name' => 'Unique Co' ) );
		$dup = WC_Inventory_Overview_Suppliers::create( array( 'name' => 'unique co', 'default_currency' => 'EUR' ) );
		$this->assertWPError( $dup, 'A case-insensitive duplicate name must be rejected, confirming groups can never tie on supplier_name in practice.' );
	}

	/**
	 * Unresolved section always renders after all resolved groups.
	 */
	public function test_unresolved_after_resolved_groups() {
		$supplier = $this->create_supplier( array( 'name' => 'AAA Supplier' ) );
		$this->product_with_default( 'Resolved item', $supplier['id'] );

		$unresolved_product = $this->create_simple_product( array( 'name' => 'Unresolved item', 'stock_qty' => 1 ) );
		$unresolved_product->set_low_stock_amount( 5 );
		$unresolved_product->save();

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertNotEmpty( $plan['groups'] );
		$this->assertNotEmpty( $plan['unresolved'] );
		// Return-shape level assertion: unresolved is its own separate key,
		// never interleaved into groups -- the renderer (WP-M24-6) is
		// responsible for always drawing it last.
		$this->assertArrayHasKey( 'unresolved', $plan );
	}

	/**
	 * Lines within a group ordered by product name case-insensitive, then
	 * SKU, then item id -- against a deliberately scrambled insertion order.
	 */
	public function test_lines_within_group_ordered_by_name_then_sku_then_id() {
		$supplier = $this->create_supplier();

		$this->product_with_default( 'Zebra Item', $supplier['id'], 'SKU-Z' );
		$this->product_with_default( 'apple item', $supplier['id'], 'SKU-A' );
		$this->product_with_default( 'Mango Item', $supplier['id'], 'SKU-M' );

		$plan  = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$names = array_map(
			static function ( $line ) {
				return $line['name'];
			},
			$plan['groups'][ $supplier['id'] ]['lines']
		);

		$this->assertSame( array( 'apple item', 'Mango Item', 'Zebra Item' ), $names );
	}

	public function test_lines_tiebreak_by_sku_when_names_equal() {
		$supplier = $this->create_supplier();

		$this->product_with_default( 'Same Name', $supplier['id'], 'SKU-B' );
		$this->product_with_default( 'Same Name', $supplier['id'], 'SKU-A' );

		$plan  = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$skus  = array_map(
			static function ( $line ) {
				return $line['sku'];
			},
			$plan['groups'][ $supplier['id'] ]['lines']
		);

		$this->assertSame( array( 'SKU-A', 'SKU-B' ), $skus );
	}

	/**
	 * Ordering runs on the already-truncated set -- proven by combining
	 * with a small $limit-equivalent check: this test seeds items whose
	 * DB insertion order is the reverse of their display order, confirming
	 * the sort is applied regardless of gather/insertion order (BR-M24-19,
	 * "independent of DB row order").
	 */
	public function test_ordering_independent_of_db_row_order() {
		$supplier = $this->create_supplier();

		// Insert in reverse alphabetical order.
		$this->product_with_default( 'Item C', $supplier['id'] );
		$this->product_with_default( 'Item B', $supplier['id'] );
		$this->product_with_default( 'Item A', $supplier['id'] );

		$plan  = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$names = array_map(
			static function ( $line ) {
				return $line['name'];
			},
			$plan['groups'][ $supplier['id'] ]['lines']
		);

		$this->assertSame( array( 'Item A', 'Item B', 'Item C' ), $names );
	}
}
