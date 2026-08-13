<?php
/**
 * Integration tests for M22 WP-M22-1:
 * WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item().
 *
 * Verifies the "committed purchase history" status set (BR-M22-8), the
 * simple-product vs. variation identity convention, most-recent-first
 * ordering, and — critically — that the query count for this method stays
 * fixed at exactly 1 regardless of how many distinct historical suppliers
 * exist for the item (INV-M22-16).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Purchase_Order_Lines_Supplier_History extends WC_Inventory_Overview_Test_Case {

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
	 * Create a PO+line for one item, force the PO to a specific status and
	 * order_date directly at the repository level (WC_Inventory_Overview_Purchase_Orders::update_fields()
	 * carries "no lifecycle policy" by its own docblock — appropriate here
	 * since this test exercises the read-side query, not lifecycle rules).
	 *
	 * @param array<string,mixed> $line_props Passed through to add_po_line().
	 * @param string              $status     PO status to force.
	 * @param string              $order_date Y-m-d.
	 * @return array{po_id:int, supplier_id:int}
	 */
	private function seed_po_with_status( array $line_props, string $status, string $order_date ): array {
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

	private function count_history_queries( int $product_id, int $variation_id = 0 ): int {
		global $wpdb;
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$queries     = array();
		$counter     = static function ( $query ) use ( $lines_table, &$queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product_id, $variation_id );
		remove_filter( 'query', $counter );

		return count( $queries );
	}

	public function test_zero_history_returns_empty_array() {
		$product = $this->create_simple_product();
		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() ) );
	}

	public function test_one_committed_supplier_is_returned() {
		$product = $this->create_simple_product();
		$seed    = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-01-01' );

		$result = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() );

		$this->assertSame( array( $seed['supplier_id'] ), $result );
	}

	public function test_multiple_suppliers_ordered_most_recent_first() {
		$product = $this->create_simple_product();
		$older   = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'received', '2025-06-01' );
		$newer   = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-02-01' );

		$result = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() );

		$this->assertSame( array( $newer['supplier_id'], $older['supplier_id'] ), $result );
	}

	public function test_cancelled_po_excluded() {
		$product = $this->create_simple_product();
		$this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'cancelled', '2026-01-01' );

		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() ) );
	}

	public function test_draft_only_po_excluded() {
		// create_purchase_order() + add_po_line() alone leaves the PO in 'draft' -- never forced to another status.
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );

		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() ), 'A draft-only PO must never surface a supplier (BR-M22-8).' );
	}

	public function test_all_four_committed_statuses_count() {
		$product = $this->create_simple_product();
		$s1      = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-01-01' );
		$s2      = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'partially_received', '2026-01-02' );
		$s3      = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'received', '2026-01-03' );
		$s4      = $this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'closed_short', '2026-01-04' );

		$result = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id() );

		$this->assertCount( 4, $result );
		foreach ( array( $s1, $s2, $s3, $s4 ) as $s ) {
			$this->assertContains( $s['supplier_id'], $result );
		}
	}

	public function test_simple_product_convention_variation_id_zero() {
		$product = $this->create_simple_product();
		$this->seed_po_with_status(
			array(
				'product_id'   => $product->get_id(),
				'variation_id' => 0,
			),
			'placed',
			'2026-01-01'
		);

		$result = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product->get_id(), 0 );

		$this->assertCount( 1, $result );
	}

	public function test_variation_convention_uses_parent_and_variation_id() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		// Re-fetch: the in-memory $variable object predates its variations being saved.
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$this->assertNotEmpty( $children );
		$variation_id = (int) $children[0];

		$this->seed_po_with_status(
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => $variation_id,
			),
			'placed',
			'2026-01-01'
		);

		$result = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $variable->get_id(), $variation_id );

		$this->assertCount( 1, $result );

		// The parent's own simple-product-shaped query (variation_id=0) must not pick this up.
		$parent_only = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $variable->get_id(), 0 );
		$this->assertSame( array(), $parent_only );
	}

	public function test_unrelated_product_never_included() {
		$product_a = $this->create_simple_product();
		$product_b = $this->create_simple_product();
		$this->seed_po_with_status( array( 'product_id' => $product_a->get_id() ), 'placed', '2026-01-01' );

		$this->assertSame( array(), WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_item( $product_b->get_id() ) );
	}

	/**
	 * INV-M22-16 / BR-M22-16: query count for this method alone stays fixed
	 * at exactly 1, regardless of how many distinct historical suppliers
	 * exist for the item.
	 */
	public function test_query_count_fixed_at_0_1_10_50_suppliers() {
		$product = $this->create_simple_product();

		// 0 suppliers.
		$this->assertSame( 1, $this->count_history_queries( $product->get_id() ) );

		// 1 supplier.
		$this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-01-01' );
		$this->assertSame( 1, $this->count_history_queries( $product->get_id() ) );

		// 10 suppliers.
		for ( $i = 0; $i < 9; $i++ ) {
			$this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-01-01' );
		}
		$this->assertSame( 1, $this->count_history_queries( $product->get_id() ) );

		// 50 suppliers.
		for ( $i = 0; $i < 40; $i++ ) {
			$this->seed_po_with_status( array( 'product_id' => $product->get_id() ), 'placed', '2026-01-01' );
		}
		$this->assertSame( 1, $this->count_history_queries( $product->get_id() ) );
	}
}
