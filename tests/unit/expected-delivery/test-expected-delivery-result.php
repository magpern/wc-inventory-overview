<?php
/**
 * Unit tests for WC_Inventory_Overview_Expected_Delivery_Result (M7, API v1).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Delivery_Result extends WP_UnitTestCase {

	/**
	 * The concrete class implements the interface contract.
	 */
	public function test_result_implements_the_interface() {
		$this->assertTrue(
			in_array(
				WC_Inventory_Overview_Expected_Delivery_Result_Interface::class,
				class_implements( WC_Inventory_Overview_Expected_Delivery_Result::class ),
				true
			)
		);
	}

	/**
	 * The interface declares exactly the four documented states.
	 */
	public function test_interface_declares_the_four_states() {
		$this->assertSame( 'in_stock', WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK );
		$this->assertSame( 'unavailable', WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE );
		$this->assertSame( 'expected_date', WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE );
		$this->assertSame( 'expected_soon', WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON );

		$states = array(
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON,
		);
		$this->assertCount( 4, array_unique( $states ), 'The four STATE_* constants must be distinct' );
	}

	/**
	 * Immutable: no public properties, private constructor.
	 */
	public function test_result_is_immutable() {
		$reflection = new ReflectionClass( WC_Inventory_Overview_Expected_Delivery_Result::class );

		foreach ( $reflection->getProperties() as $property ) {
			$this->assertTrue( $property->isPrivate(), 'Property ' . $property->getName() . ' must be private' );
		}

		$this->assertTrue( $reflection->getConstructor()->isPrivate(), 'Constructor must be private' );
	}

	/**
	 * create() + accessor round-trip: every value passed in comes back out unchanged.
	 */
	public function test_create_and_accessor_round_trip() {
		$result = WC_Inventory_Overview_Expected_Delivery_Result::create(
			1,
			false,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE,
			'2026-09-01',
			'exact'
		);

		$this->assertSame( 1, $result->api_version() );
		$this->assertFalse( $result->available_now() );
		$this->assertSame( WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_DATE, $result->state() );
		$this->assertSame( '2026-09-01', $result->expected_date() );
		$this->assertSame( 'exact', $result->confidence() );
	}

	/**
	 * api_version() reports exactly what the caller passed at create() time
	 * (the Service, in production) -- a Result always self-describes the
	 * contract it was built under.
	 */
	public function test_api_version_echoes_what_was_passed_in() {
		$result = WC_Inventory_Overview_Expected_Delivery_Result::create( 7, true, WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK, null, null );

		$this->assertSame( 7, $result->api_version() );
	}

	/**
	 * expected_date()/confidence() are null in all three non-date states.
	 */
	public function test_expected_date_and_confidence_are_null_outside_expected_date_state() {
		$non_date_states = array(
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_IN_STOCK,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_UNAVAILABLE,
			WC_Inventory_Overview_Expected_Delivery_Result_Interface::STATE_EXPECTED_SOON,
		);

		foreach ( $non_date_states as $state ) {
			$result = WC_Inventory_Overview_Expected_Delivery_Result::create( 1, false, $state, null, null );
			$this->assertNull( $result->expected_date(), $state . ' must carry a null expected_date()' );
			$this->assertNull( $result->confidence(), $state . ' must carry a null confidence()' );
		}
	}
}
