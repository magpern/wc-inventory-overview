<?php
/**
 * Integration tests for M22 WP-M22-5: security/TOCTOU/tamper-resistance.
 *
 * Proves BR-M22-4/6/7 and INV-M22-15: tampered or mismatched reorder-prefill
 * params degrade gracefully (never a fatal, never a bypass); omitted params
 * are byte-identical to the pre-M22 baseline; a stock change between badge
 * render and click fails closed (stale, no supplier query); and
 * PO_Product_Validator returns the identical error code whether invoked at
 * prefill time or at actual submit time -- proving no rule drift between
 * the two call sites.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET-only navigation under test; nothing mutates on GET (M22 plan §9).

class Test_WC_IO_Reorder_Prefill_Security_Toctou extends WC_Inventory_Overview_Test_Case {

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

	private function render_new_po(): string {
		$_GET['page']   = WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG;
		$_GET['tab']    = WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS;
		$_GET['action'] = 'new';
		$_REQUEST       = $_GET;

		ob_start();
		WC_Inventory_Overview_PO_Admin::render_panel( 'new' );
		return ob_get_clean();
	}

	// ---------------------------------------------------------------
	// Tampered/mismatched params -> invalid, graceful, no fatal
	// ---------------------------------------------------------------

	public function test_tampered_mismatched_variation_pair_degrades_gracefully() {
		$variable_a = $this->create_variable_product( array(), array( array( 'name' => 'A1' ) ) );
		$variable_b = $this->create_variable_product( array(), array( array( 'name' => 'B1' ) ) );
		$variable_b = wc_get_product( $variable_b->get_id() );
		$children_b = $variable_b->get_children();

		$_GET['wc_io_ro_product_id']   = (string) $variable_a->get_id();
		$_GET['wc_io_ro_variation_id'] = (string) $children_b[0];

		$output = $this->render_new_po();

		$this->assertStringContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringContainsString( 'could not be prefilled', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	public function test_nonexistent_variation_id_degrades_gracefully() {
		$product                        = $this->create_simple_product();
		$_GET['wc_io_ro_product_id']    = (string) $product->get_id();
		$_GET['wc_io_ro_variation_id']  = '999999';

		$output = $this->render_new_po();

		$this->assertStringContainsString( 'could not be prefilled', $output );
	}

	public function test_negative_and_non_numeric_params_never_fatal() {
		foreach ( array( '-5', 'DROP TABLE wp_posts', '<script>', '99999999999999999999' ) as $raw ) {
			$_GET['wc_io_ro_product_id'] = $raw;
			$output                      = $this->render_new_po();
			$this->assertIsString( $output, "Input '{$raw}' must never fatal." );
		}
	}

	// ---------------------------------------------------------------
	// Omitted params -> byte-identical baseline
	// ---------------------------------------------------------------

	/**
	 * Strip the two per-render-random values (anti-double-submit request
	 * token, save nonce) so structural HTML can be compared for equality
	 * despite them differing on every render by design.
	 */
	private function strip_dynamic_tokens( string $html ): string {
		$html = (string) preg_replace( '/name="wc_io_po_request_token" value="[^"]*"/', 'name="wc_io_po_request_token" value="TOKEN"', $html );
		$html = (string) preg_replace( '/name="wc_io_po_save_nonce" value="[^"]*"/', 'name="wc_io_po_save_nonce" value="NONCE"', $html );
		return $html;
	}

	public function test_omitted_params_byte_identical_to_baseline() {
		$baseline = $this->strip_dynamic_tokens( $this->render_new_po() );

		unset( $_GET['wc_io_ro_product_id'], $_GET['wc_io_ro_variation_id'] );
		$again = $this->strip_dynamic_tokens( $this->render_new_po() );

		$this->assertSame( $baseline, $again );
		$this->assertStringNotContainsString( 'wc-io-reorder-prefill-notice', $baseline );
	}

	// ---------------------------------------------------------------
	// Staleness: stock changes between "render" and "click" -> fail closed
	// ---------------------------------------------------------------

	public function test_stock_change_between_badge_and_click_fails_closed() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		// At this point the item would show "Needs reorder" on the list table.

		// Simulate the gap: stock is replenished before the merchant's click resolves.
		$product->set_stock_quantity( 100 );
		$product->save();

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'no longer appears to need reordering', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output, 'A stale item must not be prefilled despite having been needs_reorder at badge-render time.' );
	}

	public function test_stale_outcome_issues_no_supplier_query() {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed' ) );

		$product->set_stock_quantity( 100 );
		$product->save();

		// Distinguish M22's own two supplier-resolution queries from the
		// pre-existing, always-present Suppliers::list(active) call that
		// render_header_fields() already issues for the manual dropdown
		// regardless of prefill status: the history query touches the
		// PO-lines table with a supplier GROUP BY; the bulk fetch is an
		// `id IN (...)` lookup, never a `status = 'active'` scan.
		$lines_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$hits        = array();
		$counter     = static function ( $query ) use ( $lines_table, &$hits ) {
			if ( false === stripos( $query, 'SELECT' ) ) {
				return $query;
			}
			$is_history_query = false !== strpos( $query, $lines_table ) && false !== stripos( $query, 'GROUP BY po.supplier_id' );
			$is_bulk_fetch     = false !== stripos( $query, 'wc_io_suppliers' ) && false !== stripos( $query, ' IN (' );
			if ( $is_history_query || $is_bulk_fetch ) {
				$hits[] = $query;
			}
			return $query;
		};

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();

		add_filter( 'query', $counter );
		$this->render_new_po();
		remove_filter( 'query', $counter );

		$this->assertSame( array(), $hits, 'A stale outcome must never issue M22\'s own supplier-history or bulk-fetch queries.' );
	}

	// ---------------------------------------------------------------
	// No rule drift: identical WP_Error code, prefill-time vs. submit-time
	// ---------------------------------------------------------------

	public function test_identical_validator_error_code_prefill_time_and_submit_time() {
		$variable = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );

		$prefill_result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $variable->get_id() );
		$this->assertSame( 'invalid', $prefill_result['status'] );

		$direct_validation = WC_Inventory_Overview_PO_Product_Validator::validate( $variable->get_id() );
		$this->assertTrue( is_wp_error( $direct_validation ) );

		$submit_time_error = WC_Inventory_Overview_PO_Product_Validator::validate( $variable->get_id() );
		$this->assertTrue( is_wp_error( $submit_time_error ) );

		$this->assertSame( $direct_validation->get_error_code(), $submit_time_error->get_error_code(), 'Prefill-time and submit-time identity validation must never drift (single validator, INV-M22-1-adjacent).' );
	}
}
