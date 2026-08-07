<?php
/**
 * Query-scaling tests for Milestone M7 — Invariant M7-3.
 *
 * A rendered catalog/product page performs at most two
 * wc_io_purchase_order_lines SELECTs for expected-delivery purposes,
 * regardless of how many products it renders. Preload warms; memoization
 * holds; a miss degrades to one bounded lookup, never N lookups for one item.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Performance extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema/sequence, clear PO + supplier rows, and flush the
	 * Service's request-scoped memo for isolation between test methods
	 * (PHPUnit runs test methods in one PHP process, so the static memo
	 * would otherwise leak across tests).
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
	}

	public function tearDown(): void {
		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		parent::tearDown();
	}

	/**
	 * Truncate PO aggregate tables and suppliers so numbering/sequence resets stay unique.
	 */
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
	 * Works around a pre-existing WooCommerce transient-caching quirk
	 * (unrelated to M7, documented in the M3 integration suite) where
	 * create_variable_product() saves the parent before its variations
	 * exist, priming get_children() to return an empty list.
	 *
	 * @param WC_Product_Variable $parent Variable product.
	 * @return int[]
	 */
	private function variation_ids( WC_Product_Variable $parent ): array {
		wc_delete_product_transients( $parent->get_id() );
		$fresh = wc_get_product( $parent->get_id() );
		return $fresh instanceof WC_Product_Variable ? $fresh->get_children() : array();
	}

	/**
	 * Builds a mixed catalog: $simple_count simple products (each out of
	 * stock with one open, customer-safe PO line) and $parent_count variable
	 * parents (each out of stock, with 3 variations, one of which has an
	 * open PO line).
	 *
	 * @param int $simple_count Number of simple products.
	 * @param int $parent_count Number of variable parents (3 variations each).
	 * @return WC_Product[] Mixed list of simple products and variable parents.
	 */
	private function build_catalog( int $simple_count, int $parent_count ): array {
		$items = array();

		for ( $i = 0; $i < $simple_count; $i++ ) {
			$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
			$po      = $this->create_purchase_order(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
					'expected_confidence' => 'exact',
				)
			);
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
				)
			);
			$this->place_po( $po['id'] );
			$items[] = $product;
		}

		for ( $i = 0; $i < $parent_count; $i++ ) {
			$parent = $this->create_variable_product(
				array(),
				array(
					array( 'name' => 'A' ),
					array( 'name' => 'B' ),
					array( 'name' => 'C' ),
				)
			);
			$children = $this->variation_ids( $parent );

			$po = $this->create_purchase_order(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
					'expected_confidence' => 'exact',
				)
			);
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => (int) $children[0],
					'qty_ordered'  => 1,
				)
			);
			$this->place_po( $po['id'] );

			$items[] = $parent;
		}

		return $items;
	}

	/**
	 * @param callable $fn Callable to run while counting SELECTs against the PO lines table.
	 * @return int Number of matching queries issued.
	 */
	private function count_lines_table_queries( callable $fn ): int {
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$queries     = array();
		$counter     = static function ( $query ) use ( $lines_table, &$queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		$fn();
		remove_filter( 'query', $counter );

		return count( $queries );
	}

	/**
	 * One bulk call over a 20-item mixed catalog (>= 5 variable parents with
	 * 3 variations each, per the plan) issues at most 2 SELECTs.
	 */
	public function test_bulk_call_over_twenty_mixed_products_issues_at_most_two_queries() {
		$items = $this->build_catalog( 15, 5 );
		$this->assertCount( 20, $items );

		$query_count = $this->count_lines_table_queries(
			static function () use ( $items ) {
				WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $items );
			}
		);

		$this->assertLessThanOrEqual( 2, $query_count );
	}

	/**
	 * Invariant M7-3, the equality-based acceptance criterion: 40 mixed
	 * products issue the *same* number of SELECTs as 20 -- proof of bounded
	 * scaling, not merely "small."
	 */
	public function test_forty_products_issue_the_same_query_count_as_twenty() {
		$items_20 = $this->build_catalog( 15, 5 );
		$this->assertCount( 20, $items_20 );

		$count_20 = $this->count_lines_table_queries(
			static function () use ( $items_20 ) {
				WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $items_20 );
			}
		);

		WC_Inventory_Overview_Expected_Delivery_Service::flush_memo();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$items_40 = $this->build_catalog( 30, 10 );
		$this->assertCount( 40, $items_40 );

		$count_40 = $this->count_lines_table_queries(
			static function () use ( $items_40 ) {
				WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $items_40 );
			}
		);

		$this->assertSame( $count_20, $count_40, '20 and 40 mixed products must issue the same query count (bounded, not merely small)' );
	}

	/**
	 * After a bulk preload, every subsequent per-item lookup (as the
	 * Renderer issues one per rendered card) costs zero additional queries.
	 */
	public function test_preloaded_items_cost_zero_additional_queries() {
		$items = $this->build_catalog( 8, 2 );
		WC_Inventory_Overview_Expected_Delivery_Service::get_for_products_bulk( $items );

		$query_count = $this->count_lines_table_queries(
			function () use ( $items ) {
				foreach ( $items as $item ) {
					WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $item );
				}
			}
		);

		$this->assertSame( 0, $query_count, 'Every item already warmed by the preload must cost zero further queries' );
	}

	/**
	 * A cache miss (item absent from the warm set) costs exactly one
	 * additional SELECT, then is memoized -- a second call for the same
	 * item costs zero.
	 */
	public function test_cache_miss_costs_one_query_then_is_memoized() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$po      = $this->create_purchase_order(
			array(
				'expected_date'       => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
				'expected_confidence' => 'exact',
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 1,
			)
		);
		$this->place_po( $po['id'] );

		$first_call_count = $this->count_lines_table_queries(
			static function () use ( $product ) {
				WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );
			}
		);
		$this->assertSame( 1, $first_call_count, 'A cache miss must cost exactly one query' );

		$second_call_count = $this->count_lines_table_queries(
			static function () use ( $product ) {
				WC_Inventory_Overview_Expected_Delivery_Service::get_for_product( $product );
			}
		);
		$this->assertSame( 0, $second_call_count, 'A memoized item must cost zero further queries' );
	}
}
