<?php
/**
 * M24 WP-M24-5: WC_Inventory_Overview_Replenishment_Planning_Service::build_plan()
 * eligibility contract (§11).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Eligibility extends WC_Inventory_Overview_Test_Case {

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

	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	private function all_lines( array $plan ): array {
		$lines = array();
		foreach ( $plan['groups'] as $group ) {
			foreach ( $group['lines'] as $line ) {
				$lines[] = $line;
			}
		}
		return $lines;
	}

	private function all_item_ids( array $plan ): array {
		$ids = array();
		foreach ( $this->all_lines( $plan ) as $line ) {
			$ids[] = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
		}
		foreach ( $plan['unresolved'] as $u ) {
			$ids[] = $u['variation_id'] > 0 ? $u['variation_id'] : $u['product_id'];
		}
		return $ids;
	}

	public function test_needs_reorder_simple_product_appears() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertContains( $product->get_id(), $this->all_item_ids( $plan ) );
	}

	public function test_variable_parent_never_a_candidate() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertNotContains( $variable->get_id(), $this->all_item_ids( $plan ), 'A variable parent must never independently appear as a plan candidate (BR-M24-16).' );
	}

	public function test_non_stock_managed_product_excluded() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1, 'manage_stock' => false ) );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertNotContains( $product->get_id(), $this->all_item_ids( $plan ) );
	}

	public function test_covered_by_incoming_excluded() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertNotContains( $product->get_id(), $this->all_item_ids( $plan ), 'A covered_by_incoming item must never appear, not even as unresolved (§11).' );
	}

	public function test_not_low_stock_product_excluded() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertNotContains( $product->get_id(), $this->all_item_ids( $plan ) );
	}

	public function test_variation_eligible_independently_of_parent() {
		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'Needs', 'stock_qty' => 1 ),
				array( 'name' => 'Fine', 'stock_qty' => 100 ),
			)
		);
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		foreach ( $children as $vid ) {
			$v = wc_get_product( $vid );
			$v->set_low_stock_amount( 5 );
			$v->save();
		}

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$ids  = $this->all_item_ids( $plan );

		$needs_vid = null;
		$fine_vid  = null;
		foreach ( $children as $vid ) {
			$v = wc_get_product( $vid );
			if ( (float) $v->get_stock_quantity() <= 5 ) {
				$needs_vid = $vid;
			} else {
				$fine_vid = $vid;
			}
		}

		$this->assertContains( $needs_vid, $ids );
		$this->assertNotContains( $fine_vid, $ids );
	}

	public function test_zero_needs_reorder_items_returns_empty_plan_shape() {
		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( 999999999 ) );

		$this->assertSame( array(), $plan['groups'] );
		$this->assertSame( array(), $plan['unresolved'] );
		$this->assertFalse( $plan['truncated'] );
		$this->assertSame( 0, $plan['candidate_count'] );
	}

	// -----------------------------------------------------------------
	// INV-M24-1/§18: zero mutation.
	// -----------------------------------------------------------------

	public function test_build_plan_performs_zero_mutation() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$before_stock = $product->get_stock_quantity();
		$before_status = $product->get_status();

		global $wpdb;
		$po_count_before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $product->get_id() ) );

		$after = wc_get_product( $product->get_id() );
		$this->assertEqualsWithDelta( (float) $before_stock, (float) $after->get_stock_quantity(), 0.0001 );
		$this->assertSame( $before_status, $after->get_status() );

		$po_count_after = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( $po_count_before, $po_count_after, 'build_plan() must never create a Purchase Order (INV-M24-1).' );
	}
}
