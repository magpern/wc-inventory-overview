<?php
/**
 * Integration tests for Milestone M14 — Supplier Order History.
 *
 * Full render through WC_Inventory_Overview_Purchasing_Page::render_page()
 * (tab=suppliers, action=edit) -- capability gate, pagination, PO-number
 * links, currency-correct/never-blended value display, status inclusion,
 * empty states.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Order_History_Admin extends WC_Inventory_Overview_Test_Case {

	/**
	 * @var int
	 */
	private $admin_id;

	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function purge_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Render the Supplier detail screen (tab=suppliers, action=edit) and
	 * capture its output -- the same public entry point wp-admin uses.
	 *
	 * @param int                  $supplier_id Supplier id.
	 * @param array<string,mixed>  $extra_get   Extra $_GET overrides (e.g. pagination arg).
	 * @return string
	 */
	private function render_supplier_detail_html( int $supplier_id, array $extra_get = array() ): string {
		$_GET = array_merge(
			array(
				'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
				'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
				'action'      => 'edit',
				'supplier_id' => (string) $supplier_id,
			),
			$extra_get
		);

		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		return (string) ob_get_clean();
	}

	/**
	 * A supplier with zero POs shows the "no purchase orders yet" empty
	 * state.
	 */
	public function test_zero_pos_shows_empty_state() {
		$supplier = $this->create_supplier();

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( 'No purchase orders yet for this supplier.', $html );
	}

	/**
	 * A supplier with POs renders the Order History table with PO number
	 * (linked to the PO detail screen), status label, and both value
	 * columns carrying that PO's own currency.
	 */
	public function test_renders_history_row_with_link_and_currency() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'USD',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 2,
				'unit_cost'   => 30.0,
			)
		);

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( 'Order History', $html );
		$this->assertStringContainsString( esc_html( $po['po_number'] ), $html );
		$this->assertStringContainsString( WC_Inventory_Overview_PO_Admin::detail_url( $po['id'] ), html_entity_decode( $html ) );
		$this->assertStringContainsString( '60.00 USD', $html );
		$this->assertStringContainsString( esc_html( WC_Inventory_Overview_PO_Statuses::label( WC_Inventory_Overview_PO_Statuses::PLACED ) ), $html );
	}

	/**
	 * Every status appears -- including draft, which M13's print feature
	 * deliberately excludes but M14's history does not (INV-M14-4).
	 */
	public function test_draft_and_terminal_statuses_all_appear() {
		$supplier = $this->create_supplier();
		$statuses = array(
			WC_Inventory_Overview_PO_Statuses::DRAFT,
			WC_Inventory_Overview_PO_Statuses::CANCELLED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);
		foreach ( $statuses as $status ) {
			$po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
			WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => $status ) );
		}

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		foreach ( $statuses as $status ) {
			$this->assertStringContainsString( esc_html( WC_Inventory_Overview_PO_Statuses::label( $status ) ), $html );
		}
	}

	/**
	 * Two POs in different currencies never get blended into one summed
	 * value -- each row's own currency is shown next to its own value, and
	 * no aggregate/total figure appears anywhere in the section.
	 */
	public function test_multiple_currencies_never_blended_into_a_total() {
		$supplier = $this->create_supplier();

		$po_eur = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'], 'currency' => 'EUR' ) );
		$this->add_po_line( $po_eur['id'], array( 'qty_ordered' => 1, 'unit_cost' => 100.0 ) );

		$po_usd = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'], 'currency' => 'USD' ) );
		$this->add_po_line( $po_usd['id'], array( 'qty_ordered' => 1, 'unit_cost' => 50.0 ) );

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( '100.00 EUR', $html );
		$this->assertStringContainsString( '50.00 USD', $html );
		// Never a blended 150.00 total anywhere in the page.
		$this->assertStringNotContainsString( '150.00', $html );
	}

	/**
	 * Pagination: with 25 POs (default per_page 20), page 1 shows the "Next"
	 * link and 20 rows; page 2 shows the "Previous" link and the remaining
	 * 5 rows, with no "Next" link (last page).
	 */
	public function test_pagination_across_pages() {
		$supplier   = $this->create_supplier();
		$po_numbers = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$po           = $this->create_purchase_order(
				array(
					'supplier_id' => $supplier['id'],
					'order_date'  => sprintf( '2026-01-%02d', $i ),
				)
			);
			$po_numbers[] = $po['po_number'];
		}
		// Newest order_date first: the last 20 created (Jan 06-25) are page 1;
		// the first 5 created (Jan 01-05) are page 2.
		$page1_expected = array_slice( $po_numbers, 5, 20 );
		$page2_expected = array_slice( $po_numbers, 0, 5 );

		$page1 = $this->render_supplier_detail_html( $supplier['id'] );
		$this->assertStringContainsString( 'Page 1 of 2', $page1 );
		$this->assertStringContainsString( 'Next', $page1 );
		$this->assertStringNotContainsString( 'Previous', $page1 );
		foreach ( $page1_expected as $po_number ) {
			$this->assertStringContainsString( esc_html( $po_number ), $page1 );
		}
		foreach ( $page2_expected as $po_number ) {
			$this->assertStringNotContainsString( esc_html( $po_number ), $page1 );
		}

		$page2 = $this->render_supplier_detail_html(
			$supplier['id'],
			array( 'wc_io_supplier_order_history_page' => '2' )
		);
		$this->assertStringContainsString( 'Page 2 of 2', $page2 );
		$this->assertStringContainsString( 'Previous', $page2 );
		$this->assertStringNotContainsString( 'Next', $page2 );
		foreach ( $page2_expected as $po_number ) {
			$this->assertStringContainsString( esc_html( $po_number ), $page2 );
		}
	}

	/**
	 * An out-of-range page (e.g. requesting page 5 when there is only 1)
	 * shows the "no results on this page" message, distinct from the
	 * "no purchase orders yet" zero-history message.
	 */
	public function test_out_of_range_page_shows_distinct_message() {
		$supplier = $this->create_supplier();
		$this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );

		$html = $this->render_supplier_detail_html(
			$supplier['id'],
			array( 'wc_io_supplier_order_history_page' => '99' )
		);

		$this->assertStringContainsString( 'No results on this page.', $html );
		$this->assertStringNotContainsString( 'No purchase orders yet for this supplier.', $html );
	}

	/**
	 * The pre-existing manage_woocommerce gate (unchanged by M14) still
	 * denies the whole Supplier detail screen to a user without the
	 * capability.
	 */
	public function test_capability_gate_denies_unauthorized_user() {
		$supplier    = $this->create_supplier();
		$subscriber  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_GET = array(
			'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
			'action'      => 'edit',
			'supplier_id' => (string) $supplier['id'],
		);

		try {
			ob_start();
			WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
			$html = ob_get_clean();
			$this->fail( 'Expected the capability gate to deny this user.' );
		} catch ( WPDieException $e ) {
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'Order History', $html );
		}
	}
}
