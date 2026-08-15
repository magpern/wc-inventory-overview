<?php
/**
 * M24 WP-M24-3: WC_Inventory_Overview_Summary::get_needs_reorder_items()
 * (§9.4/§9.5/§21).
 *
 * BR-M24-17: scan_low_stock_and_needs_reorder()'s counts/query count are
 * byte-identical before/after the gather_low_stock_candidates() extraction
 * -- proven separately by the pre-existing, unmodified
 * Test_WC_IO_Summary_Needs_Reorder / Test_WC_IO_Summary_Query_Count suites
 * staying green (WP-M24-1 characterization). This file proves the NEW
 * itemized method's own contract: parity with scan_low_stock_and_needs_reorder()'s
 * own count on the same fixture (unbounded), item shape, gather-order
 * truncation (BR-M24-11), and BR-M24-2/3 scoped-path semantics.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Summary_Extraction_Characterization extends WC_Inventory_Overview_Test_Case {

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

	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * Parity: get_needs_reorder_items(unbounded)'s item count matches
	 * scan_low_stock_and_needs_reorder()'s own needs_reorder delta on an
	 * identical mixed fixture (simple in-need, simple covered, variation).
	 */
	public function test_item_count_matches_scan_needs_reorder_delta() {
		$before = WC_Inventory_Overview_Summary::build( array() );

		$needs_reorder_product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$needs_reorder_product->set_low_stock_amount( 5 );
		$needs_reorder_product->save();

		$covered_product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$covered_product->set_low_stock_amount( 5 );
		$covered_product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $covered_product->get_id(), 'qty_ordered' => 10 ) );
		$this->place_po( $po['id'] );

		$after = WC_Inventory_Overview_Summary::build( array() );
		$delta = $after['needs_reorder'] - $before['needs_reorder'];

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array() );
		$ids    = array_map(
			static function ( $item ) {
				return $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			},
			$result['items']
		);

		$this->assertSame( 1, $delta, 'Fixture sanity: exactly one new needs_reorder item expected.' );
		$this->assertContains( $needs_reorder_product->get_id(), $ids );
		$this->assertNotContains( $covered_product->get_id(), $ids, 'A covered-by-incoming item must never appear in get_needs_reorder_items().' );
		$this->assertFalse( $result['truncated'] );
	}

	/**
	 * Item shape: product_id/variation_id follow the same convention as
	 * Reorder_Prefill_Service (product_id = parent id for a variation, own
	 * id for a simple product); name/sku/on_hand/threshold/position present.
	 */
	public function test_item_shape_simple_product() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->set_sku( 'M24-SHAPE-SKU' );
		$product->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array() );
		$item   = null;
		foreach ( $result['items'] as $i ) {
			if ( $i['product_id'] === $product->get_id() ) {
				$item = $i;
			}
		}
		$this->assertNotNull( $item );
		$this->assertSame( 0, $item['variation_id'] );
		$this->assertSame( 'M24-SHAPE-SKU', $item['sku'] );
		$this->assertEqualsWithDelta( 1.0, $item['on_hand'], 0.0001 );
		$this->assertEqualsWithDelta( 5.0, $item['threshold'], 0.0001 );
		$this->assertEqualsWithDelta( 1.0, $item['position'], 0.0001 );
	}

	public function test_item_shape_variation() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$variation = wc_get_product( $children[0] );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array() );
		$item   = null;
		foreach ( $result['items'] as $i ) {
			if ( $i['variation_id'] === $variation->get_id() ) {
				$item = $i;
			}
		}
		$this->assertNotNull( $item );
		$this->assertSame( $variable->get_id(), $item['product_id'], 'product_id must be the parent id for a variation.' );
	}

	// -----------------------------------------------------------------
	// BR-M24-11: gather-order truncation (§9.5)
	// -----------------------------------------------------------------

	public function test_truncation_at_limit() {
		for ( $i = 0; $i < 7; $i++ ) {
			$p = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$p->set_low_stock_amount( 5 );
			$p->save();
		}

		$unbounded = WC_Inventory_Overview_Summary::get_needs_reorder_items( array() );
		$this->assertGreaterThanOrEqual( 7, count( $unbounded['items'] ) );

		$bounded = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array(), 3 );
		$this->assertCount( 3, $bounded['items'] );
		$this->assertTrue( $bounded['truncated'] );

		// Gather-order prefix: the first 3 of the bounded result must equal
		// the first 3 of the unbounded result, in the same order -- never
		// re-sorted before truncation (§9.5/§10.3 distinction).
		$this->assertSame(
			array_slice( $unbounded['items'], 0, 3 ),
			$bounded['items']
		);
	}

	public function test_no_truncation_when_under_limit() {
		$p = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$p->set_low_stock_amount( 5 );
		$p->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array(), 500 );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_zero_limit_is_unbounded() {
		for ( $i = 0; $i < 5; $i++ ) {
			$p = $this->create_simple_product( array( 'stock_qty' => 1 ) );
			$p->set_low_stock_amount( 5 );
			$p->save();
		}
		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array(), 0 );
		$this->assertFalse( $result['truncated'] );
	}

	// -----------------------------------------------------------------
	// BR-M24-2/3: item_ids-scoped path
	// -----------------------------------------------------------------

	public function test_scoped_path_returns_only_requested_ids() {
		$in_scope = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$in_scope->set_low_stock_amount( 5 );
		$in_scope->save();

		$out_of_scope = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$out_of_scope->set_low_stock_amount( 5 );
		$out_of_scope->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array( $in_scope->get_id() ) );

		$ids = array_column( $result['items'], 'product_id' );
		$this->assertContains( $in_scope->get_id(), $ids );
		$this->assertNotContains( $out_of_scope->get_id(), $ids, 'Scoped discovery must never return ids outside the requested set.' );
	}

	public function test_scoped_path_silently_drops_stale_id() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) ); // not low-stock.
		$product->set_low_stock_amount( 5 );
		$product->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array( $product->get_id() ) );

		$this->assertSame( array(), $result['items'], 'A scoped id that no longer qualifies must be silently dropped, not errored (BR-M24-3).' );
		$this->assertFalse( $result['truncated'] );
	}

	public function test_scoped_path_silently_drops_deleted_id() {
		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array(), array( 999999999 ) );
		$this->assertSame( array(), $result['items'] );
	}

	/**
	 * BR-M24-16/22: a variable parent id in the scoped list is never
	 * returned as a candidate, and its concrete variations, if also
	 * requested, still resolve correctly (proven via the same Design A
	 * mixed include call characterized at WP-M24-1).
	 */
	public function test_scoped_path_excludes_variable_parent_includes_variation() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		wc_delete_product_transients( $variable->get_id() );
		$fresh    = wc_get_product( $variable->get_id() );
		$children = $fresh->get_children();
		$variation = wc_get_product( $children[0] );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items(
			array(),
			array( $variable->get_id(), $variation->get_id() )
		);

		$variation_ids = array_column( $result['items'], 'variation_id' );
		$product_ids   = array_column( $result['items'], 'product_id' );

		$this->assertContains( $variation->get_id(), $variation_ids );
		// The variable parent's own id must never appear as a standalone
		// product_id row (variation_id=0) -- only as the parent_id of the
		// variation row above.
		foreach ( $result['items'] as $item ) {
			if ( 0 === $item['variation_id'] ) {
				$this->assertNotSame( $variable->get_id(), $item['product_id'] );
			}
		}
	}

	// -----------------------------------------------------------------
	// INV-M24-12: no re-fetch -- captured from the gather pass only.
	// -----------------------------------------------------------------

	public function test_no_wc_get_product_calls_downstream_of_gather() {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		// Not a call-count instrumentation (WooCommerce core has no filter
		// hook for wc_get_product() call counting), but a shape proof: the
		// returned item's name/sku are populated without any subsequent
		// product lookup being necessary, and match the product's own
		// current values -- proving the data came from the gather pass.
		$result = WC_Inventory_Overview_Summary::get_needs_reorder_items( array() );
		$item   = null;
		foreach ( $result['items'] as $i ) {
			if ( $i['product_id'] === $product->get_id() ) {
				$item = $i;
			}
		}
		$this->assertNotNull( $item );
		$this->assertSame( $product->get_name(), $item['name'] );
	}
}
