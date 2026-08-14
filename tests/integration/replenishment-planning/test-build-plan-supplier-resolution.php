<?php
/**
 * M24 WP-M24-5: supplier resolution contract (§12, BR-M24-4..8), including
 * the corrected BR-M24-4 cross-check: parity against
 * Reorder_Prefill_Service::resolve() on BOTH resolved supplier_id AND
 * semantic notice/outcome, for every fixture.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Build_Plan_Supplier_Resolution extends WC_Inventory_Overview_Test_Case {

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

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 1 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

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

	private function line_for( array $plan, int $item_post_id ): ?array {
		foreach ( $plan['groups'] as $group ) {
			foreach ( $group['lines'] as $line ) {
				$id = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
				if ( $id === $item_post_id ) {
					return array_merge( $line, array( '_supplier_id' => $group['supplier_id'] ) );
				}
			}
		}
		return null;
	}

	private function unresolved_for( array $plan, int $item_post_id ): ?array {
		foreach ( $plan['unresolved'] as $u ) {
			$id = $u['variation_id'] > 0 ? $u['variation_id'] : $u['product_id'];
			if ( $id === $item_post_id ) {
				return $u;
			}
		}
		return null;
	}

	/**
	 * BR-M24-4 cross-check: resolved supplier_id AND semantic outcome must
	 * match Reorder_Prefill_Service::resolve() exactly.
	 *
	 * @param WC_Product $product     Product/variation under test.
	 * @param int        $variation_id 0 for a simple product.
	 */
	private function assert_parity_with_prefill_service( WC_Product $product, int $variation_id = 0 ): void {
		$product_id = $variation_id > 0 ? $product->get_parent_id() : $product->get_id();
		$item_post_id = $variation_id > 0 ? $variation_id : $product->get_id();

		$prefill = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product_id, $variation_id );
		$this->assertSame( 'prefilled', $prefill['status'], 'Fixture sanity: prefill service must reach the prefilled branch.' );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();

		$line       = $this->line_for( $plan, $item_post_id );
		$unresolved = $this->unresolved_for( $plan, $item_post_id );

		if ( $prefill['supplier_id'] > 0 ) {
			$this->assertNotNull( $line, 'Item must resolve to a group line when the prefill service resolves a supplier.' );
			$this->assertSame( $prefill['supplier_id'], $line['_supplier_id'], 'Resolved supplier_id must match Reorder_Prefill_Service exactly (BR-M24-4).' );
			$this->assertNull( $unresolved );
		} else {
			$this->assertNotNull( $unresolved, 'Item must land in unresolved when the prefill service resolves no supplier.' );
			$this->assertNull( $line );

			// Semantic outcome parity: the prefill service's notice type
			// (no_supplier vs multiple_suppliers) must match the plan's
			// unresolved reason.
			$has_multiple_notice = false;
			foreach ( $prefill['notices'] as $notice ) {
				if ( false !== strpos( $notice['message'], 'more than one supplier' ) ) {
					$has_multiple_notice = true;
				}
			}
			$expected_reason = $has_multiple_notice ? 'multiple_suppliers' : 'no_supplier';
			$this->assertSame( $expected_reason, $unresolved['reason'], 'Unresolved reason must match the prefill service\'s semantic outcome (BR-M24-4).' );
		}

		// Stale-preference parity.
		$has_stale_notice = false;
		foreach ( $prefill['notices'] as $notice ) {
			if ( false !== strpos( $notice['message'], 'preferred supplier is no longer available' ) ) {
				$has_stale_notice = true;
			}
		}
		if ( null !== $line ) {
			$this->assertSame( $has_stale_notice, $line['preferred_supplier_stale'], 'preferred_supplier_stale must match the prefill service\'s stale notice presence.' );
		}
	}

	// -----------------------------------------------------------------
	// BR-M24-5: eligible preferred supplier used directly.
	// -----------------------------------------------------------------

	public function test_eligible_preferred_supplier_used_directly() {
		$product  = $this->make_needs_reorder_product();
		$supplier = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $supplier['id'] );

		$this->assert_parity_with_prefill_service( $product );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $product->get_id() );
		$this->assertSame( (int) $supplier['id'], $line['_supplier_id'] );
		$this->assertFalse( $line['preferred_supplier_stale'] );
	}

	// -----------------------------------------------------------------
	// BR-M24-6: stale preferred supplier falls back to history.
	// -----------------------------------------------------------------

	public function test_stale_preferred_supplier_falls_back_to_history() {
		$product  = $this->make_needs_reorder_product();
		$preferred = $this->create_supplier();
		WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $product->get_id(), $preferred['id'] );
		WC_Inventory_Overview_Suppliers::archive( $preferred['id'] );

		$history = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$this->assert_parity_with_prefill_service( $product );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $product->get_id() );
		$this->assertSame( $history['supplier_id'], $line['_supplier_id'] );
		$this->assertTrue( $line['preferred_supplier_stale'], 'Stale preferred supplier must be surfaced (BR-M24-6/§10.4).' );
	}

	// -----------------------------------------------------------------
	// BR-M24-7: zero eligible suppliers -> unresolved/no_supplier.
	// -----------------------------------------------------------------

	public function test_zero_eligible_suppliers_unresolved() {
		$product = $this->make_needs_reorder_product();

		$this->assert_parity_with_prefill_service( $product );

		$plan       = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$unresolved = $this->unresolved_for( $plan, $product->get_id() );
		$this->assertSame( 'no_supplier', $unresolved['reason'] );
	}

	// -----------------------------------------------------------------
	// BR-M24-8: multiple eligible suppliers -> unresolved/multiple_suppliers.
	// -----------------------------------------------------------------

	public function test_multiple_eligible_suppliers_unresolved() {
		$product = $this->make_needs_reorder_product();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01' );
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-02-01' );

		$this->assert_parity_with_prefill_service( $product );

		$plan       = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$unresolved = $this->unresolved_for( $plan, $product->get_id() );
		$this->assertSame( 'multiple_suppliers', $unresolved['reason'] );
	}

	// -----------------------------------------------------------------
	// Single eligible history supplier, no preference configured.
	// -----------------------------------------------------------------

	public function test_single_eligible_history_supplier_resolved() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$this->assert_parity_with_prefill_service( $product );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $product->get_id() );
		$this->assertSame( $seed['supplier_id'], $line['_supplier_id'] );
	}

	// -----------------------------------------------------------------
	// Variation identity parity.
	// -----------------------------------------------------------------

	public function test_variation_supplier_resolution_parity() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1', 'stock_qty' => 1 ) ) );
		$variable = wc_get_product( $variable->get_id() );
		$children = $variable->get_children();
		$variation = wc_get_product( $children[0] );
		$variation->set_low_stock_amount( 5 );
		$variation->save();

		$seed = $this->seed_committed_history(
			array(
				'product_id'   => $variable->get_id(),
				'variation_id' => $variation->get_id(),
			)
		);

		$this->assert_parity_with_prefill_service( $variation, $variation->get_id() );

		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan();
		$line = $this->line_for( $plan, $variation->get_id() );
		$this->assertNotNull( $line );
		$this->assertSame( $seed['supplier_id'], $line['_supplier_id'] );
		$this->assertSame( $variable->get_id(), $line['product_id'] );
	}

	// -----------------------------------------------------------------
	// Merged supplier excluded (same eligibility rule as M22/M23).
	// -----------------------------------------------------------------

	public function test_merged_supplier_excluded_from_history() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );
		$target  = $this->create_supplier();
		WC_Inventory_Overview_Suppliers::mark_merged( $seed['supplier_id'], $target['id'] );

		$this->assert_parity_with_prefill_service( $product );
	}
}
