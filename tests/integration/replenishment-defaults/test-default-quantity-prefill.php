<?php
/**
 * Integration tests for M23 WP-M23-4: default-quantity prefill in
 * WC_Inventory_Overview_Reorder_Prefill_Service::resolve() and its
 * consumption by PO_Admin's render_line_row().
 *
 * Covers BR-M23-13/16 and INV-M23-9: a configured default quantity
 * populates the prefilled line's qty_ordered verbatim (never derived from
 * position/threshold); absent a configured quantity, the ordinary '1'
 * default is unaffected; the quantity never leaks into malformed/invalid/
 * stale outcomes; a submitted POST value overrides the prefilled value at
 * actual submit (POST authority, §20 of the M23 plan).
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- GET-only navigation under test; nothing mutates on GET.

class Test_WC_IO_Default_Quantity_Prefill extends WC_Inventory_Overview_Test_Case {

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

	private function make_needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 0 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();
		return $product;
	}

	// ---------------------------------------------------------------
	// resolve()-level assertions.
	// ---------------------------------------------------------------

	public function test_configured_quantity_appears_in_prefilled_line() {
		$product = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '15' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertSame( 'prefilled', $result['status'] );
		$this->assertArrayHasKey( 'qty_ordered', $result['line'] );
		$this->assertEqualsWithDelta( 15.0, (float) $result['line']['qty_ordered'], 0.0001 );
	}

	public function test_absent_quantity_omits_key_from_line() {
		$product = $this->make_needs_reorder_product();

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertArrayNotHasKey( 'qty_ordered', $result['line'] );
	}

	public function test_decimal_quantity_preserved() {
		$product = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '2.75' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $product->get_id() );

		$this->assertEqualsWithDelta( 2.75, (float) $result['line']['qty_ordered'], 0.0001 );
	}

	/**
	 * A quantity configured on a variation must never leak onto that
	 * variation's parent's identity, and vice versa.
	 */
	public function test_quantity_never_leaks_across_malformed_invalid_stale() {
		$this->assertNull( WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 0 )['line'] );
		$this->assertNull( WC_Inventory_Overview_Reorder_Prefill_Service::resolve( 999999 )['line'] );

		$stale = $this->create_simple_product( array( 'stock_qty' => 100 ) );
		$stale->set_low_stock_amount( 5 );
		$stale->save();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $stale->get_id(), '20' );

		$result = WC_Inventory_Overview_Reorder_Prefill_Service::resolve( $stale->get_id() );
		$this->assertSame( 'stale', $result['status'] );
		$this->assertNull( $result['line'], 'A configured quantity must never resurrect a discarded stale prefill.' );
	}

	// ---------------------------------------------------------------
	// Rendering-level: absent default -> unchanged '1'; configured -> flows through.
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

	public function test_render_shows_configured_quantity_as_prefilled_value() {
		$product = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '6' );

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'name="lines[0][qty_ordered]" value="6"', $output );
	}

	public function test_render_falls_back_to_one_when_unconfigured() {
		$product = $this->make_needs_reorder_product();

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$output                      = $this->render_new_po();

		$this->assertStringContainsString( 'name="lines[0][qty_ordered]" value="1"', $output );
	}

	// ---------------------------------------------------------------
	// BR-M23-16/§20: submitted POST value overrides the prefilled default.
	// ---------------------------------------------------------------

	public function test_submitted_post_quantity_overrides_prefilled_default() {
		$product = $this->make_needs_reorder_product();
		WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $product->get_id(), '6' );
		$supplier = $this->create_supplier();

		$_GET['wc_io_ro_product_id'] = (string) $product->get_id();
		$this->render_new_po();

		$token = WC_Inventory_Overview_PO_Request_Token::issue( 'save' );
		add_filter(
			'wp_redirect',
			static function ( $location ) {
				throw new Exception( 'redirect:' . $location );
			}
		);

		$_POST = array(
			'action'                 => 'wc_io_po_save',
			'po_id'                  => '0',
			'wc_io_po_save_nonce'    => wp_create_nonce( 'wc_io_po_save_0' ),
			'wc_io_po_request_token' => $token,
			'po'                     => array(
				'supplier_id' => (string) $supplier['id'],
				'currency'    => 'EUR',
			),
			'lines'                  => array(
				0 => array(
					'product_id'  => (string) $product->get_id(),
					'qty_ordered' => '99',
					'unit_cost'   => '1',
				),
			),
		);
		$_REQUEST = $_POST;

		try {
			WC_Inventory_Overview_PO_Admin::handle_save();
			$this->fail( 'Expected a redirect exception.' );
		} catch ( Exception $e ) {
			$this->assertStringStartsWith( 'redirect:', $e->getMessage() );
		}

		$pos = WC_Inventory_Overview_Purchase_Orders::list( array( 'orderby' => 'id', 'order' => 'DESC', 'per_page' => 1 ) );
		$this->assertNotEmpty( $pos );
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( (int) $pos[0]['id'] );
		$this->assertEqualsWithDelta( 99.0, (float) $lines[0]['qty_ordered'], 0.0001, 'The submitted POST quantity (99) must win over the prefilled default (6).' );
	}
}
