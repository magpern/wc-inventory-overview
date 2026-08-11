<?php
/**
 * M15: WC_Inventory_Overview_Supplier_Spend_Service — thin pass-through
 * over Purchase_Orders::spend_summary_for_supplier() owning the
 * committed-status business rule (BR-M15-1).
 *
 * Formula/currency-grouping/po_count correctness is proven exhaustively in
 * tests/unit/purchase-orders/test-po-spend-summary.php against the read
 * owner directly -- this file proves the service delegates correctly and
 * applies the right status set, not the arithmetic again.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit test class naming convention.

/**
 * Correctness of get_summary() via the service layer.
 */
class Test_WC_IO_Supplier_Spend_Service extends WC_Inventory_Overview_Test_Case {

	/**
	 * Reset schema and clear PO tables for isolation.
	 */
	public function setUp(): void {
		parent::setUp();
		WC_Inventory_Overview_Install::create_tables();
		$this->purge_po_tables();
	}

	/**
	 * Truncate PO aggregate tables and suppliers so ids stay predictable.
	 */
	private function purge_po_tables(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_PO_Events::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Order_Lines::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Purchase_Orders::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . WC_Inventory_Overview_Suppliers::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * The committed-status constant (BR-M15-1) is exactly the four
	 * "actually placed" statuses -- draft and cancelled are absent.
	 */
	public function test_committed_statuses_excludes_draft_and_cancelled() {
		$statuses = WC_Inventory_Overview_Supplier_Spend_Service::committed_statuses();

		$this->assertContains( WC_Inventory_Overview_PO_Statuses::PLACED, $statuses );
		$this->assertContains( WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED, $statuses );
		$this->assertContains( WC_Inventory_Overview_PO_Statuses::RECEIVED, $statuses );
		$this->assertContains( WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT, $statuses );
		$this->assertNotContains( WC_Inventory_Overview_PO_Statuses::DRAFT, $statuses );
		$this->assertNotContains( WC_Inventory_Overview_PO_Statuses::CANCELLED, $statuses );
	}

	/**
	 * A supplier with zero POs: empty result (BR-M15-4 empty-state
	 * precondition).
	 */
	public function test_zero_pos_returns_empty_array() {
		$supplier = $this->create_supplier();

		$result = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( (int) $supplier['id'] );

		$this->assertSame( array(), $result );
	}

	/**
	 * A supplier with only draft/cancelled POs: empty result -- the
	 * service's status filter, not the read owner's, is what excludes them
	 * here (proving the service actually applies committed_statuses()).
	 */
	public function test_draft_and_cancelled_only_supplier_returns_empty_array() {
		$supplier = $this->create_supplier();

		$draft = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		$this->add_po_line( $draft['id'] );

		$cancelled = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $cancelled['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::CANCELLED ) );
		$this->add_po_line( $cancelled['id'] );

		$result = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( (int) $supplier['id'] );

		$this->assertSame( array(), $result );
	}

	/**
	 * A single-currency committed supplier: one row, correct totals.
	 */
	public function test_single_currency_supplier() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::RECEIVED ) );
		$line = $this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 4,
				'unit_cost'   => 25.0,
			)
		);
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $line['id'], 4 );

		$result = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( (int) $supplier['id'] );

		$this->assertCount( 1, $result );
		$this->assertSame( 'EUR', $result[0]['currency'] );
		$this->assertDecimalEqual( 100.0, $result[0]['ordered_total'] );
		$this->assertDecimalEqual( 100.0, $result[0]['received_total'] );
		$this->assertSame( 1, $result[0]['po_count'] );
	}

	/**
	 * A multi-currency committed supplier: rows never blended -- proves the
	 * service passes the full row set through unchanged (no aggregation of
	 * its own).
	 */
	public function test_multi_currency_supplier_rows_never_blended() {
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
				'unit_cost'   => 10.0,
			)
		);

		$po_sek = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'SEK',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po_sek['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED ) );
		$this->add_po_line(
			$po_sek['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 20.0,
			)
		);

		$result  = WC_Inventory_Overview_Supplier_Spend_Service::get_summary( (int) $supplier['id'] );
		$by_curr = array();
		foreach ( $result as $row ) {
			$by_curr[ $row['currency'] ] = $row;
		}

		$this->assertCount( 2, $result );
		$this->assertDecimalEqual( 10.0, $by_curr['EUR']['ordered_total'] );
		$this->assertDecimalEqual( 20.0, $by_curr['SEK']['ordered_total'] );
	}
}
