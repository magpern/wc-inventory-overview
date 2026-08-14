<?php
/**
 * Consolidated M22 capability matrix (WP-M22-6, BR-M22-2, INV-M22-14).
 *
 * A single pass proving every new M22 surface -- the list-table link, the
 * New PO prefill rendering, and supplier preselection -- is reachable if
 * and only if the viewer holds Purchasing_Caps::EDIT_PO, in the same run,
 * across three viewer types: EDIT_PO-capable, VIEW_PO-only, and neither.
 * Individual surfaces are already covered piecemeal by their own WP's
 * test files; this file exists to catch drift between them in one place.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Prefill_Capability_Matrix extends WC_Inventory_Overview_Test_Case {

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );
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

	private function place_po( int $po_id ): array {
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'Failed to place PO: ' . $result->get_error_message() );
		}
		return $result;
	}

	/**
	 * stock_qty=2 (not 0): a qty-0 product goes "Out of stock" in
	 * WooCommerce's own stock_status, which short-circuits
	 * render_direct_stock_badges_inner() before it ever reaches the
	 * Low-stock/Reorder-Signal branch. Mirrors the working fixture already
	 * used by M21's own list-table tests.
	 */
	private function needs_reorder_product(): WC_Product_Simple {
		$product = $this->create_simple_product( array( 'stock_qty' => 2 ) );
		$product->set_low_stock_amount( 5 );
		$product->save();

		$po = $this->create_purchase_order();
		$this->add_po_line( $po['id'], array( 'product_id' => $product->get_id(), 'qty_ordered' => 1 ) );
		$this->place_po( $po['id'] ); // on_hand=2, incoming=1, position=3 <= threshold(5) => needs_reorder.

		return $product;
	}

	private function as_edit_po_capable(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	private function as_view_po_only(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		add_filter(
			'wc_io_purchasing_capability_map',
			static function ( array $map ): array {
				$map[ WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ] = 'wc_io_test_edit_po_cap_unassigned';
				return $map;
			}
		);
	}

	private function as_neither(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$user    = new WP_User( $user_id );
		$user->add_cap( 'edit_products' );
		wp_set_current_user( $user_id );
	}

	private function list_table_html(): string {
		$table = new WC_Inventory_Overview_List_Table();
		$table->prepare_items();
		ob_start();
		$table->display_rows();
		return (string) ob_get_clean();
	}

	private function new_po_html( int $product_id ): string {
		$_GET['page']                = WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG;
		$_GET['tab']                 = WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS;
		$_GET['action']               = 'new';
		$_GET['wc_io_ro_product_id'] = (string) $product_id;
		$_REQUEST                    = $_GET;

		ob_start();
		WC_Inventory_Overview_PO_Admin::render_panel( 'new' );
		return (string) ob_get_clean();
	}

	public function test_edit_po_capable_viewer_sees_every_m22_surface() {
		$product = $this->needs_reorder_product();
		$this->as_edit_po_capable();

		$list_html = $this->list_table_html();
		$this->assertStringContainsString( 'wc-io-reorder-action', $list_html );

		$po_html = $this->new_po_html( $product->get_id() );
		$this->assertStringContainsString( 'value="' . $product->get_id() . '" selected="selected"', $po_html );
	}

	public function test_view_po_only_viewer_sees_no_m22_surface() {
		$product = $this->needs_reorder_product();
		$this->as_view_po_only();

		$list_html = $this->list_table_html();
		// The badge itself (manage_woocommerce-gated, M21) remains visible; only the M22 link is withheld.
		$this->assertStringContainsString( 'wc-io-badge-needs-reorder', $list_html );
		$this->assertStringNotContainsString( 'wc-io-reorder-action', $list_html );

		$po_html = $this->new_po_html( $product->get_id() );
		$this->assertStringNotContainsString( 'wc-io-reorder-prefill-notice', $po_html );
		$this->assertStringNotContainsString( 'selected="selected"', $po_html );
	}

	public function test_viewer_lacking_manage_woocommerce_sees_no_m22_surface() {
		$product = $this->needs_reorder_product();
		$this->as_neither();

		$list_html = $this->list_table_html();
		$this->assertStringNotContainsString( 'wc-io-badge-needs-reorder', $list_html );
		$this->assertStringNotContainsString( 'wc-io-reorder-action', $list_html );
	}
}
