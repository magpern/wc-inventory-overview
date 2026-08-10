<?php
/**
 * M14: Purchase_Orders::values_bulk() — bulk ordered/received value per PO.
 *
 * @package WC_Inventory_Overview_Tests
 */

// phpcs:disable WordPress.Files.FileName -- PHPUnit test class naming convention.

/**
 * values_bulk() correctness/edge cases.
 */
class Test_WC_IO_PO_Values_Bulk extends WC_Inventory_Overview_Test_Case {

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
	 * Empty input returns an empty array without issuing SQL.
	 */
	public function test_empty_array_returns_empty_array_without_query() {
		global $wpdb;
		$before = $wpdb->num_queries;
		$result = WC_Inventory_Overview_Purchase_Orders::values_bulk( array() );
		$this->assertSame( array(), $result );
		$this->assertSame( $before, $wpdb->num_queries, 'values_bulk( [] ) must not issue any SQL query.' );
	}

	/**
	 * Single PO, single line: ordered/received formulas match qty × unit_cost.
	 */
	public function test_single_po_single_line_formula() {
		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 10,
				'unit_cost'   => 4.5,
			)
		);
		$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po['id'] );
		WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $lines[0]['id'], 6 );

		$result = WC_Inventory_Overview_Purchase_Orders::values_bulk( array( $po['id'] ) );

		$this->assertArrayHasKey( $po['id'], $result );
		$this->assertDecimalEqual( 45.0, $result[ $po['id'] ]['ordered'] );
		$this->assertDecimalEqual( 27.0, $result[ $po['id'] ]['received'] );
	}

	/**
	 * Multiple lines on one PO sum within that PO only.
	 */
	public function test_multiple_lines_sum_within_one_po() {
		$po = $this->create_purchase_order();
		$this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 5,
				'unit_cost'   => 2.0,
			)
		);
		$this->add_po_line(
			$po['id'],
			array(
				'qty_ordered' => 3,
				'unit_cost'   => 10.0,
			)
		);

		$result = WC_Inventory_Overview_Purchase_Orders::values_bulk( array( $po['id'] ) );

		$this->assertDecimalEqual( 40.0, $result[ $po['id'] ]['ordered'] );
		$this->assertDecimalEqual( 0.0, $result[ $po['id'] ]['received'] );
	}

	/**
	 * Two POs, including different currencies: each PO's value stays
	 * independent — values_bulk() never sums across POs (INV-M14-2).
	 */
	public function test_multiple_pos_never_summed_together_even_across_currencies() {
		$po_eur = $this->create_purchase_order( array( 'currency' => 'EUR' ) );
		$this->add_po_line(
			$po_eur['id'],
			array(
				'qty_ordered' => 2,
				'unit_cost'   => 100.0,
			)
		);

		$po_usd = $this->create_purchase_order( array( 'currency' => 'USD' ) );
		$this->add_po_line(
			$po_usd['id'],
			array(
				'qty_ordered' => 1,
				'unit_cost'   => 50.0,
			)
		);

		$result = WC_Inventory_Overview_Purchase_Orders::values_bulk( array( $po_eur['id'], $po_usd['id'] ) );

		$this->assertCount( 2, $result );
		$this->assertDecimalEqual( 200.0, $result[ $po_eur['id'] ]['ordered'] );
		$this->assertDecimalEqual( 50.0, $result[ $po_usd['id'] ]['ordered'] );
	}

	/**
	 * A PO id with no lines is simply absent from the result (caller treats
	 * missing keys as 0.0/0.0 — see Supplier_Order_History_Service).
	 */
	public function test_po_with_no_lines_is_absent_from_result() {
		$po = $this->create_purchase_order();

		$result = WC_Inventory_Overview_Purchase_Orders::values_bulk( array( $po['id'] ) );

		$this->assertArrayNotHasKey( $po['id'], $result );
	}

	/**
	 * A single grouped query regardless of how many POs are requested — no N+1.
	 */
	public function test_one_query_regardless_of_po_count() {
		global $wpdb;
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$po = $this->create_purchase_order();
			$this->add_po_line( $po['id'] );
			$ids[] = $po['id'];
		}

		$before = $wpdb->num_queries;
		WC_Inventory_Overview_Purchase_Orders::values_bulk( $ids );
		$after = $wpdb->num_queries;

		$this->assertSame( 1, $after - $before, 'values_bulk() must issue exactly one query regardless of PO count.' );
	}
}
