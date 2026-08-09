<?php
/**
 * Pure-input-shape unit tests for Milestone M10 —
 * WC_Inventory_Overview_Expected_Date_Suggestion_Service.
 *
 * Covers input-shape edge cases and the configured-fallback/no-suggestion
 * paths, which never require real observed history (a nonexistent supplier
 * ID has no PO/receipt history by construction, so Supplier_Lead_Time_Service
 * always reports "no data" for it here -- exactly like M9's own unit-test
 * split). The "observed average wins" path requires real fixture data and
 * lives in tests/integration/expected-date-suggestion/.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Date_Suggestion_Service extends WP_UnitTestCase {

	private const NONEXISTENT_SUPPLIER_ID = 999999;

	/**
	 * Empty input must short-circuit to an empty result.
	 */
	public function test_get_suggestions_bulk_with_empty_array_returns_empty_array() {
		$this->assertSame( array(), WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( array() ) );
	}

	/**
	 * A supplier row missing/zero/negative `id` is not a valid supplier and
	 * must be filtered out, never fatal, never treated as a real supplier.
	 */
	public function test_get_suggestions_bulk_with_invalid_supplier_ids_returns_empty_array() {
		$results = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk(
			array(
				array( 'default_lead_time_days' => 10 ), // No 'id' at all.
				array(
					'id'                      => 0,
					'default_lead_time_days'  => 10,
				),
				array(
					'id'                      => -5,
					'default_lead_time_days'  => 10,
				),
			)
		);

		$this->assertSame( array(), $results );
	}

	/**
	 * No observed history (nonexistent supplier) + a configured
	 * default_lead_time_days > 0 -> the configured value is used, never
	 * silently dropped.
	 */
	public function test_configured_lead_time_used_when_no_observed_history() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array(
				'id'                     => self::NONEXISTENT_SUPPLIER_ID,
				'default_lead_time_days' => 9,
			)
		);

		$this->assertSame( 9, $suggestion['days'] );
		$this->assertSame( WC_Inventory_Overview_PO_Confidence::ESTIMATED, $suggestion['confidence'] );
		$this->assertSame( 'configured', $suggestion['source'] );
	}

	/**
	 * default_lead_time_days = 0 is not a usable fallback (a real value must
	 * be strictly positive) -- must fall through to "no suggestion", not a
	 * misleading "0 days" suggestion.
	 */
	public function test_zero_configured_lead_time_produces_no_suggestion() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array(
				'id'                     => self::NONEXISTENT_SUPPLIER_ID,
				'default_lead_time_days' => 0,
			)
		);

		$this->assertNull( $suggestion['days'] );
		$this->assertNull( $suggestion['confidence'] );
		$this->assertSame( 'none', $suggestion['source'] );
	}

	/**
	 * No observed history and no configured_lead_time_days at all (missing
	 * key, matching a supplier row that never set it) -> no suggestion,
	 * never a fabricated value and never EXACT confidence.
	 */
	public function test_no_observed_and_no_configured_produces_no_suggestion() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array( 'id' => self::NONEXISTENT_SUPPLIER_ID )
		);

		$this->assertNull( $suggestion['days'] );
		$this->assertNull( $suggestion['confidence'] );
		$this->assertSame( 'none', $suggestion['source'] );
		$this->assertNotSame( WC_Inventory_Overview_PO_Confidence::EXACT, $suggestion['confidence'] );
	}

	/**
	 * An empty-string default_lead_time_days (e.g. a blank form field
	 * persisted as '') must not be misread as a real value.
	 */
	public function test_empty_string_configured_lead_time_produces_no_suggestion() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array(
				'id'                     => self::NONEXISTENT_SUPPLIER_ID,
				'default_lead_time_days' => '',
			)
		);

		$this->assertSame( 'none', $suggestion['source'] );
	}

	/**
	 * Confidence is always ESTIMATED when a suggestion exists -- never
	 * EXACT, which is reserved for a supplier-confirmed date, never
	 * something a system guessed.
	 */
	public function test_confidence_is_always_estimated_never_exact_when_suggestion_exists() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array(
				'id'                     => self::NONEXISTENT_SUPPLIER_ID,
				'default_lead_time_days' => 5,
			)
		);

		$this->assertSame( WC_Inventory_Overview_PO_Confidence::ESTIMATED, $suggestion['confidence'] );
		$this->assertNotSame( WC_Inventory_Overview_PO_Confidence::EXACT, $suggestion['confidence'] );
	}

	/**
	 * Archived-ness is irrelevant to the suggestion resolver -- it never
	 * inspects a supplier's status at all (mirrors M9's own "archiving
	 * never erases purchasing evidence" precedent); an archived supplier's
	 * configured lead time is used exactly the same as an active one's.
	 */
	public function test_archived_supplier_status_does_not_affect_the_suggestion() {
		$suggestion = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier(
			array(
				'id'                     => self::NONEXISTENT_SUPPLIER_ID,
				'default_lead_time_days' => 7,
				'status'                 => 'archived',
			)
		);

		$this->assertSame( 7, $suggestion['days'] );
		$this->assertSame( 'configured', $suggestion['source'] );
	}

	/**
	 * Bulk and single-call results must be identical for every supplier
	 * (same discipline as every prior derived-value service in this
	 * codebase).
	 */
	public function test_bulk_matches_single_for_every_supplier() {
		$suppliers = array(
			array(
				'id'                     => 1000001,
				'default_lead_time_days' => 5,
			),
			array(
				'id'                     => 1000002,
				'default_lead_time_days' => 0,
			),
			array( 'id' => 1000003 ),
		);

		$bulk = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers );

		foreach ( $suppliers as $supplier ) {
			$single = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestion_for_supplier( $supplier );
			$this->assertSame( $bulk[ (int) $supplier['id'] ], $single, "Bulk and single results must be identical for supplier {$supplier['id']}." );
		}
	}

	/**
	 * The reused M9 predicate is exercised as a black box here, not
	 * duplicated -- proves the two documented boundary values behave as
	 * M9 itself defines them (MINIMUM_SAMPLE_COUNT_FOR_DISPLAY = 2),
	 * without this service hardcoding the number "2" anywhere.
	 */
	public function test_reuses_m9_usability_predicate_as_a_black_box() {
		$this->assertFalse(
			WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable(
				array(
					'has_data'     => true,
					'average_days' => 5.0,
					'fastest_days' => 5,
					'slowest_days' => 5,
					'sample_count' => 1,
				)
			)
		);
		$this->assertTrue(
			WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable(
				array(
					'has_data'     => true,
					'average_days' => 5.0,
					'fastest_days' => 5,
					'slowest_days' => 5,
					'sample_count' => WC_Inventory_Overview_Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY,
				)
			)
		);
	}
}
