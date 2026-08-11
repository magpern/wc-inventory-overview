<?php
/**
 * Integration tests for Milestone M15 — Supplier Spend Summary.
 *
 * Full render through WC_Inventory_Overview_Purchasing_Page::render_page()
 * (tab=suppliers, action=edit) -- capability gate, empty state, currency
 * grouping/never-blended totals, committed-status filtering, section
 * ordering relative to Observed Lead Time / Order History.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Spend_Admin extends WC_Inventory_Overview_Test_Case {

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
	 * @param int $supplier_id Supplier id.
	 * @return string
	 */
	private function render_supplier_detail_html( int $supplier_id ): string {
		$_GET = array(
			'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
			'action'      => 'edit',
			'supplier_id' => (string) $supplier_id,
		);

		ob_start();
		WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
		return (string) ob_get_clean();
	}

	/**
	 * Isolate just the Spend Summary section's HTML from a full Supplier
	 * detail page -- the page also legitimately renders the draft/cancelled
	 * PO's own value inside the separate, status-inclusive Order History
	 * section (INV-M14-4), so assertions about what Spend Summary excludes
	 * must not be made against the whole page.
	 *
	 * @param string $html Full render_supplier_detail_html() output.
	 * @return string
	 */
	private function spend_summary_section_html( string $html ): string {
		$start = strpos( $html, 'Spend Summary' );
		$this->assertNotFalse( $start, 'Spend Summary heading not found in page output.' );
		$end = strpos( $html, '<h3>', $start );
		return false === $end ? substr( $html, $start ) : substr( $html, $start, $end - $start );
	}

	/**
	 * A supplier with zero POs shows the Spend Summary empty state.
	 */
	public function test_zero_pos_shows_empty_state() {
		$supplier = $this->create_supplier();

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( 'Spend Summary', $html );
		$this->assertStringContainsString( 'No committed purchase orders yet for this supplier.', $html );
	}

	/**
	 * A supplier whose only POs are draft/cancelled also shows the empty
	 * state -- BR-M15-1's exclusion applies at the presentation layer too.
	 */
	public function test_draft_and_cancelled_only_shows_empty_state() {
		$supplier = $this->create_supplier();

		$draft = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		$this->add_po_line( $draft['id'] );

		$cancelled = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $cancelled['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::CANCELLED ) );
		$this->add_po_line( $cancelled['id'] );

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( 'No committed purchase orders yet for this supplier.', $html );
	}

	/**
	 * A supplier with a committed PO renders the Spend Summary table with
	 * correct currency, ordered/received totals, and committed-PO count.
	 */
	public function test_renders_totals_for_committed_po() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'USD',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$line = $this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 2,
				'unit_cost'   => 30.0,
			)
		);
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $line['id'], 1 );

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$this->assertStringContainsString( 'Spend Summary', $html );
		$this->assertStringContainsString( '60.00 USD', $html );
		$this->assertStringContainsString( '30.00 USD', $html );
		// One committed PO contributing to the USD row.
		$this->assertMatchesRegularExpression( '/USD.*?60\.00 USD.*?30\.00 USD.*?<td>1<\/td>/s', $html );
	}

	/**
	 * A draft PO's value never appears in the totals, even though a
	 * committed PO for the same supplier does.
	 */
	public function test_draft_po_value_excluded_from_totals() {
		$supplier = $this->create_supplier();

		$draft = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		$this->add_po_line(
			$draft['id'],
			array(
				'qty_ordered' => 100,
				'unit_cost'   => 999.0,
			)
		);

		$placed = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $placed['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$placed['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 10.0,
			)
		);

		$html    = $this->render_supplier_detail_html( $supplier['id'] );
		$section = $this->spend_summary_section_html( $html );

		$this->assertStringContainsString( '10.00 EUR', $section );
		$this->assertStringNotContainsString( '99,900.00', $section, 'The draft PO\'s value must not appear inside the Spend Summary section (it legitimately appears elsewhere, in the status-inclusive Order History section).' );
	}

	/**
	 * Two committed POs in different currencies render two separate rows,
	 * never a blended total.
	 */
	public function test_multiple_currencies_never_blended_into_a_total() {
		$supplier = $this->create_supplier();

		$po_eur = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po_eur['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$po_eur['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 100.0,
			)
		);

		$po_usd = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'USD',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po_usd['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ) );
		$this->add_po_line(
			$po_usd['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 50.0,
			)
		);

		$html    = $this->render_supplier_detail_html( $supplier['id'] );
		$section = $this->spend_summary_section_html( $html );

		$this->assertStringContainsString( '100.00 EUR', $section );
		$this->assertStringContainsString( '50.00 USD', $section );
		// Never a blended 150.00 total anywhere in the Spend Summary section.
		$this->assertStringNotContainsString( '150.00', $section );
	}

	/**
	 * Section ordering: Spend Summary appears before Observed Lead Time,
	 * which appears before Order History (unchanged M14 ordering).
	 */
	public function test_section_ordering() {
		$supplier = $this->create_supplier();

		$html = $this->render_supplier_detail_html( $supplier['id'] );

		$spend_pos     = strpos( $html, 'Spend Summary' );
		$lead_time_pos = strpos( $html, 'Observed Lead Time' );
		$history_pos   = strpos( $html, 'Order History' );

		$this->assertNotFalse( $spend_pos );
		$this->assertNotFalse( $lead_time_pos );
		$this->assertNotFalse( $history_pos );
		$this->assertLessThan( $lead_time_pos, $spend_pos, 'Spend Summary must render before Observed Lead Time.' );
		$this->assertLessThan( $history_pos, $lead_time_pos, 'Observed Lead Time must render before Order History.' );
	}

	/**
	 * The pre-existing manage_woocommerce gate (unchanged by M15) still
	 * denies the whole Supplier detail screen -- including the new Spend
	 * Summary section -- to a user without the capability. See
	 * test-supplier-order-history-admin.php's identically-named test for
	 * the full explanation of the DOING_AJAX-related die-handler forcing
	 * below (unchanged technique, this file just needs its own copy since
	 * PHPUnit test classes can't share private test methods).
	 */
	public function test_capability_gate_denies_unauthorized_user() {
		$supplier   = $this->create_supplier();
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$_GET = array(
			'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
			'action'      => 'edit',
			'supplier_id' => (string) $supplier['id'],
		);

		$force_throwing_die_handler = static function () {
			return static function ( $message, $title = '', $args = array() ) {
				throw new WPDieException( is_string( $message ) ? $message : 'wp_die' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $message is wp_die()'s own message argument being re-thrown as a test exception, not output.
			};
		};
		add_filter( 'wp_die_handler', $force_throwing_die_handler, PHP_INT_MAX );
		add_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );

		try {
			ob_start();
			WC_Inventory_Overview_Purchasing_Page::instance()->render_page();
			$html = ob_get_clean();
			$this->fail( 'Expected the capability gate to deny this user.' );
		} catch ( WPDieException $e ) {
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'Spend Summary', $html );
		} finally {
			remove_filter( 'wp_die_handler', $force_throwing_die_handler, PHP_INT_MAX );
			remove_filter( 'wp_die_ajax_handler', $force_throwing_die_handler, PHP_INT_MAX );
		}
	}
}
