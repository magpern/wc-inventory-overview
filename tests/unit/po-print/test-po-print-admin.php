<?php
/**
 * M13: WC_Inventory_Overview_PO_Admin::handle_print() -- the full security
 * and printable-state matrix from docs/milestones/m13-implementation-plan.md
 * Part G/Part S, in the same handler-simulation style as
 * tests/unit/purchase-orders/test-po-admin.php.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName,WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHPUnit admin-handler simulations.

/**
 * PO print handler tests.
 */
class Test_WC_IO_PO_Print_Admin extends WC_Inventory_Overview_Test_Case {

	/**
	 * Admin user id.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Setup.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Build a PO (with one line) at the given status.
	 *
	 * @param string $status  Desired PO status: draft, placed, partially_received, received, cancelled, closed_short.
	 * @param float  $ordered Qty ordered on the single line.
	 * @return array{po_id:int,line_id:int,supplier:array,product:WC_Product_Simple}
	 */
	private function po_at_status( string $status, float $ordered = 10.0 ): array {
		$supplier = $this->create_supplier(
			array(
				'email' => 'supplier@example.test',
				'phone' => '555-0100',
			)
		);
		$product  = $this->create_simple_product();
		$po_id    = WC_Inventory_Overview_PO_Service::create_draft(
			array( 'supplier_id' => (int) $supplier['id'] ),
			array(
				array(
					'product_id'  => $product->get_id(),
					'qty_ordered' => $ordered,
					'unit_cost'   => 2.5,
				),
			)
		);
		$lines    = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$line_id  = (int) $lines[0]['id'];

		switch ( $status ) {
			case 'draft':
				break;
			case 'placed':
				WC_Inventory_Overview_PO_Service::place( $po_id );
				break;
			case 'partially_received':
				WC_Inventory_Overview_PO_Service::place( $po_id );
				WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( $line_id, $ordered / 2, 1, 'GR-TEST-1', $this->admin_id, false );
				break;
			case 'received':
				WC_Inventory_Overview_PO_Service::place( $po_id );
				WC_Inventory_Overview_PO_Receiving_Sync::apply_line_delta( $line_id, $ordered, 1, 'GR-TEST-1', $this->admin_id, false );
				break;
			case 'cancelled':
				WC_Inventory_Overview_PO_Service::place( $po_id );
				WC_Inventory_Overview_PO_Service::cancel( $po_id );
				break;
			case 'closed_short':
				WC_Inventory_Overview_PO_Service::place( $po_id );
				WC_Inventory_Overview_PO_Service::close_short( $po_id );
				break;
		}

		return array(
			'po_id'    => $po_id,
			'line_id'  => $line_id,
			'supplier' => $supplier,
			'product'  => $product,
		);
	}

	/**
	 * Populate $_GET/$_REQUEST with a valid print request for a PO.
	 *
	 * @param int $po_id PO id.
	 */
	private function set_print_request( int $po_id ): void {
		$_GET     = array(
			'action'               => 'wc_io_po_print',
			'po_id'                => (string) $po_id,
			'wc_io_po_print_nonce' => wp_create_nonce( 'wc_io_po_print_' . $po_id ),
		);
		$_REQUEST = $_GET;
	}

	// -----------------------------------------------------------------
	// A / G-K: authorized, valid nonce, each printable status -> success.
	// -----------------------------------------------------------------

	/**
	 * Authorized user, valid nonce, printable status -> a standalone document.
	 *
	 * @dataProvider printable_status_provider
	 * @param string $status Printable status under test.
	 */
	public function test_printable_status_succeeds( string $status ) {
		$setup = $this->po_at_status( $status );
		$this->set_print_request( $setup['po_id'] );

		ob_start();
		WC_Inventory_Overview_PO_Admin::handle_print();
		$html = ob_get_clean();

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( $setup['supplier']['name'], $html );
	}

	/**
	 * Data provider: every printable status (draft is deliberately excluded).
	 *
	 * @return array<string,array<int,string>>
	 */
	public function printable_status_provider(): array {
		return array(
			'placed'             => array( 'placed' ),
			'partially_received' => array( 'partially_received' ),
			'received'           => array( 'received' ),
			'cancelled'          => array( 'cancelled' ),
			'closed_short'       => array( 'closed_short' ),
		);
	}

	// -----------------------------------------------------------------
	// F: draft is denied server-side, even with a valid nonce/capability.
	// -----------------------------------------------------------------

	/**
	 * Draft is denied server-side even with valid capability/nonce.
	 */
	public function test_draft_po_denied() {
		$setup = $this->po_at_status( 'draft' );
		$this->set_print_request( $setup['po_id'] );

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			ob_end_clean();
			$this->fail( 'Expected draft PO to be denied.' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
			$this->assertStringContainsString( 'cannot be printed', $e->getMessage() );
		}
	}

	// -----------------------------------------------------------------
	// B / C: missing / invalid nonce -> denied, nothing rendered.
	// -----------------------------------------------------------------

