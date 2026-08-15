<?php
/**
 * M24 WP-M24-5: truncation contract at 500/501-item fixtures (BR-M24-11),
 * gather-order truncation (§9.5), pre-display-sort.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Truncation extends WC_Inventory_Overview_Test_Case {

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

	private function total_plan_lines( array $plan ): int {
		$count = 0;
		foreach ( $plan['groups'] as $group ) {
			$count += count( $group['lines'] );
		}
		return $count + count( $plan['unresolved'] );
	}

	private function seed_needs_reorder_products( int $n ): void {
		for ( $i = 0; $i < $n; $i++ ) {
			$p = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$p->set_low_stock_amount( 5 );
			$p->save();
		}
	}

	/**
	 * @group performance
	 */
	public function test_exactly_501_qualifying_items_truncates_to_500() {
		$this->seed_needs_reorder_products( 501 );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertSame( 500, $this->total_plan_lines( $plan ) );
		$this->assertTrue( $plan['truncated'] );
	}

	/**
	 * @group performance
	 */
	public function test_exactly_500_qualifying_items_not_truncated() {
		$this->seed_needs_reorder_products( 500 );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertSame( 500, $this->total_plan_lines( $plan ) );
		$this->assertFalse( $plan['truncated'] );
	}

	public function test_under_limit_not_truncated() {
		$this->seed_needs_reorder_products( 3 );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertSame( 3, $this->total_plan_lines( $plan ) );
		$this->assertFalse( $plan['truncated'] );
	}
}
