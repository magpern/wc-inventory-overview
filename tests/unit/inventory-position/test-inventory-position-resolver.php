<?php
/**
 * Unit tests for WC_Inventory_Overview_Inventory_Position_Resolver (M3, D11).
 *
 * Pure calculator: Position = On Hand + Incoming. No $wpdb, no product
 * loading, no PO repository access.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Inventory_Position_Resolver extends WP_UnitTestCase {

	/**
	 * Zero On Hand and zero Incoming resolve to zero Position, not delayed.
	 */
	public function test_zero_on_hand_zero_incoming() {
		$result = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 0.0, 0.0, false );

		$this->assertSame( 0.0, $result['on_hand'] );
		$this->assertSame( 0.0, $result['incoming'] );
		$this->assertSame( 0.0, $result['position'] );
		$this->assertFalse( $result['incoming_delayed'] );
	}

	/**
	 * Positive On Hand and Incoming sum exactly into Position.
	 */
	public function test_positive_values_sum_to_position() {
		$result = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 12.0, 5.0, false );

		$this->assertSame( 12.0, $result['on_hand'] );
		$this->assertSame( 5.0, $result['incoming'] );
		$this->assertSame( 17.0, $result['position'] );
	}

	/**
	 * Decimal precision is preserved through the calculation.
	 */
	public function test_decimal_precision_preserved() {
		$result = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 3.25, 1.75, false );

		$this->assertSame( 5.0, $result['position'] );

		$result2 = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 3.1, 2.2, false );
		$this->assertEqualsWithDelta( 5.3, $result2['position'], 0.0001 );
	}

	/**
	 * incoming_delayed is propagated through untouched, both ways.
	 */
	public function test_delayed_flag_propagation() {
		$delayed = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 1.0, 2.0, true );
		$this->assertTrue( $delayed['incoming_delayed'] );

		$not_delayed = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 1.0, 2.0, false );
		$this->assertFalse( $not_delayed['incoming_delayed'] );
	}

	/**
	 * Position is always exactly on_hand + incoming, including negative-looking
	 * edge cases the caller should never actually pass (still exercised for
	 * arithmetic correctness only).
	 */
	public function test_exact_position_calculation() {
		$result = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 100.5, 49.25, true );
		$this->assertSame( 149.75, $result['position'] );
	}

	/**
	 * Return shape is exactly the four contracted keys — no extra fields.
	 */
	public function test_return_shape_is_exactly_contracted_keys() {
		$result = WC_Inventory_Overview_Inventory_Position_Resolver::resolve( 1.0, 1.0, false );
		$this->assertEqualsCanonicalizing(
			array( 'on_hand', 'incoming', 'position', 'incoming_delayed' ),
			array_keys( $result )
		);
	}
}
