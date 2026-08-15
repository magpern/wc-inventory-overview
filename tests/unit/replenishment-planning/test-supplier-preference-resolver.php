<?php
/**
 * M24 WP-M24-2: WC_Inventory_Overview_Supplier_Preference_Resolver::decide()
 * truth table (pure, no DB).
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Preference_Resolver extends WC_Inventory_Overview_Test_Case {

	public function test_eligible_preferred_used_directly_history_not_consulted() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 42, true, array( 7, 8 ) );

		$this->assertSame( 42, $result['supplier_id'] );
		$this->assertFalse( $result['preferred_was_stale'] );
		$this->assertSame( 'not_consulted', $result['history_outcome'], 'A currently-eligible preferred supplier must short-circuit history entirely, even if candidates were passed.' );
	}

	public function test_stale_preferred_zero_history_unresolved() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 42, false, array() );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertTrue( $result['preferred_was_stale'] );
		$this->assertSame( 'none', $result['history_outcome'] );
	}

	public function test_stale_preferred_single_history_falls_back() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 42, false, array( 7 ) );

		$this->assertSame( 7, $result['supplier_id'] );
		$this->assertTrue( $result['preferred_was_stale'] );
		$this->assertSame( 'single', $result['history_outcome'] );
	}

	public function test_stale_preferred_multiple_history_unresolved_ambiguous() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 42, false, array( 7, 8 ) );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertTrue( $result['preferred_was_stale'] );
		$this->assertSame( 'ambiguous', $result['history_outcome'] );
	}

	public function test_no_preferred_zero_history_unresolved() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 0, false, array() );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertFalse( $result['preferred_was_stale'], 'preferred_was_stale must be false when no preference was ever configured.' );
		$this->assertSame( 'none', $result['history_outcome'] );
	}

	public function test_no_preferred_single_history_resolved() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 0, false, array( 9 ) );

		$this->assertSame( 9, $result['supplier_id'] );
		$this->assertFalse( $result['preferred_was_stale'] );
		$this->assertSame( 'single', $result['history_outcome'] );
	}

	public function test_no_preferred_multiple_history_unresolved_ambiguous() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 0, false, array( 9, 10, 11 ) );

		$this->assertSame( 0, $result['supplier_id'] );
		$this->assertFalse( $result['preferred_was_stale'] );
		$this->assertSame( 'ambiguous', $result['history_outcome'] );
	}

	/**
	 * Defensive: preferred_supplier_eligible=true is meaningless/ignored
	 * when preferred_supplier_id<=0 -- must not be treated as a valid
	 * direct hit.
	 */
	public function test_eligible_flag_ignored_when_no_preferred_id() {
		$result = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 0, true, array( 9 ) );

		$this->assertSame( 9, $result['supplier_id'], 'With no preferred id, eligible=true must not fabricate a supplier_id of 0 being treated as eligible.' );
		$this->assertSame( 'single', $result['history_outcome'] );
	}

	/**
	 * Purity: same inputs always produce the same output (no internal
	 * state, no randomness, no I/O).
	 */
	public function test_pure_deterministic() {
		$a = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 5, false, array( 1, 2 ) );
		$b = WC_Inventory_Overview_Supplier_Preference_Resolver::decide( 5, false, array( 1, 2 ) );
		$this->assertSame( $a, $b );
	}
}
