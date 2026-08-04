<?php
/**
 * Unit tests for supplier normalization.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Suppliers_Normalization extends WP_UnitTestCase {

	/**
	 * Test whitespace collapse.
	 */
	public function test_collapse_whitespace() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( 'Nature  Supply   AB' );
		$this->assertEquals( 'nature supply ab', $result );
	}

	/**
	 * Test trim.
	 */
	public function test_trim() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( '  NatureSupp  ' );
		$this->assertEquals( 'naturesupp', $result );
	}

	/**
	 * Test casefold.
	 */
	public function test_casefold() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( 'NATURE SUPPLY' );
		$this->assertEquals( 'nature supply', $result );
	}

	/**
	 * Test no punctuation stripping.
	 */
	public function test_punctuation_preserved() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( 'Nature Supply, Inc.' );
		$this->assertEquals( 'nature supply, inc.', $result );
	}

	/**
	 * Test no suffix stripping.
	 */
	public function test_suffix_preserved() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( 'Nature Supply AB' );
		$this->assertEquals( 'nature supply ab', $result );
	}

	/**
	 * Test distinct names remain distinct.
	 */
	public function test_distinct_names_stay_distinct() {
		$norm1 = WC_Inventory_Overview_Suppliers::normalize_name( 'NatureSupp' );
		$norm2 = WC_Inventory_Overview_Suppliers::normalize_name( 'Nature Supp AB' );
		$this->assertNotEquals( $norm1, $norm2 );
	}

	/**
	 * Test NBSP collapse.
	 */
	public function test_nbsp_collapse() {
		$result = WC_Inventory_Overview_Suppliers::normalize_name( "Nature\u{00A0}Supply" );
		$this->assertEquals( 'nature supply', $result );
	}

	/**
	 * Test idempotency: normalizing twice yields the same result.
	 */
	public function test_idempotency() {
		$original = 'Nature  Supply, Inc.';
		$norm1 = WC_Inventory_Overview_Suppliers::normalize_name( $original );
		$norm2 = WC_Inventory_Overview_Suppliers::normalize_name( $norm1 );
		$this->assertEquals( $norm1, $norm2 );
	}
}
