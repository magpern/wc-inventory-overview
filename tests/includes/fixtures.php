<?php
/**
 * Fixture helpers for WC Inventory Overview tests
 *
 * Provides utilities for loading, comparing, and managing golden fixtures.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Golden fixture management
 */
class WC_Inventory_Overview_Fixtures {

	/**
	 * Load a fixture file from the fixtures directory.
	 *
	 * @param string $fixture_path Path relative to tests/fixtures/.
	 * @return array|mixed The fixture data.
	 * @throws Exception If fixture file not found.
	 */
	public static function load( string $fixture_path ) {
		$file = dirname( __DIR__ ) . '/fixtures/' . ltrim( $fixture_path, '/' );

		if ( ! file_exists( $file ) ) {
			throw new Exception( "Fixture not found: {$file}" );
		}

		// Support both PHP and JSON fixture formats.
		if ( '.php' === substr( $file, -4 ) ) {
			return include $file;
		} elseif ( '.json' === substr( $file, -5 ) ) {
			return json_decode( file_get_contents( $file ), true );
		} else {
			throw new Exception( "Unsupported fixture format: {$file}" );
		}
	}

	/**
	 * Assert that actual output matches golden fixture value exactly.
	 *
	 * Per the fixture-governance rule (M0.14):
	 * - Never adjust expected values to make tests pass.
	 * - Expected values may only change when an approved milestone authorizes it.
	 * - Changes must be cited in the PR (milestone, architecture section, or ADR).
	 *
	 * @param mixed  $expected The expected golden value.
	 * @param mixed  $actual The actual value from production code.
	 * @param string $scenario The scenario name (for error reporting).
	 * @return void
	 * @throws AssertionError If values do not match.
	 */
	public static function assert_equals( $expected, $actual, string $scenario = '' ): void {
		if ( $expected !== $actual ) {
			$msg = "Golden fixture mismatch";
			if ( $scenario ) {
				$msg .= " ({$scenario})";
			}
			$msg .= ": expected " . self::value_to_string( $expected );
			$msg .= " but got " . self::value_to_string( $actual );
			throw new AssertionError( $msg );
		}
	}

	/**
	 * Assert that a decimal value matches a golden value to given precision.
	 *
	 * @param float  $expected The expected value.
	 * @param float  $actual The actual value.
	 * @param int    $decimals Number of decimal places (default 4).
	 * @param string $scenario Optional scenario name.
	 * @return void
	 * @throws AssertionError If values do not match.
	 */
	public static function assert_decimal_equals( float $expected, float $actual, int $decimals = 4, string $scenario = '' ): void {
		$expected_str = number_format( $expected, $decimals, '.', '' );
		$actual_str   = number_format( $actual, $decimals, '.', '' );

		if ( $expected_str !== $actual_str ) {
			$msg = "Decimal fixture mismatch (to {$decimals} places)";
			if ( $scenario ) {
				$msg .= " ({$scenario})";
			}
			$msg .= ": expected {$expected_str} but got {$actual_str}";
			throw new AssertionError( $msg );
		}
	}

	/**
	 * Convert a value to a human-readable string for error reporting.
	 *
	 * @param mixed $value The value to format.
	 * @return string A string representation.
	 */
	private static function value_to_string( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		} elseif ( is_null( $value ) ) {
			return 'null';
		} elseif ( is_array( $value ) ) {
			return 'array(' . count( $value ) . ')';
		} elseif ( is_float( $value ) || is_int( $value ) ) {
			return (string) $value;
		} else {
			return (string) $value;
		}
	}
}

/**
 * Fixture scenario builder for costing tests
 *
 * Encapsulates common setup patterns used across characterization scenarios.
 */
class WC_Inventory_Overview_Costing_Fixture_Builder {

	/**
	 * Build a scenario: fresh stock from zero (null prior average).
	 *
	 * @return array Fixture data.
	 */
	public static function scenario_fresh_stock_from_zero(): array {
		return array(
			'name'              => 'Fresh stock from zero',
			'old_stock'         => 0,
			'old_average_cost'  => null,
			'old_inventory_value' => 0.0,
			'added_stock'       => 10,
			'unit_cost_entered' => 15.5,
			'expected_new_stock' => 10,
			'expected_new_average_cost' => 15.5,
			'expected_new_inventory_value' => 155.0,
		);
	}

	/**
	 * Build a scenario: blending into existing stock and average.
	 *
	 * Old: 100 units at avg 10.0
	 * New: 50 units at 12.0
	 * Expected: 150 units at (100*10 + 50*12) / 150 = 10.666...
	 *
	 * @return array Fixture data.
	 */
	public static function scenario_blend_existing_stock(): array {
		return array(
			'name'              => 'Blend into existing stock and average',
			'old_stock'         => 100,
			'old_average_cost'  => 10.0,
			'old_inventory_value' => 1000.0,
			'added_stock'       => 50,
			'unit_cost_entered' => 12.0,
			'expected_new_stock' => 150,
			'expected_new_average_cost' => 10.6667, // (1000 + 600) / 150
			'expected_new_inventory_value' => 1600.0,
		);
	}
}
