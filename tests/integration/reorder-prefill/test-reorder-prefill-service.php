<?php
/**
 * Integration tests for M22 WP-M22-2:
 * WC_Inventory_Overview_Reorder_Prefill_Service::resolve().
 *
 * Covers all five §8 status branches (malformed/invalid/stale/prefilled
 * with 0/1/many suppliers), identity re-validation reuse (BR-M22-5),
 * TOCTOU re-derivation (BR-M22-6/7), the quantity/cost/sku omission
 * contract (BR-M22-9), and the fixed (never N+1) query-count contract for
 * the 'prefilled' path at 0/1/10/50 historical suppliers (INV-M22-16).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Prefill_Service extends WC_Inventory_Overview_Test_Case {

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

	/**
	 * Seed one committed-history PO+line for a product. Status defaults to
	 * 'placed' -- callers that need many historical suppliers without also
	 * inflating the item's Inventory Position (Position's "incoming" is
	 * summed only from 'placed'/'partially_received'/'received' lines, per
	 * M3) should pass 'closed_short' instead, which counts as committed
	 * purchase history (BR-M22-8) but never as incoming supply.
	 *
	 * @return array{po_id:int, supplier_id:int}
	 */
	private function seed_committed_history( array $line_props, string $order_date = '2026-01-01', string $status = 'placed' ): array {
		$po = $this->create_purchase_order( array( 'order_date' => $order_date ) );
		$this->add_po_line( $po['id'], $line_props );
		$result = WC_Inventory_Overview_Purchase_Orders::update_fields(
			$po['id'],
			array(
				'status'     => $status,
				'order_date' => $order_date,
			)
		);
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to force PO status: ' . $result->get_error_message() );
		}
		return array(
			'po_id'       => $po['id'],
			'supplier_id' => (int) $po['supplier_id'],
		);
	}

	// ---------------------------------------------------------------
	// 'malformed'
	// ---------------------------------------------------------------

	public function test_malformed_zero_product_id() {
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 0 );

		$this->assertSame( 'malformed', $result['status'] );
		$this->assertNull( $result['line'] );
		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertNotEmpty( $result['notices'] );
	}

	public function test_malformed_negative_product_id() {
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( -5 );
		$this->assertSame( 'malformed', $result['status'] );
	}

	// ---------------------------------------------------------------
	// 'invalid'
	// ---------------------------------------------------------------

	public function test_invalid_deleted_product_id() {
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 999999 );

		$this->assertSame( 'invalid', $result['status'] );
		$this->assertNull( $result['line'] );
		$this->assertSame( 0, $result['supplier_id'] );
	}

	public function test_invalid_variable_parent_product() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id() );

		$this->assertSame( 'invalid', $result['status'] );
	}

	public function test_invalid_mismatched_parent_variation_pair() {
		$variable_a = $this->create_variable_product( array(), array( array( 'name' => 'A1' ) ) );
		$variable_b = $this->create_variable_product( array(), array( array( 'name' => 'B1' ) ) );
		$variable_b = wc_get_product( $variable_b->get_id() );
		$children_b = $variable_b->get_children();
		$this->assertNotEmpty( $children_b );

		// Variation belongs to $variable_b, but product_id claims $variable_a's parent.
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable_a->get_id(), (int) $children_b[0] );

		$this->assertSame( 'invalid', $result['status'] );
	}

	public function test_invalid_non_stock_managed_product() {
		$product = $this->create_simple_product();
		$product->set_manage_stock( false );
		$product->save();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'invalid', $result['status'] );
	}

	// ---------------------------------------------------------------
	// 'stale'
	// ---------------------------------------------------------------

	public function test_stale_when_no_longer_needs_reorder() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'stale', $result['status'] );
		$this->assertNull( $result['line'], "'stale' must discard the reorder-specific prefill entirely (BR-M22-7)." );
		$this->assertSame( 0, $result['supplier_id'] );
	}

	public function test_stale_issues_no_supplier_query() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$suppliers_table = WC_Inventory_Overview_Suppliers::table_name();
		$hits            = array();
		$counter         = static function ( $query ) use ( $suppliers_table, &$hits ) {
			if ( false !== strpos( $query, $suppliers_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );
		remove_filter( 'query', $counter );

		$this->assertSame( 'stale', $result['status'] );
		$this->assertSame( array(), $hits, 'A stale outcome must never issue a supplier query (§13).' );
	}

	// ---------------------------------------------------------------
	// 'prefilled' — 0 / 1 / many suppliers
	// ---------------------------------------------------------------

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	public function test_prefilled_zero_eligible_suppliers() {
		$product = $this->make_needs_reorder_product();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertNotNull( $result['line'] );
		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertNotEmpty( $result['notices'] );
	}

	public function test_prefilled_one_eligible_supplier_preselected() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( $seed['supplier_id'], $result['supplier_id'] );
	}

	public function test_prefilled_multiple_eligible_suppliers_unselected() {
		$product = $this->make_needs_reorder_product();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01' );
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-02-01' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( 0, $result['supplier_id'], 'Multiple eligible suppliers must never be auto-picked.' );
		$this->assertNotEmpty( $result['notices'] );
	}

	public function test_prefilled_archived_supplier_excluded() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Suppliers::archive( $seed['supplier_id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 0, $result['supplier_id'], 'An archived-but-unmerged supplier must never be preselected.' );
	}

	public function test_prefilled_merged_supplier_excluded() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		$target  = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::mark_merged( $seed['supplier_id'], $target['id'] );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 0, $result['supplier_id'], 'A merged/dissolved supplier must never be preselected.' );
	}

	public function test_prefilled_variation_identity() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 0 ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );
		$variation = wc_get_product( $children[0] );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id(), $variation->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( $variable->get_id(), $result['line']['product_id'] );
		$this->assertSame( $variation->get_id(), $result['line']['variation_id'] );
	}

	public function test_prefilled_line_never_contains_qty_cost_sku_keys() {
		$product = $this->make_needs_reorder_product();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertArrayNotHasKey( 'qty_ordered', $result['line'] );
		$this->assertArrayNotHasKey( 'unit_cost', $result['line'] );
		$this->assertArrayNotHasKey( 'supplier_sku', $result['line'] );
		$this->assertArrayHasKey( 'name_snapshot', $result['line'] );
		$this->assertArrayHasKey( 'sku_snapshot', $result['line'] );
	}

	// ---------------------------------------------------------------
	// INV-M22-16: fixed query count, never N+1
	// ---------------------------------------------------------------

	private function count_relevant_queries( int $product_id ): int {
		$lines_table     = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$suppliers_table = WC_Inventory_Overview_Suppliers::table_name();
		$hits            = array();
		$counter         = static function ( $query ) use ( $lines_table, $suppliers_table, &$hits ) {
			if ( false === stripos( $query, 'SELECT' ) ) {
				return $query;
			}
			if ( false !== strpos( $query, $lines_table ) || false !== strpos( $query, $suppliers_table ) ) {
				$hits[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product_id );
		remove_filter( 'query', $counter );

		$this->assertSame( 'prefilled', $result['status'], 'Query-count fixture must stay in the prefilled branch throughout (closed_short lines never inflate incoming).' );

		return count( $hits );
	}

	/**
	 * Uses 'closed_short' status: counts as committed supplier history
	 * (BR-M22-8) without inflating the item's Inventory Position (M3 never
	 * treats a closed_short line as incoming), so the item stays
	 * needs_reorder=true throughout regardless of how many historical
	 * suppliers are seeded -- isolating the query-count measurement to
	 * exactly the variable under test (INV-M22-16).
	 */
	public function test_prefilled_query_count_fixed_at_1_10_50_suppliers() {
		$product = $this->make_needs_reorder_product();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		$n_at_1 = $this->count_relevant_queries( $product->get_id() );

		for ( $i = 0; $i < 9; $i++ ) {
			$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		}
		$n_at_10 = $this->count_relevant_queries( $product->get_id() );

		for ( $i = 0; $i < 40; $i++ ) {
			$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		}
		$n_at_50 = $this->count_relevant_queries( $product->get_id() );

		$this->assertSame( $n_at_1, $n_at_10, 'Query count must not grow between 1 and 10 historical suppliers (INV-M22-16).' );
		$this->assertSame( $n_at_1, $n_at_50, 'Query count must not grow between 1 and 50 historical suppliers (INV-M22-16).' );
	}
}
