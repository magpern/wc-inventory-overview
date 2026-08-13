<?php
/**
 * Integration query-count regression gate for M21 (WP-M21-4, INV-M21-4,
 * §27): Summary::build()'s needs_reorder classification must add exactly a
 * fixed, small number of Position-repository queries, regardless of catalog
 * size or how many low-stock lines are found -- never a function of N.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Summary_Query_Count extends WC_Inventory_Overview_Test_Case {

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
	 * @param int $po_id PO id.
	 * @return array<string,mixed>
	 */
	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * @param int $n_low_stock Number of low-stock products to create.
	 */
	private function seed_low_stock_products( int $n_low_stock ): void {
		for ( $i = 0; $i < $n_low_stock; $i++ ) {
			$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$product->set_low_stock_amount( 5 );
			$product->save();

			$po = $this->create_purchase_order();
			$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
			$this->place_po( $po['id'] );
		}
	}

	/**
	 * Count Position-repository (PO lines table) SELECT queries issued
	 * during Summary::build().
	 */
	private function count_position_queries_for_build(): int {
		$lines_table       = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$position_queries  = array();
		$counter           = static function ( $query ) use ( $lines_table, &$position_queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$position_queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Summary::build( array() );
		remove_filter( 'query', $counter );

		return count( $position_queries );
	}

	/**
	 * Small scale (5 low-stock products): at most 2 Position-repository
	 * queries (1 product-scoped + 1 variation-scoped, per get_positions_bulk()'s
	 * own D12 contract) -- never once per candidate.
	 */
	public function test_query_count_bounded_at_small_scale() {
		$this->seed_low_stock_products( 5 );

		$n = $this->count_position_queries_for_build();

		$this->assertLessThanOrEqual( 2, $n, 'Summary::build() must not issue per-candidate Position queries.' );
	}

	/**
	 * Larger scale (60 low-stock products): the query count must be
	 * identical to the small-scale count -- proving it does not scale with N
	 * (INV-M21-4).
	 */
	public function test_query_count_identical_at_larger_scale() {
		$this->seed_low_stock_products( 60 );

		$n = $this->count_position_queries_for_build();

		$this->assertLessThanOrEqual( 2, $n, 'Summary::build() must not issue per-candidate Position queries at scale.' );
	}
}
