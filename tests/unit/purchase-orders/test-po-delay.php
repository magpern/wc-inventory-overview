<?php
/**
 * M2-B: delay calculator truth table and SQL equivalence.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Delay detection tests.
 */
class Test_WC_IO_PO_Delay extends WP_UnitTestCase {

	/**
	 * Core delay truth table for a single line.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function delay_cases(): array {
		return array(
			// status, outstanding, date, confidence, grace, today, expected.
			array( 'placed', 1.0, '2026-08-01', 'estimated', 0, '2026-08-05', true ),
			array( 'placed', 1.0, '2026-08-01', 'exact', 0, '2026-08-05', true ),
			array( 'placed', 1.0, '2026-08-01', 'unknown', 0, '2026-08-05', false ),
			array( 'placed', 0.0, '2026-08-01', 'estimated', 0, '2026-08-05', false ),
			array( 'draft', 1.0, '2026-08-01', 'estimated', 0, '2026-08-05', false ),
			array( 'cancelled', 1.0, '2026-08-01', 'estimated', 0, '2026-08-05', false ),
			array( 'closed_short', 1.0, '2026-08-01', 'estimated', 0, '2026-08-05', false ),
			array( 'placed', 1.0, null, 'estimated', 0, '2026-08-05', false ),
			array( 'placed', 1.0, '2026-08-10', 'estimated', 0, '2026-08-05', false ),
			// Grace boundary: deadline = date+grace; delayed only when deadline < today.
			array( 'placed', 1.0, '2026-08-04', 'estimated', 1, '2026-08-05', false ), // 4+1=5 not < 5.
			array( 'placed', 1.0, '2026-08-03', 'estimated', 1, '2026-08-05', true ),  // 3+1=4 < 5.
			array( 'placed', 1.0, '2026-08-05', 'estimated', 0, '2026-08-05', false ), // equal not delayed.
		);
	}

	/**
	 * PHP delay truth table.
	 */
	public function test_line_delay_truth_table() {
		foreach ( $this->delay_cases() as $i => $case ) {
			list( $status, $outstanding, $date, $confidence, $grace, $today, $expected ) = $case;
			$actual = WC_Inventory_Overview_PO_Delay::is_line_delayed(
				$status,
				$outstanding,
				$date,
				$confidence,
				$grace,
				$today
			);
			$this->assertSame( $expected, $actual, 'case ' . $i );
		}
	}

	/**
	 * Grace days option defaults to zero.
	 */
	public function test_grace_option_default() {
		delete_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS );
		$this->assertSame( 0, WC_Inventory_Overview_PO_Delay::grace_days_from_option() );
		update_option( WC_Inventory_Overview_PO_Delay::OPTION_GRACE_DAYS, 3 );
		$this->assertSame( 3, WC_Inventory_Overview_PO_Delay::grace_days_from_option() );
	}

	/**
	 * PO is delayed when any line is delayed.
	 */
	public function test_po_delayed_when_any_line_delayed() {
		$lines = array(
			array(
				'qty_ordered'         => 1,
				'qty_cancelled'       => 0,
				'expected_date'       => null,
				'expected_confidence' => null,
			),
			array(
				'qty_ordered'         => 2,
				'qty_cancelled'       => 0,
				'expected_date'       => '2026-07-01',
				'expected_confidence' => 'exact',
			),
		);
		$this->assertTrue(
			WC_Inventory_Overview_PO_Delay::is_po_delayed(
				'placed',
				'2026-09-01',
				'estimated',
				$lines,
				0,
				'2026-08-05'
			)
		);
		$this->assertFalse(
			WC_Inventory_Overview_PO_Delay::is_po_delayed(
				'placed',
				'2026-09-01',
				'unknown',
				array(
					array(
						'qty_ordered'   => 1,
						'qty_cancelled' => 0,
					),
				),
				0,
				'2026-08-05'
			)
		);
	}

	/**
	 * PHP and SQL delay predicates agree on the truth table (integration).
	 */
	public function test_php_sql_delay_equivalence() {
		global $wpdb;

		WC_Inventory_Overview_Install::create_tables();
		delete_option( WC_Inventory_Overview_PO_Numbering::OPTION_KEY );

		$po_table   = WC_Inventory_Overview_Purchase_Orders::table_name();
		$line_table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();

		foreach ( $this->delay_cases() as $i => $case ) {
			list( $status, $outstanding, $date, $confidence, $grace, $today, $expected ) = $case;

			$qty_ordered   = max( $outstanding, 1.0 );
			$qty_cancelled = $qty_ordered - $outstanding;

			$wpdb->query( "DELETE FROM {$line_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$po_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$wpdb->insert(
				$po_table,
				array(
					'po_number'           => sprintf( 'PO-TEST-%04d', $i ),
					'supplier_id'         => 1,
					'currency'            => 'EUR',
					'status'              => $status,
					'expected_date'       => $date,
					'expected_confidence' => $confidence,
					'created_at'          => current_time( 'mysql', true ),
					'updated_at'          => current_time( 'mysql', true ),
				)
			);
			$po_id = (int) $wpdb->insert_id;

			$wpdb->insert(
				$line_table,
				array(
					'po_id'               => $po_id,
					'line_index'          => 0,
					'product_id'          => 1,
					'qty_ordered'         => $qty_ordered,
					'qty_cancelled'       => $qty_cancelled,
					'unit_cost'           => 1,
					'currency'            => 'EUR',
					'expected_date'       => null,
					'expected_confidence' => null,
					'status'              => 'open',
					'created_at'          => current_time( 'mysql', true ),
					'updated_at'          => current_time( 'mysql', true ),
				)
			);

			$php = WC_Inventory_Overview_PO_Delay::is_line_delayed(
				$status,
				(float) $outstanding,
				$date,
				$confidence,
				$grace,
				$today
			);

			$exists     = WC_Inventory_Overview_PO_Delay::sql_po_is_delayed_exists( 'po', $grace, $today );
			$sql        = "SELECT {$exists} FROM {$po_table} po WHERE po.id = %d";
			$sql_result = (bool) $wpdb->get_var( $wpdb->prepare( $sql, $po_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

			$this->assertSame( $expected, $php, 'PHP case ' . $i );
			$this->assertSame( $expected, $sql_result, 'SQL case ' . $i );
			$this->assertSame( $php, $sql_result, 'PHP/SQL equivalence case ' . $i );
		}
	}
}
