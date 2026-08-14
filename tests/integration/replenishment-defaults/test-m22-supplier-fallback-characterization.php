<?php
/**
 * M23 WP-M23-1: characterization tests pinning M22's existing, unmodified
 * `WC_Inventory_Overview_Reorder_Prefill_Service::resolve_supplier()`
 * behavior and `render_line_row()`'s `qty_ordered ?? '1'` default, written
 * BEFORE any M23 production change (per the approved M23 plan §25/§26
 * WP-M23-1).
 *
 * These tests must pass unmodified both now (against the pristine M22
 * implementation) and after WP-M23-4 restructures `resolve_supplier()` --
 * they specifically pin the "no preferred supplier configured" path, which
 * BR-M23-3/INV-M23-7/INV-M23-18 require to remain byte-for-byte identical
 * to M22. If any assertion here needs to change after WP-M23-4, that is a
 * sign the M22 fallback was not preserved, not a reason to edit this file.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET-only navigation under test; nothing mutates on GET.

class Test_WC_IO_M22_Supplier_Fallback_Characterization extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
	}

	public function tearDown(): void {
		$_GET     = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Mirrors Test_WC_IO_Reorder_Prefill_Service's own helper exactly --
	 * status defaults to 'placed'; pass 'closed_short' for many-supplier
	 * query-count fixtures so Position's "incoming" isn't inflated.
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

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	// ---------------------------------------------------------------
	// History-branch pinning: 0 / 1 / many eligible suppliers.
	// ---------------------------------------------------------------

	public function test_no_history_yields_unselected_supplier_and_no_supplier_notice() {
		$product = $this->make_needs_reorder_product();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertCount( 1, $result['notices'] );
		$this->assertStringContainsString( "No eligible supplier found in this item's purchase history", $result['notices'][0]['message'] );
	}

	public function test_one_eligible_supplier_is_preselected_with_no_notice() {
		$product = $this->make_needs_reorder_product();
		$seed    = $this->seed_committed_history( array( 'product_id' => $product->get_id() ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( $seed['supplier_id'], $result['supplier_id'] );
		$this->assertSame( array(), $result['notices'] );
	}

	public function test_multiple_eligible_suppliers_yield_unselected_with_notice() {
		$product = $this->make_needs_reorder_product();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01' );
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-02-01' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertCount( 1, $result['notices'] );
		$this->assertStringContainsString( 'purchase history with more than one supplier', $result['notices'][0]['message'] );
	}

	public function test_draft_only_history_never_counts() {
		$product = $this->make_needs_reorder_product();
		// Default PO status is 'draft'; never force it to a committed status.
		$po = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 0, $result['supplier_id'], 'A draft-only PO must never surface a supplier preselection.' );
	}

	// ---------------------------------------------------------------
	// Exact notice text pinning (all four status branches + both
	// zero/many-supplier notices within 'prefilled').
	// ---------------------------------------------------------------

	public function test_exact_notice_strings() {
		$this->assertSame(
			'The reorder link was invalid — showing a blank purchase order.',
			WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 0 )['notices'][0]['message']
		);
		$this->assertSame(
			'This item could not be prefilled — showing a blank purchase order.',
			WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 999999 )['notices'][0]['message']
		);

		$stale = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$stale->set_low_stock_amount( 5 );
		$stale->save();
		$this->assertSame(
			'This item no longer appears to need reordering — showing a blank purchase order. You can still create one manually.',
			WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $stale->get_id() )['notices'][0]['message']
		);
	}

	// ---------------------------------------------------------------
	// Query-count pinning at 0 / 1 / 10 / 50 historical suppliers.
	// ---------------------------------------------------------------

	private function count_supplier_related_queries( int $product_id ): int {
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

		$this->assertSame( 'prefilled', $result['status'] );

		return count( $hits );
	}

	/**
	 * Measured (not assumed) baseline: the counter matches ANY query
	 * touching the lines/suppliers tables across the whole resolve() call,
	 * not just resolve_supplier() -- so it also picks up
	 * Inventory_Position_Service::get_position()'s own incoming-lines
	 * query (1 query, table-name match), independent of supplier history.
	 * At zero history, resolve_supplier() itself issues exactly 1 query
	 * (the history query; the bulk fetch never runs since $supplier_ids is
	 * empty) -- so the observed total is 1 (position) + 1 (history) = 2.
	 */
	public function test_query_count_at_zero_history_is_two() {
		$product = $this->make_needs_reorder_product();
		$this->assertSame( 2, $this->count_supplier_related_queries( $product->get_id() ) );
	}

	/**
	 * At >=1 historical supplier, resolve_supplier() issues 2 queries
	 * (history + bulk fetch), so the observed total is
	 * 1 (position) + 2 (history + bulk) = 3, fixed regardless of how many
	 * distinct suppliers are in that history (INV-M22-16).
	 */
	public function test_query_count_fixed_at_three_from_1_to_50_historical_suppliers() {
		$product = $this->make_needs_reorder_product();
		$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		$n_at_1 = $this->count_supplier_related_queries( $product->get_id() );
		$this->assertSame( 3, $n_at_1 );

		for ( $i = 0; $i < 9; $i++ ) {
			$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		}
		$n_at_10 = $this->count_supplier_related_queries( $product->get_id() );

		for ( $i = 0; $i < 40; $i++ ) {
			$this->seed_committed_history( array( 'product_id' => $product->get_id() ), '2026-01-01', 'closed_short' );
		}
		$n_at_50 = $this->count_supplier_related_queries( $product->get_id() );

		$this->assertSame( $n_at_1, $n_at_10 );
		$this->assertSame( $n_at_1, $n_at_50 );
	}

	// ---------------------------------------------------------------
	// render_line_row()'s existing qty_ordered/unit_cost/supplier_sku
	// blank-line defaults, exercised end-to-end through the New PO screen.
	// ---------------------------------------------------------------

	private function render_new_po(): string {
		$_GET['page']   = WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG;
		$_GET['tab']    = WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS;
		$_GET['action'] = 'new';
		$_REQUEST       = $_GET;

		ob_start();
		WC_Inventory_Overview_PO_Admin::render_panel( 'new' );
		return ob_get_clean();
	}

	public function test_render_line_row_qty_default_is_one_when_no_prefill() {
		$output = $this->render_new_po();

		$this->assertStringContainsString( 'name="lines[0][qty_ordered]" value="1"', $output );
		$this->assertStringContainsString( 'name="lines[0][unit_cost]" value="0"', $output );
		$this->assertStringContainsString( 'name="lines[0][supplier_sku]" value=""', $output );
	}

	public function test_render_line_row_qty_default_is_one_when_prefilled_without_quantity() {
		$product                     = $this->make_needs_reorder_product();
		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		// M22's prefill never sets qty_ordered -- the unmodified blank-line
		// default must still appear even though a product was prefilled.
		$this->assertStringContainsString( 'name="lines[0][qty_ordered]" value="1"', $output );
	}
}
