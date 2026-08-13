<?php
/**
 * Unit tests for WC_Inventory_Overview_Reorder_Signal_Resolver (M21, BR-M21-2).
 *
 * Pure classifier: needs_reorder = (position <= threshold);
 * covered_by_incoming = ! needs_reorder. No $wpdb, no product loading, no
 * repository access.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Reorder_Signal_Resolver extends WP_UnitTestCase {

	/**
	 * Position strictly below threshold needs reorder.
	 */
	public function test_position_below_threshold_needs_reorder() {
		$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 1.0, 3.0 );

		$this->assertTrue( $result['needs_reorder'] );
		$this->assertFalse( $result['covered_by_incoming'] );
	}

	/**
	 * Position equal to threshold still needs reorder (BR-M21-2 uses <=, not <).
	 */
	public function test_position_equal_to_threshold_needs_reorder() {
		$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 3.0, 3.0 );

		$this->assertTrue( $result['needs_reorder'] );
		$this->assertFalse( $result['covered_by_incoming'] );
	}

	/**
	 * Position above threshold is covered by incoming, not needing reorder.
	 */
	public function test_position_above_threshold_covered_by_incoming() {
		$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 4.0, 3.0 );

		$this->assertFalse( $result['needs_reorder'] );
		$this->assertTrue( $result['covered_by_incoming'] );
	}

	/**
	 * Zero threshold: any positive position covers it; zero position still needs reorder.
	 */
	public function test_zero_threshold() {
		$covered = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 1.0, 0.0 );
		$this->assertFalse( $covered['needs_reorder'] );
		$this->assertTrue( $covered['covered_by_incoming'] );

		$needs = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 0.0, 0.0 );
		$this->assertTrue( $needs['needs_reorder'] );
		$this->assertFalse( $needs['covered_by_incoming'] );
	}

	/**
	 * Zero position (no incoming at all) against a positive threshold needs reorder.
	 */
	public function test_zero_position_against_positive_threshold() {
		$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 0.0, 5.0 );

		$this->assertTrue( $result['needs_reorder'] );
		$this->assertFalse( $result['covered_by_incoming'] );
	}

	/**
	 * The two flags are always mutually exclusive and exhaustive across a range of values.
	 */
	public function test_flags_are_always_mutually_exclusive_and_exhaustive() {
		$cases = array(
			array( 0.0, 0.0 ),
			array( 0.0, 10.0 ),
			array( 10.0, 0.0 ),
			array( 2.5, 2.5 ),
			array( 2.4999, 2.5 ),
			array( 2.5001, 2.5 ),
			array( 100.0, 3.0 ),
		);

		foreach ( $cases as $case ) {
			$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( $case[0], $case[1] );
			$this->assertNotSame(
				$result['needs_reorder'],
				$result['covered_by_incoming'],
				sprintf( 'position=%s threshold=%s must yield exactly one true flag', $case[0], $case[1] )
			);
		}
	}

	/**
	 * Return shape is exactly the two contracted keys — no extra fields.
	 */
	public function test_return_shape_is_exactly_contracted_keys() {
		$result = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( 1.0, 1.0 );
		$this->assertEqualsCanonicalizing(
			array( 'needs_reorder', 'covered_by_incoming' ),
			array_keys( $result )
		);
	}
}
