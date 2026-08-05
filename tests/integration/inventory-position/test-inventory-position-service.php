<?php
/**
 * Integration tests for WC_Inventory_Overview_Inventory_Position_Service (M3, D12).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Inventory_Position_Service extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema/sequence and clear PO + supplier rows for isolation.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
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
	 * Variation IDs for a just-created variable product. Works around a
	 * pre-existing WooCommerce transient-caching quirk (unrelated to M3)
	 * where create_variable_product() saves the parent before its
	 * variations exist, priming get_children() to return an empty list.
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
	 * get_position() and get_positions_bulk() agree for the same item (D12: one calculation path).
	 */
	public function test_single_and_bulk_consistency() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->place_po( $po['id'] );

		$single = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			10.0
		);
		$bulk   = WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk(
			array( $product->get_id() => 10.0 ),
			array()
		);

		$this->assertSame( $single, $bulk[ $product->get_id() ] );
		$this->assertEqualsWithDelta( 4.0, $single['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 14.0, $single['position'], 0.0001 );
	}

	/**
	 * Multiple lines for one item aggregate correctly (sum in PHP).
	 */
	public function test_correct_aggregation_across_multiple_lines() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 7,
			)
		);
		$this->place_po( $po['id'] );

		$result = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			5.0
		);

		$this->assertEqualsWithDelta( 10.0, $result['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 15.0, $result['position'], 0.0001 );
	}

	/**
	 * Independent contributing lines are retained individually, never merged (INV-1, INV-7).
	 */
	public function test_independent_incoming_lines_retained() {
		$product = $this->create_simple_product();
		$po      = $this->create_purchase_order();
		$line_a  = $this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$line_b  = $this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 7,
			)
		);
		$this->place_po( $po['id'] );

		$result = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			0.0
		);

		$this->assertCount( 2, $result['incoming_lines'] );
		$line_ids = array_map( static fn( $l ) => (int) $l['line_id'], $result['incoming_lines'] );
		sort( $line_ids );
		$expected = array( (int) $line_a['id'], (int) $line_b['id'] );
		sort( $expected );
		$this->assertSame( $expected, $line_ids );
	}

	/**
	 * An item with no open PO lines has zero Incoming, Position = On Hand, and an empty line list.
	 */
	public function test_no_contributing_lines() {
		$product = $this->create_simple_product();

		$result = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			9.0
		);

		$this->assertSame( 0.0, $result['incoming'] );
		$this->assertSame( 9.0, $result['position'] );
		$this->assertFalse( $result['incoming_delayed'] );
		$this->assertSame( array(), $result['incoming_lines'] );
	}

	/**
	 * incoming_delayed is true when any contributing line is delayed, even if others are not.
	 */
	public function test_delayed_aggregation_true_if_any_line_delayed() {
		$product = $this->create_simple_product();

		$po_delayed = $this->create_purchase_order(
			array(
				'expected_date'       => gmdate( 'Y-m-d', strtotime( '-5 days' ) ),
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			)
		);
		$this->add_po_line(
			$po_delayed['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 2,
			)
		);
		$this->place_po( $po_delayed['id'] );

		$po_on_time = $this->create_purchase_order(
			array(
				'expected_date'       => gmdate( 'Y-m-d', strtotime( '+5 days' ) ),
				'expected_confidence' => WC_Inventory_Overview_PO_Confidence::EXACT,
			)
		);
		$this->add_po_line(
			$po_on_time['id'],
			array(
				'product_id'  => $product->get_id(),
				'qty_ordered' => 3,
			)
		);
		$this->place_po( $po_on_time['id'] );

		$result = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			0.0
		);

		$this->assertTrue( $result['incoming_delayed'] );
		$this->assertEqualsWithDelta( 5.0, $result['incoming'], 0.0001 );
	}

	/**
	 * Caller-supplied On Hand is used verbatim; the Service never refetches the product.
	 */
	public function test_caller_supplied_on_hand_is_used_verbatim() {
		$product = $this->create_simple_product( array( 'stock_qty' => 999 ) );

		$result = WC_Inventory_Overview_Inventory_Position_Service::get_position(
			WC_Inventory_Overview_Inventory_Position_Service::TYPE_PRODUCT,
			$product->get_id(),
			1.0
		);

		// The Service must use the caller-supplied On Hand (1.0), not the
		// product's actual WooCommerce stock quantity (999).
		$this->assertSame( 1.0, $result['on_hand'] );
	}

	/**
	 * Bulk calls correctly isolate products and variations sharing one flat result map.
	 */
	public function test_bulk_mixes_products_and_variations_without_cross_contamination() {
		$simple       = $this->create_simple_product();
		$variable     = $this->create_variable_product( array(), array( array( 'name' => 'Variation A' ) ) );
		$children     = $this->variation_ids( $variable );
		$variation_id = (int) $children[0];

		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'  => $simple->get_id(),
				'qty_ordered' => 4,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => $variation_id,
				'qty_ordered'  => 9,
			)
		);
		$this->place_po( $po['id'] );

		$bulk = WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk(
			array( $simple->get_id() => 1.0 ),
			array( $variation_id => 2.0 )
		);

		$this->assertEqualsWithDelta( 4.0, $bulk[ $simple->get_id() ]['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $bulk[ $simple->get_id() ]['position'], 0.0001 );
		$this->assertEqualsWithDelta( 9.0, $bulk[ $variation_id ]['incoming'], 0.0001 );
		$this->assertEqualsWithDelta( 11.0, $bulk[ $variation_id ]['position'], 0.0001 );
	}

	/**
	 * Query-scaling guard (WP7): a bulk call over 20+ mixed items issues at
	 * most one product-scoped and one variation-scoped SELECT against the
	 * PO lines table — no N+1, regardless of how many items are requested.
	 */
	public function test_bulk_call_issues_at_most_one_query_per_repository_method() {
		$product_on_hand   = array();
		$variation_on_hand = array();

		for ( $i = 0; $i < 12; $i++ ) {
			$product = $this->create_simple_product();
			$po      = $this->create_purchase_order();
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => 1,
				)
			);
			$this->place_po( $po['id'] );
			$product_on_hand[ $product->get_id() ] = 0.0;
		}

		for ( $i = 0; $i < 9; $i++ ) {
			$variable     = $this->create_variable_product( array(), array( array( 'name' => 'Variation ' . $i ) ) );
			$children     = $this->variation_ids( $variable );
			$variation_id = (int) $children[0];
			$po           = $this->create_purchase_order();
			$this->add_po_line(
				$po['id'],
				array(
					'product_id'   => $variable->get_id(),
					'variation_id' => $variation_id,
					'qty_ordered'  => 1,
				)
			);
			$this->place_po( $po['id'] );
			$variation_on_hand[ $variation_id ] = 0.0;
		}

		$this->assertGreaterThanOrEqual(
			20,
			count( $product_on_hand ) + count( $variation_on_hand ),
			'Query-scaling guard requires at least 20 mixed items per the M3 plan'
		);

		$lines_table      = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$position_queries = array();
		$counter          = static function ( $query ) use ( $lines_table, &$position_queries ) {
			if ( false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'SELECT' ) ) {
				$position_queries[] = $query;
			}
			return $query;
		};

		add_filter( 'query', $counter );
		WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( $product_on_hand, $variation_on_hand );
		remove_filter( 'query', $counter );

		$this->assertLessThanOrEqual(
			2,
			count( $position_queries ),
			'get_positions_bulk() must issue at most one product-line query and one variation-line query, regardless of row count'
		);
	}
}
