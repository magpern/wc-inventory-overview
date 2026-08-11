<?php
/**
 * M15: Purchase_Orders::spend_summary_for_supplier() — per-currency spend
 * aggregate for a supplier's committed Purchase Orders.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit test class naming convention.

/**
 * Correctness/edge cases for spend_summary_for_supplier().
 */
class Test_WC_IO_PO_Spend_Summary extends WC_Inventory_Overview_Test_Case {

	/**
	 * Committed statuses (BR-M15-1), matching WC_Inventory_Overview_Supplier_Spend_Service.
	 *
	 * @return string[]
	 */
	private function committed_statuses(): array {
		return array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
		);
	}

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
	 * Convenience: index the returned rows by currency for easy assertion.
	 *
	 * @param array $rows Return of spend_summary_for_supplier().
	 * @return array<string,array>
	 */
	private function by_currency( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ $row['currency'] ] = $row;
		}
		return $out;
	}

	/**
	 * A supplier with no POs at all returns an empty array.
	 */
	public function test_zero_pos_returns_empty_array() {
		$supplier = $this->create_supplier();

		$result = WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() );

		$this->assertSame( array(), $result );
	}

	/**
	 * A single committed PO in one currency: ordered/received formulas match
	 * qty × unit_cost, po_count is 1 (BR-M15-3, BR-M15-5).
	 */
	public function test_single_currency_aggregation_formula() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$line = $this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 10,
				'unit_cost'   => 4.5,
			)
		);
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $line['id'], 6 );

		$result = $this->by_currency( WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() ) );

		$this->assertArrayHasKey( 'EUR', $result );
		$this->assertDecimalEqual( 45.0, $result['EUR']['ordered_total'] );
		$this->assertDecimalEqual( 27.0, $result['EUR']['received_total'] );
		$this->assertSame( 1, $result['EUR']['po_count'] );
	}

	/**
	 * Two committed POs in different currencies produce two independent
	 * rows, never blended (BR-M15-2, INV-M15-2).
	 */
	public function test_multi_currency_rows_never_blended() {
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
				'qty_ordered' => 2,
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

		$result = $this->by_currency( WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() ) );

		$this->assertCount( 2, $result );
		$this->assertDecimalEqual( 200.0, $result['EUR']['ordered_total'] );
		$this->assertDecimalEqual( 50.0, $result['USD']['ordered_total'] );
		$this->assertSame( 1, $result['EUR']['po_count'] );
		$this->assertSame( 1, $result['USD']['po_count'] );
	}

	/**
	 * Draft and cancelled POs never contribute to spend totals (BR-M15-1,
	 * INV-M15-1) even when a committed-status PO exists alongside them.
	 */
	public function test_draft_and_cancelled_excluded_from_totals() {
		$supplier = $this->create_supplier();

		$draft = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		$this->add_po_line(
			$draft['id'],
			array(
				'qty_ordered' => 100,
				'unit_cost'   => 999.0,
			)
		);
		// Left as draft — default status from create_draft().

		$cancelled = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $cancelled['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::CANCELLED ) );
		$this->add_po_line(
			$cancelled['id'],
			array(
				'qty_ordered' => 100,
				'unit_cost'   => 999.0,
			)
		);

		$placed = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $placed['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$placed['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 10.0,
			)
		);

		$result = $this->by_currency( WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() ) );

		$this->assertCount( 1, $result );
		$this->assertDecimalEqual( 10.0, $result['EUR']['ordered_total'] );
		$this->assertSame( 1, $result['EUR']['po_count'] );
	}

	/**
	 * A supplier whose only POs are draft/cancelled returns an empty array
	 * (BR-M15-4 empty state precondition).
	 */
	public function test_draft_only_supplier_returns_empty_array() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		$this->add_po_line( $po['id'] );

		$result = WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() );

		$this->assertSame( array(), $result );
	}

	/**
	 * A supplier whose only PO is cancelled returns an empty array.
	 */
	public function test_cancelled_only_supplier_returns_empty_array() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::CANCELLED ) );
		$this->add_po_line( $po['id'] );

		$result = WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() );

		$this->assertSame( array(), $result );
	}

	/**
	 * Mixed-line-currency fixture (BR-M15-5, required by the approved plan):
	 * one committed PO with an EUR line and a USD line contributes once to
	 * each currency's po_count, and each line's value stays in its own
	 * currency row -- never double-counted within a row, never combined
	 * across rows.
	 */
	public function test_mixed_line_currency_po_contributes_once_per_currency_row() {
		$supplier = $this->create_supplier();
		$po       = $this->create_purchase_order(
			array(
				'supplier_id' => $supplier['id'],
				'currency'    => 'EUR',
			)
		);
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );

		$eur_line = $this->add_po_line(
			$po['id'],
			array(
				'currency'    => 'EUR',
				'qty_ordered' => 2,
				'unit_cost'   => 100.0,
			)
		);
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $eur_line['id'], 2 );

		$usd_line = $this->add_po_line(
			$po['id'],
			array(
				'currency'    => 'USD',
				'qty_ordered' => 3,
				'unit_cost'   => 10.0,
			)
		);
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $usd_line['id'], 1 );

		$result = $this->by_currency( WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() ) );

		$this->assertCount( 2, $result, 'One committed PO with lines in two currencies must produce exactly two rows.' );

		// EUR row: only the EUR line's values; USD line never leaks in.
		$this->assertDecimalEqual( 200.0, $result['EUR']['ordered_total'] );
		$this->assertDecimalEqual( 200.0, $result['EUR']['received_total'] );
		$this->assertSame( 1, $result['EUR']['po_count'], 'The PO contributes po_count = 1 to the EUR row.' );

		// USD row: only the USD line's values; EUR line never leaks in.
		$this->assertDecimalEqual( 30.0, $result['USD']['ordered_total'] );
		$this->assertDecimalEqual( 10.0, $result['USD']['received_total'] );
		$this->assertSame( 1, $result['USD']['po_count'], 'The same PO contributes po_count = 1 to the USD row too -- not double-counted, not skipped.' );

		// The two rows' po_count values are never combined into a
		// supplier-wide total anywhere in the return shape (BR-M15-5) --
		// the return is a flat list of independent per-currency rows with
		// no synthesized "total_po_count" key.
		foreach ( $result as $row ) {
			$this->assertArrayNotHasKey( 'total_po_count', $row );
			$this->assertArrayNotHasKey( 'supplier_po_count', $row );
		}
	}

	/**
	 * A supplier's spend totals are scoped to that supplier only -- another
	 * supplier's committed POs never leak into the result.
	 */
	public function test_scoped_to_supplier_only() {
		$supplier_a = $this->create_supplier();
		$supplier_b = $this->create_supplier();

		$po_a = $this->create_purchase_order( array( 'supplier_id' => $supplier_a['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po_a['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$po_a['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 5.0,
			)
		);

		$po_b = $this->create_purchase_order( array( 'supplier_id' => $supplier_b['id'] ) );
		WC_Inventory_Overview_Purchase_Orders::update_fields( $po_b['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
		$this->add_po_line(
			$po_b['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 999.0,
			)
		);

		$result = $this->by_currency( WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier_a['id'], $this->committed_statuses() ) );

		$this->assertDecimalEqual( 5.0, $result['EUR']['ordered_total'] );
	}

	/**
	 * A single grouped query regardless of how many committed POs/lines the
	 * supplier has -- no N+1.
	 */
	public function test_one_query_regardless_of_po_count() {
		global $wpdb;
		$supplier = $this->create_supplier();
		for ( $i = 0; $i < 5; $i++ ) {
			$po = $this->create_purchase_order( array( 'supplier_id' => $supplier['id'] ) );
			WC_Inventory_Overview_Purchase_Orders::update_fields( $po['id'], array( 'status' => WC_Inventory_Overview_PO_Statuses::PLACED ) );
			$this->add_po_line( $po['id'] );
		}

		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], $this->committed_statuses() );
		$after = $wpdb->num_queries;

		$this->assertSame( 1, $after - $before, 'spend_summary_for_supplier() must issue exactly one query regardless of PO count.' );
	}

	/**
	 * An empty statuses argument returns an empty array without issuing SQL
	 * (defensive: the caller-supplied status list must never be empty in
	 * practice, but the method must not construct an invalid "IN ()" clause
	 * if it ever is).
	 */
	public function test_empty_statuses_returns_empty_array_without_query() {
		global $wpdb;
		$supplier = $this->create_supplier();

		$before = $wpdb->num_queries;
		$result = WC_Inventory_Overview_Purchase_Orders::spend_summary_for_supplier( (int) $supplier['id'], array() );
		$after  = $wpdb->num_queries;

		$this->assertSame( array(), $result );
		$this->assertSame( $before, $after, 'An empty statuses list must not issue any SQL query.' );
	}
}
