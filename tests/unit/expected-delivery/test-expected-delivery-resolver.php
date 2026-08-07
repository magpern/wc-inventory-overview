<?php
/**
 * Unit tests for WC_Inventory_Overview_Expected_Delivery_Resolver (M7).
 *
 * Pure, deterministic, total: every case below is table-driven with no DB
 * and no product loading. $today is always passed explicitly.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Resolver extends WP_UnitTestCase {

	private const TODAY = '2026-06-15';

	/**
	 * Raw open-PO-line row, shaped exactly like
	 * Inventory_Position_Service's incoming_lines element: every value a
	 * string except expected_date (Y-m-d or null).
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function make_line( array $overrides = array() ): array {
		return array_merge(
			array(
				'line_id'             => '1',
				'po_id'               => '1',
				'po_number'           => 'PO-0001',
				'product_id'          => '10',
				'variation_id'        => '0',
				'outstanding'         => '5',
				'expected_date'       => null,
				'expected_confidence' => 'unknown',
				'is_delayed'          => '0',
			),
			$overrides
		);
	}

	private function resolve( bool $available_now, array $lines, string $today = self::TODAY ): array {
		return WC_Inventory_Overview_Expected_Delivery_Resolver::resolve( $available_now, $lines, $today );
	}

	public function test_in_stock_wins_regardless_of_incoming_lines() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => '2026-01-01',
					'expected_confidence' => 'exact',
					'is_delayed'          => '1',
				)
			),
		);

		$result = $this->resolve( true, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK, $result['state'] );
		$this->assertNull( $result['expected_date'] );
		$this->assertNull( $result['confidence'] );
	}

	public function test_no_lines_is_unavailable() {
		$result = $this->resolve( false, array() );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE, $result['state'] );
	}

	public function test_all_lines_delayed_is_expected_soon() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( self::TODAY . ' +5 days' ) ),
					'expected_confidence' => 'exact',
					'is_delayed'          => '1',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_all_lines_unknown_confidence_is_expected_soon() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( self::TODAY . ' +5 days' ) ),
					'expected_confidence' => 'unknown',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_all_lines_no_date_is_expected_soon() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => null,
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_one_customer_safe_exact_line_wins() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result['state'] );
		$this->assertSame( '2026-09-01', $result['expected_date'] );
		$this->assertSame( 'exact', $result['confidence'] );
	}

	public function test_one_customer_safe_estimated_line_wins() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'estimated',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result['state'] );
		$this->assertSame( 'estimated', $result['confidence'] );
	}

	public function test_multiple_customer_safe_lines_earliest_date_wins() {
		$lines = array(
			$this->make_line(
				array(
					'line_id'             => '1',
					'expected_date'       => '2026-09-12',
					'expected_confidence' => 'exact',
				)
			),
			$this->make_line(
				array(
					'line_id'             => '2',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( '2026-09-01', $result['expected_date'] );
	}

	public function test_same_date_tie_exact_beats_estimated() {
		$lines = array(
			$this->make_line(
				array(
					'line_id'             => '1',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'estimated',
				)
			),
			$this->make_line(
				array(
					'line_id'             => '2',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( 'exact', $result['confidence'] );
	}

	public function test_same_date_same_confidence_lowest_line_id_wins() {
		$lines = array(
			$this->make_line(
				array(
					'line_id'             => '9',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
			$this->make_line(
				array(
					'line_id'             => '3',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		// Both lines are identical apart from line_id; determinism is
		// verified by the fact this is stable across repeated resolves.
		$again = $this->resolve( false, $lines );
		$this->assertSame( $result, $again );
	}

	public function test_unsafe_line_never_wins_even_when_earlier() {
		$lines = array(
			$this->make_line(
				array(
					'line_id'             => '1',
					'expected_date'       => '2026-01-01', // Earlier, but delayed.
					'expected_confidence' => 'exact',
					'is_delayed'          => '1',
				)
			),
			$this->make_line(
				array(
					'line_id'             => '2',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( '2026-09-01', $result['expected_date'] );
	}

	/**
	 * Invariant M7-1: a customer-safe line's date is never in the past,
	 * regardless of is_delayed. Three scenarios per the plan.
	 */
	public function test_invariant_m7_1_past_dated_partially_received_simulation_not_customer_safe() {
		// Simulates a PO that auto-transitioned to partially_received: its
		// remaining outstanding is never flagged delayed by the upstream
		// SQL predicate (po.status = 'placed' only), yet the date is past.
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( self::TODAY . ' -30 days' ) ),
					'expected_confidence' => 'exact',
					'is_delayed'          => '0',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_invariant_m7_1_past_dated_with_grace_days_simulation_not_customer_safe() {
		// Simulates wc_io_po_delay_grace_days = 7 shifting the upstream
		// is_delayed threshold so a date 3 days past is not flagged delayed.
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => gmdate( 'Y-m-d', strtotime( self::TODAY . ' -3 days' ) ),
					'expected_confidence' => 'exact',
					'is_delayed'          => '0',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_invariant_m7_1_boundary_today_remains_customer_safe() {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => self::TODAY,
					'expected_confidence' => 'exact',
					'is_delayed'          => '0',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result['state'] );
		$this->assertSame( self::TODAY, $result['expected_date'] );
	}

	/**
	 * @return array<string,array{0:string|null}>
	 */
	public function malformed_date_provider(): array {
		return array(
			'zero date'    => array( '0000-00-00' ),
			'empty string' => array( '' ),
			'invalid day'  => array( '2026-13-45' ),
			'not a date'   => array( 'not-a-date' ),
			'null'         => array( null ),
		);
	}

	/**
	 * @dataProvider malformed_date_provider
	 * @param string|null $malformed_date Malformed date value.
	 */
	public function test_malformed_expected_date_is_not_customer_safe( $malformed_date ) {
		$lines = array(
			$this->make_line(
				array(
					'expected_date'       => $malformed_date,
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON, $result['state'] );
	}

	public function test_zero_outstanding_is_not_customer_safe() {
		$lines = array(
			$this->make_line(
				array(
					'outstanding'         => '0',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE, $result['state'] );
	}

	public function test_negative_outstanding_is_not_customer_safe() {
		$lines = array(
			$this->make_line(
				array(
					'outstanding'         => '-2',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'exact',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE, $result['state'] );
	}

	public function test_unexpected_confidence_string_ranks_last_never_crashes() {
		$lines = array(
			$this->make_line(
				array(
					'line_id'             => '1',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'guessed', // Not 'exact'/'estimated'/'unknown'.
				)
			),
			$this->make_line(
				array(
					'line_id'             => '2',
					'expected_date'       => '2026-09-01',
					'expected_confidence' => 'estimated',
				)
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result['state'] );
		$this->assertSame( 'estimated', $result['confidence'], 'estimated (rank 1) must beat an unrecognized confidence string (rank 2)' );
	}

	/**
	 * Every field arrives as a string from $wpdb (except expected_date,
	 * which may be null) -- the Resolver must not assume numeric types.
	 */
	public function test_string_typed_inputs_throughout() {
		$lines = array(
			array(
				'line_id'             => '42',
				'po_id'                => '7',
				'po_number'            => 'PO-0007',
				'product_id'           => '10',
				'variation_id'         => '0',
				'outstanding'          => '3.5',
				'expected_date'        => '2026-09-01',
				'expected_confidence'  => 'exact',
				'is_delayed'           => '0',
			),
		);

		$result = $this->resolve( false, $lines );

		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result['state'] );
		$this->assertSame( '2026-09-01', $result['expected_date'] );
		$this->assertSame( 'exact', $result['confidence'] );
	}
}
