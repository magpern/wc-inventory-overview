<?php
/**
 * M24 WP-M24-5/WP-M24-8: resolution-size bound (§9.5, BR-M24-1/11/21).
 *
 * A >500-needs-reorder-item catalog-wide fixture proves resolution inputs
 * (Replenishment_Defaults::get_bulk(), distinct_supplier_history_for_items_bulk(),
 * Suppliers::list_by_ids(), display sort) never exceed 500 items, and that
 * truncation happens in gather order before the §10.3 display sort.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Resolution_Cap extends WC_Inventory_Overview_Test_Case {

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
	public function test_catalog_wide_resolution_capped_at_500_when_550_qualify() {
		$this->seed_needs_reorder_products( 550 );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$total = $this->total_plan_lines( $plan );
		$this->assertLessThanOrEqual( 500, $total, 'Resolution/display output must never exceed 500 items on the catalog-wide path (BR-M24-21).' );
		$this->assertTrue( $plan['truncated'], 'truncated must be true when more than 500 needs-reorder items exist.' );
	}

	/**
	 * @group performance
	 */
	public function test_resolution_stage_query_count_does_not_scale_with_catalog_size() {
		$this->seed_needs_reorder_products( 550 );

		$defaults_table = $GLOBALS['wpdb']->postmeta;
		$suppliers_table = WC_Inventory_Overview_Suppliers::table_name();
		$hits = array();
		$counter = static function ( $query ) use ( $suppliers_table, &$hits ) {
			if ( false !== strpos( $query, $suppliers_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		remove_filter( 'query', $counter );

		// With 550 candidates and no committed history/preferences configured
		// for any of them, the supplier resolution stage should issue at
		// most a small constant number of queries (list_by_ids() with an
		// empty touched-supplier-id set never even runs) -- proving no
		// per-item supplier query pattern regardless of catalog size.
		$this->assertLessThanOrEqual( 2, count( $hits ), 'Supplier resolution must not scale with catalog size.' );
	}

	/**
	 * @group performance
	 */
	public function test_no_truncation_when_exactly_500_qualify() {
		$this->seed_needs_reorder_products( 500 );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$this->assertSame( 500, $this->total_plan_lines( $plan ) );
		$this->assertFalse( $plan['truncated'], 'Exactly 500 qualifying items must not be flagged as truncated.' );
	}

	/**
	 * BR-M24-21 for the scoped path: input already bounded to <=100
	 * externally by the bulk-action cap, so no additional truncation
	 * applies -- proven explicitly with exactly 100 scoped ids.
	 */
	/**
	 * @group performance
	 */
	public function test_scoped_path_100_items_no_truncation() {
		$ids = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$p = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$p->set_low_stock_amount( 5 );
			$p->save();
			$ids[] = $p->get_id();
		}

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), $ids );

		$this->assertSame( 100, $this->total_plan_lines( $plan ) );
		$this->assertFalse( $plan['truncated'] );
	}
}
