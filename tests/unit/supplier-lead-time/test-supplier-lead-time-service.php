<?php
/**
 * Pure, no-fixture unit tests for Milestone M9 —
 * WC_Inventory_Overview_Supplier_Lead_Time_Service.
 *
 * Covers the input-shape edge cases that never need to touch the database
 * (empty/invalid input short-circuits before any query is issued) and the
 * presentation-threshold constant's shape. Behavioral computation tests
 * (avg/min/max/count, exclusion rules, insertion-order independence, bulk/
 * single consistency) live in tests/integration/supplier-lead-time/, since
 * they require real Purchase Order / Goods Receipt fixture data.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Supplier_Lead_Time_Service extends WP_UnitTestCase {

	/**
	 * Empty input must short-circuit to an empty result without ever
	 * touching the database (no supplier IDs to look up).
	 */
	public function test_get_stats_bulk_with_empty_array_returns_empty_array() {
		$this->assertSame( array(), WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( array() ) );
	}

	/**
	 * Zero/negative IDs are not valid supplier IDs and must be filtered out
	 * before any query runs, leaving an empty result -- never a fatal error
	 * or a query with a nonsensical WHERE supplier_id IN (0, -1).
	 */
	public function test_get_stats_bulk_with_only_invalid_ids_returns_empty_array() {
		$this->assertSame( array(), WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( array( 0, -1, -100 ) ) );
	}

	/**
	 * Duplicate IDs in the input must not produce duplicate keys or double
	 * counting -- the result is keyed by supplier ID, so a duplicate simply
	 * collapses to one entry.
	 */
	public function test_get_stats_bulk_deduplicates_repeated_ids_without_a_real_supplier() {
		$results = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( array( 999999, 999999 ) );

		$this->assertCount( 1, $results );
		$this->assertArrayHasKey( 999999, $results );
		$this->assertFalse( $results[999999]['has_data'] );
	}

	/**
	 * The presentation-layer display threshold (plan §9) is a public,
	 * documented constant -- the single source of truth the admin UI
	 * consults, never a hardcoded "2" duplicated in the UI file.
	 */
	public function test_minimum_sample_count_constant_is_two() {
		$this->assertSame( 2, WC_Inventory_Overview_Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY );
	}

	/**
	 * A supplier ID with no data at all (no PO/receipt history) returns the
	 * explicit "not enough data yet" shape -- never 0-as-if-computed.
	 */
	public function test_get_stats_for_supplier_with_no_history_returns_no_data_state() {
		$stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( 999999 );

		$this->assertFalse( $stats['has_data'] );
		$this->assertNull( $stats['average_days'] );
		$this->assertNull( $stats['fastest_days'] );
		$this->assertNull( $stats['slowest_days'] );
		$this->assertSame( 0, $stats['sample_count'] );
	}
}
