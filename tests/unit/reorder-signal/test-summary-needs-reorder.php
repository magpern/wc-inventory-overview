<?php
/**
 * Unit tests for WC_Inventory_Overview_Summary's M21 needs_reorder extension
 * (WP-M21-4, WP-M21-5): build()'s low_stock value/population is unchanged
 * (BR-M21-7); needs_reorder is always <= low_stock; classify_needs_reorder_bulk()
 * correctness.
 *
 * Summary::build( array() ) scans the entire catalog (no per-test scoping
 * filter exists for it), and this suite's dbDelta()-based schema setup
 * breaks WP_UnitTestCase's usual per-test transaction rollback for product
 * fixtures (a pre-existing quirk, documented in
 * tests/integration/inventory-position/test-inventory-position-list-table.php)
 * -- so every count-asserting test below measures the delta this test's own
 * fixtures cause, taken against a baseline snapshot at the top of the test,
 * rather than an absolute catalog-wide count.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Summary_Needs_Reorder extends WC_Inventory_Overview_Test_Case {

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
	 * @return array<string, int>
	 */
	private function stats(): array {
		return WC_Inventory_Overview_Summary::build( array() );
	}

	/**
	 * A brand-new not-low-stock product changes neither count.
	 */
	public function test_not_low_stock_product_does_not_change_either_count() {
		$before = $this->stats();

		$this->create_simple_product( array( 'stock_qty' => 100 ) );

		$after = $this->stats();

		$this->assertSame( $before['low_stock'], $after['low_stock'] );
		$this->assertSame( $before['needs_reorder'], $after['needs_reorder'] );
	}

	/**
	 * BR-M21-7: low_stock's value/population is unchanged by M21 -- a
	 * low-stock item with NO incoming supply increments both counts by
	 * exactly one, and (since Position === On Hand here) is also
	 * needs_reorder.
	 */
	public function test_low_stock_with_no_incoming_increments_both() {
		$before = $this->stats();

		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$after = $this->stats();

		$this->assertSame( $before['low_stock'] + 1, $after['low_stock'] );
		$this->assertSame( $before['needs_reorder'] + 1, $after['needs_reorder'] );
	}

	/**
	 * A low-stock item whose incoming supply already covers it increments
	 * low_stock but NOT needs_reorder -- proving needs_reorder is a genuine
	 * subset, not a duplicate of low_stock.
	 */
	public function test_low_stock_covered_by_incoming_excluded_from_needs_reorder() {
		$before = $this->stats();

		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );
		// on_hand=1, incoming=10, position=11 > threshold(5) => covered, not needs_reorder.

		$after = $this->stats();

		$this->assertSame( $before['low_stock'] + 1, $after['low_stock'] );
		$this->assertSame( $before['needs_reorder'], $after['needs_reorder'], 'A covered-by-incoming item must not increment needs_reorder.' );
	}

	/**
	 * Mixed fixture: some low-stock lines need reorder, some are covered --
	 * needs_reorder's delta counts exactly the subset (BR-M21-7).
	 */
	public function test_mixed_fixture_needs_reorder_is_exact_subset() {
		$before = $this->stats();

		$needs_reorder_product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$needs_reorder_product->set_low_stock_amount( 5 );
		$needs_reorder_product->save();

		$covered_product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$covered_product->set_low_stock_amount( 5 );
		$covered_product->save();

		$not_low_product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$not_low_product->set_low_stock_amount( 5 );
		$not_low_product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $needs_reorder_product->get_id(), 'qty_ordered' => 1 ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $covered_product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$after = $this->stats();

		$this->assertSame( $before['low_stock'] + 2, $after['low_stock'] );
		$this->assertSame( $before['needs_reorder'] + 1, $after['needs_reorder'] );
		$this->assertLessThanOrEqual( $after['low_stock'], $after['needs_reorder'] );
	}

	/**
	 * A low-stock variation is classified independently of its parent and of
	 * sibling variations (INV-8/INV-M21-7).
	 */
	public function test_variation_classified_independently() {
		$before = $this->stats();

		$variable = $this->create_variable_product(
			array(),
			array(
				array( 'name' => 'A', 'stock_qty' => 1 ),
				array( 'name' => 'B', 'stock_qty' => 1 ),
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

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $variable->get_id(), 'variation_id' => (int) $children[0], 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$after = $this->stats();

		$this->assertSame( $before['low_stock'] + 2, $after['low_stock'] );
		$this->assertSame( $before['needs_reorder'] + 1, $after['needs_reorder'], 'Only the variation without covering incoming supply should need reorder.' );
	}

	/**
	 * Return shape includes needs_reorder alongside every pre-M21 key,
	 * unchanged (INV-M21-6, additive-only).
	 */
	public function test_build_return_shape_includes_needs_reorder_additively() {
		$stats = $this->stats();

		$this->assertEqualsCanonicalizing(
			array( 'total', 'in_stock', 'out_of_stock', 'on_backorder', 'low_stock', 'needs_reorder', 'draft', 'hidden' ),
			array_keys( $stats )
		);
	}

	// -----------------------------------------------------------------
	// WP-M21-5: get_low_stock_lines_for_chart() row extension (BR-M21-8)
	// -----------------------------------------------------------------

	/**
	 * Pre-M21 keys/values/order (id, name, qty, low) are unchanged; new keys
	 * are additive.
	 */
	public function test_chart_row_shape_is_additive() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$rows = WC_Inventory_Overview_Summary::get_low_stock_lines_for_chart( array(), 10 );

		$row = null;
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] === $product->get_id() ) {
				$row = $r;
				break;
			}
		}
		$this->assertNotNull( $row );

		$this->assertEqualsCanonicalizing(
			array( 'id', 'name', 'qty', 'low', 'position', 'incoming', 'needs_reorder', 'covered_by_incoming' ),
			array_keys( $row )
		);
		$this->assertEqualsWithDelta( 1.0, $row['qty'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $row['low'], 0.0001 );
	}

	/**
	 * A chart row with no incoming supply: position === on_hand, incoming
	 * === 0, needs_reorder === true.
	 */
	public function test_chart_row_with_no_incoming_needs_reorder() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$row = null;
		foreach ( WC_Inventory_Overview_Summary::get_low_stock_lines_for_chart( array(), 50 ) as $r ) {
			if ( (int) $r['id'] === $product->get_id() ) {
				$row = $r;
			}
		}
		$this->assertNotNull( $row );

		$this->assertEqualsWithDelta( 1.0, $row['position'], 0.0001 );
		$this->assertEqualsWithDelta( 0.0, $row['incoming'], 0.0001 );
		$this->assertTrue( $row['needs_reorder'] );
		$this->assertFalse( $row['covered_by_incoming'] );
	}

	/**
	 * A chart row whose incoming supply covers the threshold:
	 * covered_by_incoming === true, needs_reorder === false.
	 */
	public function test_chart_row_covered_by_incoming() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$row = null;
		foreach ( WC_Inventory_Overview_Summary::get_low_stock_lines_for_chart( array(), 50 ) as $r ) {
			if ( (int) $r['id'] === $product->get_id() ) {
				$row = $r;
			}
		}
		$this->assertNotNull( $row );

		$this->assertEqualsWithDelta( 11.0, $row['position'], 0.0001 );
		$this->assertEqualsWithDelta( 10.0, $row['incoming'], 0.0001 );
		$this->assertFalse( $row['needs_reorder'] );
		$this->assertTrue( $row['covered_by_incoming'] );
	}

	/**
	 * The Dashboard chart payload (Dashboard_Charts_Data) continues to use
	 * only name/qty from each row -- unaffected by the new keys (BR-M21-8).
	 */
	public function test_dashboard_chart_payload_unaffected_by_new_keys() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$summary_base = array(
			'search'          => '',
			'category_tt_id'  => 0,
			'stock_status'    => array(),
			'exclude_private' => false,
		);

		$payload = WC_Inventory_Overview_Dashboard_Charts_Data::build_admin_script_payload(
			array( 'totals' => array( 'product_revenue' => 0 ), 'daily_buckets' => array() ),
			array( 'date_from' => '2026-01-01', 'date_to' => '2026-12-31', 'statuses' => array() ),
			array( 'date_from' => '2026-01-01', 'date_to' => '2026-12-31' ),
			$summary_base
		);

		$this->assertArrayHasKey( 'lowStock', $payload );
		$this->assertArrayHasKey( 'labels', $payload['lowStock'] );
		$this->assertArrayHasKey( 'values', $payload['lowStock'] );
		$this->assertNotEmpty( $payload['lowStock']['values'] );
	}
}
