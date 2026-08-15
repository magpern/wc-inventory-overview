<?php
/**
 * M26 WP-M26-5: downstream consumption + full-path bulk-apply performance evidence.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_M26_Downstream_And_Performance extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Replenishment_Defaults::reset_test_write_fail();
		parent::tearDown();
	}

	private function purge_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param int $n Number of variations.
	 * @return array{parent:WC_Product_Variable,ids:int[]}
	 */
	private function make_variable_with_n_variations( int $n ): array {
		$specs = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$specs[] = array(
				'name'      => 'V' . $i,
				'stock_qty' => 1,
			);
		}
		$parent = $this->create_variable_product( array(), $specs );
		wc_delete_product_transients( $parent->get_id() );
		$parent = wc_get_product( $parent->get_id() );
		$ids    = array_map( 'absint', $parent->get_children() );
		$this->assertCount( $n, $ids );
		foreach ( $ids as $vid ) {
			$v = wc_get_product( $vid );
			$v->set_manage_stock( true );
			$v->set_stock_quantity( 1 );
			$v->set_low_stock_amount( 5 );
			$v->save();
		}
		return array(
			'parent' => $parent,
			'ids'    => $ids,
		);
	}

	public function test_m22_prefill_sees_bulk_applied_defaults() {
		$fixture  = $this->make_variable_with_n_variations( 2 );
		$supplier = $this->create_supplier();
		$result   = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$fixture['parent']->get_id(),
			$fixture['ids'],
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
				'update_default_qty'        => true,
				'default_qty'               => '22',
			)
		);
		$this->assertIsArray( $result );

		$vid    = $fixture['ids'][0];
		$prefill = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $fixture['parent']->get_id(), $vid );
		$this->assertSame( 'prefilled', $prefill['status'] );
		$this->assertSame( (int) $supplier['id'], (int) $prefill['supplier_id'] );
		$this->assertSame( '22', $prefill['line']['qty_ordered'] );
	}

	public function test_m24_plan_sees_bulk_applied_defaults() {
		$fixture  = $this->make_variable_with_n_variations( 2 );
		$supplier = $this->create_supplier( array( 'default_currency' => 'SEK' ) );
		WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$fixture['parent']->get_id(),
			$fixture['ids'],
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
				'update_default_qty'        => true,
				'default_qty'               => '8',
			)
		);

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), $fixture['ids'] );
		$this->assertNotEmpty( $plan['groups'] );
		$found = false;
		foreach ( $plan['groups'] as $group ) {
			if ( (int) $group['supplier_id'] === (int) $supplier['id'] ) {
				foreach ( $group['lines'] as $line ) {
					$line_item = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];
					if ( in_array( $line_item, $fixture['ids'], true ) ) {
						$this->assertSame( 8.0, (float) $line['qty_suggested'] );
						$found = true;
					}
				}
			}
		}
		$this->assertTrue( $found, 'Scoped plan must include bulk-applied variation defaults.' );
	}

	public function test_m25_commit_uses_bulk_applied_qty_when_selected() {
		$fixture  = $this->make_variable_with_n_variations( 1 );
		$supplier = $this->create_supplier( array( 'default_currency' => 'EUR' ) );
		WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$fixture['parent']->get_id(),
			$fixture['ids'],
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
				'update_default_qty'        => true,
				'default_qty'               => '11',
			)
		);

		$vid  = $fixture['ids'][0];
		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), array( $vid ) );
		$this->assertNotEmpty( $plan['groups'] );
		$group = reset( $plan['groups'] );
		$this->assertSame( 11.0, (float) $group['lines'][0]['qty_suggested'] );

		$commit = WC_Inventory_Overview_Replenishment_Commit_Service::commit(
			array(
				array(
					'product_id'   => $fixture['parent']->get_id(),
					'variation_id' => $vid,
					'qty'          => 11.0,
				),
			)
		);
		$this->assertIsArray( $commit );
		$this->assertSame( 1, count( $commit['created'] ) );
		$po_id = (int) $commit['created'][0]['po_id'];
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$this->assertCount( 1, $lines );
		$this->assertSame( $vid, (int) $lines[0]['variation_id'] );
		$this->assertSame( 11.0, (float) $lines[0]['qty_ordered'] );
	}

	/**
	 * Full-path performance matrix: membership + caps + normalize + meta.
	 *
	 * @param int $n Variation count.
	 * @return array{
	 *   n:int,
	 *   wall_supplier:float,
	 *   queries_supplier:int,
	 *   meta_writes_supplier:int,
	 *   meta_deletes_supplier:int,
	 *   wall_qty:float,
	 *   queries_qty:int,
	 *   meta_writes_qty:int,
	 *   meta_deletes_qty:int
	 * }
	 */
	private function measure_full_path( int $n ): array {
		$fixture  = $this->make_variable_with_n_variations( $n );
		$supplier = $this->create_supplier();
		$parent   = $fixture['parent']->get_id();
		$ids      = $fixture['ids'];

		$queries      = 0;
		$meta_writes  = 0;
		$meta_deletes = 0;
		$counter      = static function ( $sql ) use ( &$queries ) {
			++$queries;
			return $sql;
		};
		$on_updated = static function () use ( &$meta_writes ) {
			++$meta_writes;
		};
		$on_deleted = static function () use ( &$meta_deletes ) {
			++$meta_deletes;
		};

		add_filter( 'query', $counter );
		add_action( 'updated_post_meta', $on_updated );
		add_action( 'added_post_meta', $on_updated );
		add_action( 'deleted_post_meta', $on_deleted );
		$t0     = microtime( true );
		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$parent,
			$ids,
			array(
				'update_preferred_supplier' => true,
				'preferred_supplier_id'     => (int) $supplier['id'],
			)
		);
		$wall_s = microtime( true ) - $t0;
		remove_filter( 'query', $counter );
		remove_action( 'updated_post_meta', $on_updated );
		remove_action( 'added_post_meta', $on_updated );
		remove_action( 'deleted_post_meta', $on_deleted );
		$this->assertIsArray( $result );
		$this->assertSame( $n, $result['variations_updated'] );
		$q_s = $queries;
		$w_s = $meta_writes;
		$d_s = $meta_deletes;

		$queries      = 0;
		$meta_writes  = 0;
		$meta_deletes = 0;
		add_filter( 'query', $counter );
		add_action( 'updated_post_meta', $on_updated );
		add_action( 'added_post_meta', $on_updated );
		add_action( 'deleted_post_meta', $on_deleted );
		$t0     = microtime( true );
		$result = WC_Inventory_Overview_Replenishment_Defaults::apply_to_variations(
			$parent,
			$ids,
			array(
				'update_default_qty' => true,
				'default_qty'        => '5',
			)
		);
		$wall_q = microtime( true ) - $t0;
		remove_filter( 'query', $counter );
		remove_action( 'updated_post_meta', $on_updated );
		remove_action( 'added_post_meta', $on_updated );
		remove_action( 'deleted_post_meta', $on_deleted );
		$this->assertIsArray( $result );
		$this->assertSame( $n, $result['variations_updated'] );

		return array(
			'n'                     => $n,
			'wall_supplier'         => $wall_s,
			'queries_supplier'      => $q_s,
			'meta_writes_supplier'  => $w_s,
			'meta_deletes_supplier' => $d_s,
			'wall_qty'              => $wall_q,
			'queries_qty'           => $queries,
			'meta_writes_qty'       => $meta_writes,
			'meta_deletes_qty'      => $meta_deletes,
		);
	}

	/**
	 * Full-path performance matrix at 1/10/50/100.
	 *
	 * @group performance
	 */
	public function test_full_path_performance_matrix_1_10_50_100() {
		$sizes  = array( 1, 10, 50, 100 );
		$report = array();
		foreach ( $sizes as $n ) {
			$report[ $n ] = $this->measure_full_path( $n );
			fwrite(
				STDERR,
				sprintf(
					"\n[M26 full-path perf] N=%d supplier: wall=%.4fs queries=%d meta_w=%d meta_d=%d | qty: wall=%.4fs queries=%d meta_w=%d meta_d=%d\n",
					$n,
					$report[ $n ]['wall_supplier'],
					$report[ $n ]['queries_supplier'],
					$report[ $n ]['meta_writes_supplier'],
					$report[ $n ]['meta_deletes_supplier'],
					$report[ $n ]['wall_qty'],
					$report[ $n ]['queries_qty'],
					$report[ $n ]['meta_writes_qty'],
					$report[ $n ]['meta_deletes_qty']
				)
			);
		}

		// Sanity: larger N must not explode super-linearly beyond a loose bound
		// (no invented hard ceiling — detect accidental N+1 blowups only).
		$this->assertLessThan(
			$report[1]['queries_supplier'] * 200,
			$report[100]['queries_supplier'],
			'Supplier-path query count at N=100 must not indicate an uncontrolled N+1 blowup.'
		);
		$this->assertLessThan(
			$report[1]['queries_qty'] * 200,
			$report[100]['queries_qty'],
			'Qty-path query count at N=100 must not indicate an uncontrolled N+1 blowup.'
		);
		$this->assertLessThan( 30.0, $report[100]['wall_supplier'] );
		$this->assertLessThan( 30.0, $report[100]['wall_qty'] );

		update_option(
			'wc_io_m26_perf_evidence',
			array(
				'measured_at' => gmdate( 'c' ),
				'matrix'      => $report,
			)
		);
	}
}