	/**
	 * Missing nonce is denied; nothing is rendered.
	 */
	public function test_missing_nonce_denied() {
		$setup    = $this->po_at_status( 'placed' );
		$_GET     = array(
			'action' => 'wc_io_po_print',
			'po_id'  => (string) $setup['po_id'],
		);
		$_REQUEST = $_GET;

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			$html = ob_get_clean();
			$this->fail( 'Expected missing-nonce denial.' );
		} catch ( WPDieException $e ) {
			$html = ob_get_clean();
			$this->assertStringNotContainsString( $setup['supplier']['name'], $html, 'No purchasing/supplier data may be rendered before nonce verification succeeds.' );
		}
	}

	/**
	 * Invalid nonce is denied; nothing is rendered.
	 */
	public function test_invalid_nonce_denied() {
		$setup    = $this->po_at_status( 'placed' );
		$_GET     = array(
			'action'               => 'wc_io_po_print',
			'po_id'                => (string) $setup['po_id'],
			'wc_io_po_print_nonce' => 'not-a-valid-nonce',
		);
		$_REQUEST = $_GET;

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			$html = ob_get_clean();
			$this->fail( 'Expected invalid-nonce denial.' );
		} catch ( WPDieException $e ) {
			$html = ob_get_clean();
			$this->assertStringNotContainsString( $setup['supplier']['name'], $html );
		}
	}

	/**
	 * A valid nonce for a *different* PO id must not authorize this one --
	 * the nonce is scoped to both the action and the specific PO.
	 */
	public function test_nonce_scoped_to_po_id_not_reusable_across_purchase_orders() {
		$setup_a = $this->po_at_status( 'placed' );
		$setup_b = $this->po_at_status( 'placed' );

		$_GET     = array(
			'action'               => 'wc_io_po_print',
			'po_id'                => (string) $setup_b['po_id'],
			'wc_io_po_print_nonce' => wp_create_nonce( 'wc_io_po_print_' . $setup_a['po_id'] ),
		);
		$_REQUEST = $_GET;

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			ob_end_clean();
			$this->fail( 'Expected nonce scoped to a different PO id to be rejected.' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	// -----------------------------------------------------------------
	// D: user without VIEW_PO -> denied.
	// -----------------------------------------------------------------

	/**
	 * A user without VIEW_PO is denied.
	 */
	public function test_unauthorized_user_denied() {
		$setup = $this->po_at_status( 'placed' );
		$this->set_print_request( $setup['po_id'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			ob_end_clean();
			$this->fail( 'Expected capability denial.' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
			$this->assertStringContainsString( 'Insufficient permissions', $e->getMessage() );
		}
	}

	// -----------------------------------------------------------------
	// E: nonexistent PO id -> denied.
	// -----------------------------------------------------------------

	/**
	 * A nonexistent PO id is denied.
	 */
	public function test_nonexistent_po_denied() {
		$bogus_id = 999999;
		$this->set_print_request( $bogus_id );

		try {
			ob_start();
			WC_Inventory_Overview_PO_Admin::handle_print();
			ob_end_clean();
			$this->fail( 'Expected not-found denial.' );
		} catch ( WPDieException $e ) {
			ob_end_clean();
			$this->assertStringContainsString( 'not found', $e->getMessage() );
		}
	}

	// -----------------------------------------------------------------
	// L: deleted product/variation referenced by a line -> still succeeds,
	// via the line's own historical snapshot columns.
	// -----------------------------------------------------------------

	/**
	 * A deleted product/variation line still prints via its snapshot columns.
	 */
	public function test_deleted_product_line_still_prints_via_snapshot() {
		$setup = $this->po_at_status( 'placed' );

		$expected_name = $setup['product']->get_name();
		$expected_sku  = $setup['product']->get_sku();

		wp_delete_post( $setup['product']->get_id(), true );

		$this->set_print_request( $setup['po_id'] );

		ob_start();
		WC_Inventory_Overview_PO_Admin::handle_print();
		$html = ob_get_clean();

		$this->assertStringContainsString( $expected_name, $html, 'Deleted product must still print via name_snapshot.' );
		if ( '' !== $expected_sku ) {
			$this->assertStringContainsString( $expected_sku, $html, 'Deleted product must still print via sku_snapshot.' );
		}
	}

	// -----------------------------------------------------------------
	// M: supplier row unresolvable -> still succeeds, via the PO header's
	// own supplier_name_snapshot; contact/reference fields simply absent.
	// -----------------------------------------------------------------

	/**
	 * An unresolvable supplier row still prints via the header's own snapshot.
	 */
	public function test_unresolvable_supplier_still_prints_via_header_snapshot() {
		$setup = $this->po_at_status( 'placed' );

		$po = WC_Inventory_Overview_Purchase_Orders::get( $setup['po_id'] );
		$this->assertNotEmpty( $po['supplier_name_snapshot'] );

		global $wpdb;
		$wpdb->delete( WC_Inventory_Overview_Suppliers::table_name(), array( 'id' => (int) $setup['supplier']['id'] ) );

		$this->set_print_request( $setup['po_id'] );

		ob_start();
		WC_Inventory_Overview_PO_Admin::handle_print();
		$html = ob_get_clean();

		$this->assertStringContainsString( $po['supplier_name_snapshot'], $html );
		$this->assertStringNotContainsString( $setup['supplier']['email'], $html );
	}

	// -----------------------------------------------------------------
	// Entry-point visibility: the detail screen's Print link matches the
	// same printable-status gate the handler itself enforces.
	// -----------------------------------------------------------------

	/**
	 * The print URL is nonce-scoped per PO id, not a shared/reusable link.
	 */
	public function test_print_url_is_scoped_to_po_id() {
		$url_a = WC_Inventory_Overview_PO_Admin::print_url( 5 );
		$url_b = WC_Inventory_Overview_PO_Admin::print_url( 6 );

		$this->assertStringContainsString( 'action=wc_io_po_print', $url_a );
		$this->assertStringContainsString( 'po_id=5', $url_a );
		$this->assertNotSame( $url_a, $url_b );
	}
}
