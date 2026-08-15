<?php
/**
 * M24 WP-M24-5: item_ids-scoped discovery contract (§9.4/BR-M24-2/3).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Item_Ids_Scope extends WC_Inventory_Overview_Test_Case {

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

	private function all_item_ids( array $plan ): array {
		$ids = array();
		foreach ( $plan['groups'] as $group ) {
			foreach ( $group['lines'] as $line ) {
				$ids[] = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
			}
		}
		foreach ( $plan['unresolved'] as $u ) {
			$ids[] = $u['variation_id'] > 0 ? $u['variation_id'] : $u['product_id'];
		}
		return $ids;
	}

	public function test_scoped_plan_returns_only_requested_ids() {
		$in_scope = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$in_scope->set_low_stock_amount( 5 );
		$in_scope->save();

		$out_of_scope = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$out_of_scope->set_low_stock_amount( 5 );
		$out_of_scope->save();

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $in_scope->get_id() ) );

		$ids = $this->all_item_ids( $plan );
		$this->assertContains( $in_scope->get_id(), $ids );
		$this->assertNotContains( $out_of_scope->get_id(), $ids );
	}

	/**
	 * BR-M24-3: a scoped id that no longer qualifies (became stale between
	 * selection and render -- TOCTOU) is silently dropped, not surfaced as
	 * an error and never left in a broken partial-plan state.
	 */
	public function test_scoped_id_that_became_stale_silently_dropped() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		// Restock above threshold -- no longer needs reorder.
		$product->set_stock_quantity( 100 );
		$product->save();

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $product->get_id() ) );

		$this->assertSame( array(), $this->all_item_ids( $plan ) );
		$this->assertSame( array(), $plan['groups'] );
		$this->assertSame( array(), $plan['unresolved'] );
	}

	public function test_scoped_id_that_no_longer_exists_silently_dropped() {
		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( 999999999 ) );
		$this->assertSame( array(), $this->all_item_ids( $plan ) );
	}

	public function test_scoped_variable_parent_never_appears() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $variable->get_id() ) );

		$this->assertSame( array(), $this->all_item_ids( $plan ), 'A variable parent id in item_ids must never produce a plan candidate.' );
	}
}
