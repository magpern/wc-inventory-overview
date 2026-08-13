<?php
/**
 * Integration tests for M22 WP-M22-3: PO_Admin's reorder-prefill wiring on
 * the New PO screen.
 *
 * Covers all five §8 status cases rendering the documented, mutually
 * distinguishable form state + notice (or lack thereof), EDIT_PO-gated
 * activation (BR-M22-2), Edit-PO-screen isolation (BR-M22-11), and that
 * nothing is persisted merely by rendering the screen with reorder GET
 * params present (BR-M22-12).
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET-only navigation under test; nothing mutates on GET (M22 plan §9).

class Test_WC_IO_PO_Admin_Reorder_Prefill_Rendering extends WC_Inventory_Overview_Test_Case {

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
		remove_all_filters( 'wc_io_purchasing_capability_map' );
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

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	// ---------------------------------------------------------------
	// Case 1: param key absent -- ordinary blank form, zero notices.
	// ---------------------------------------------------------------

	public function test_absent_param_renders_blank_form_no_notice() {
		$output = $this->render_new_po();

		$this->assertStringNotContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// Case 2: malformed (present but collapses to 0) -- blank form + notice.
	// ---------------------------------------------------------------

	public function test_malformed_param_renders_blank_form_with_notice() {
		$_GET['wc_io_ro_product_id'] = 'not-a-number';
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringContainsString( 'reorder link was invalid', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// Case 3: invalid identity -- blank form + notice.
	// ---------------------------------------------------------------

	public function test_invalid_identity_renders_blank_form_with_notice() {
		$variable                    = $this->create_variable_product( array(), array( array( 'name' => 'V1' ) ) );
		$_GET['wc_io_ro_product_id'] = (string) $variable->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringContainsString( 'could not be prefilled', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// Case 4: stale -- blank form + notice, distinguishable from case 3.
	// ---------------------------------------------------------------

	public function test_stale_renders_blank_form_with_distinct_notice() {
		$product = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringContainsString( 'no longer appears to need reordering', $output );
		$this->assertStringNotContainsString( 'could not be prefilled', $output, 'Stale must be distinguishable from invalid.' );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// Case 5: prefilled -- product preselected, supplier state per §5.
	// ---------------------------------------------------------------

	public function test_prefilled_preselects_product_zero_suppliers() {
		$product                      = $this->make_needs_reorder_product();
		$_GET['wc_io_ro_product_id']  = (string) $product->get_id();
		$output                       = $this->render_new_po();

		$this->assertStringContainsString( 'value="' . $product->get_id() . '" selected="selected"', $output );
		$this->assertStringContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringContainsString( 'No eligible supplier found', $output );
	}

	public function test_prefilled_preselects_supplier_when_exactly_one_eligible() {
		$product = $this->make_needs_reorder_product();
		$po      = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed' ) );

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'name="po[supplier_id]"', $output );
		// WP core's selected() helper emits single-quoted selected='selected'.
		$this->assertMatchesRegularExpression(
			'/<option value="' . preg_quote( (string) $po['supplier_id'], '/' ) . '"\s+selected=\'selected\'>/',
			$output
		);
	}

	public function test_prefilled_line_never_carries_qty_cost_sku_values_beyond_defaults() {
		$product                      = $this->make_needs_reorder_product();
		$_GET['wc_io_ro_product_id']  = (string) $product->get_id();
		$output                       = $this->render_new_po();

		// The unmodified blank-line default -- never a prefilled value.
		$this->assertStringContainsString( 'name="lines[0][qty_ordered]" value="1"', $output );
		$this->assertStringContainsString( 'name="lines[0][unit_cost]" value="0"', $output );
	}

	// ---------------------------------------------------------------
	// BR-M22-2 / INV-M22-14: EDIT_PO-gated, not merely VIEW_PO.
	// ---------------------------------------------------------------

	public function test_view_po_only_viewer_never_sees_prefill() {
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( array $map ): array {
				$map[ WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ] = 'wc_io_test_edit_po_cap_unassigned';
				return $map;
			}
		);

		$product                      = $this->make_needs_reorder_product();
		$_GET['wc_io_ro_product_id']  = (string) $product->get_id();
		$output                       = $this->render_new_po();

		$this->assertStringNotContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringNotContainsString( 'selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// BR-M22-11: Edit-PO screen is unaffected by reorder GET params.
	// ---------------------------------------------------------------

	public function test_edit_po_screen_unaffected_by_reorder_params() {
		$product = $this->make_needs_reorder_product();
		$po      = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $this->create_simple_product()->get_id() ) );

		$_GET['page']                = WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG;
		$_GET['tab']                 = WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS;
		$_GET['action']              = 'edit';
		$_GET['po_id']               = (string) $po['id'];
		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$_REQUEST                    = $_GET;

		ob_start();
		WC_Inventory_Overview_PO_Admin::render_panel( 'edit' );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'wc-io-reorder-prefill-notice', $output );
		$this->assertStringNotContainsString( 'value="' . $product->get_id() . '" selected="selected"', $output );
	}

	// ---------------------------------------------------------------
	// BR-M22-12: nothing persisted by the GET render.
	// ---------------------------------------------------------------

	public function test_get_render_persists_nothing() {
		$product = $this->make_needs_reorder_product();
		$po      = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed' ) );

		$before = WC_Inventory_Overview_Purchase_Orders::count();

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$this->render_new_po();

		$after = WC_Inventory_Overview_Purchase_Orders::count();

		$this->assertSame( $before, $after );
	}

	// ---------------------------------------------------------------
	// WP-M22-5: full GET-prefill -> POST-submit round trip through the
	// completely unmodified handle_save()/PO_Service::create_draft()
	// pipeline (BR-M22-12, INV-M22-3, INV-M22-15).
	// ---------------------------------------------------------------

	public function test_prefilled_get_then_submit_creates_matching_draft() {
		$product = $this->make_needs_reorder_product();
		$po      = $this->create_purchase_order( array( 'order_date' => '2026-01-01' ) );
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id() ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => 'placed' ) );

		// Step 1: GET render with the reorder-prefill params (what the merchant's browser does on click).
		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$this->render_new_po();

		// Step 2: the merchant reviews and submits -- exactly what handle_save() already
		// expects, with zero special-casing for reorder-prefill origin. The GET params
		// from step 1 are never read here; only $_POST matters (BR-M22-12/INV-M22-15).
		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'save' );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);

		$_POST    = array(
			'action'                 => 'wc_io_po_save',
			'po_id'                  => '0',
			'wc_io_po_save_nonce'    => wp_create_nonce( 'wc_io_po_save_0' ),
			'wc_io_po_request_token' => $token,
			'po'                     => array(
				'supplier_id' => (string) $po['supplier_id'],
				'currency'    => 'EUR',
			),
			'lines'                  => array(
				array(
					'product_id'  => (string) $product->get_id(),
					'qty_ordered' => '7',
					'unit_cost'   => '3.25',
				),
			),
		);
		$_REQUEST = $_POST;

		try {
			WC_Inventory_Overview_PO_Admin::handle_save();
			$this->fail( 'Expected redirect' );
		} catch ( Exception $e ) {
			$this->assertStringContainsString( 'wc_io_po=saved', $e->getMessage() );
		}

		$orders = WC_Inventory_Overview_Purchase_Orders::list( array( 'orderby' => 'id', 'order' => 'DESC', 'per_page' => 1 ) );
		$this->assertCount( 1, $orders );
		$created_po = $orders[0];
		$this->assertSame( 'draft', $created_po['status'] );

		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( (int) $created_po['id'] );
		$this->assertCount( 1, $lines );
		$this->assertSame( $product->get_id(), (int) $lines[0]['product_id'] );
		$this->assertSame( 0, (int) $lines[0]['variation_id'] );
		// Only what was actually typed into $_POST -- never a GET-smuggled value.
		$this->assertSame( '7.0000', $lines[0]['qty_ordered'] );
		$this->assertSame( '3.250000', $lines[0]['unit_cost'] );
	}
}
