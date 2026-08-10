<?php
/**
 * Pure, no-fixture unit tests for Milestone M11 —
 * WC_Inventory_Overview_Expected_Deadline.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Expected_Deadline extends WP_UnitTestCase {

	/**
	 * Unknown confidence is never a known date, regardless of the date
	 * value.
	 */
	public function test_has_known_date_false_for_unknown_confidence() {
		$this->assertFalse( WC_Inventory_Overview_Expected_Deadline::has_known_date( '2026-08-05', WC_Inventory_Overview_PO_Confidence::UNKNOWN ) );
	}

	/**
	 * Null, empty, and '0000-00-00' dates are never known, regardless of
	 * confidence.
	 */
	public function test_has_known_date_false_for_missing_date_variants() {
		$this->assertFalse( WC_Inventory_Overview_Expected_Deadline::has_known_date( null, WC_Inventory_Overview_PO_Confidence::ESTIMATED ) );
		$this->assertFalse( WC_Inventory_Overview_Expected_Deadline::has_known_date( '', WC_Inventory_Overview_PO_Confidence::EXACT ) );
		$this->assertFalse( WC_Inventory_Overview_Expected_Deadline::has_known_date( '0000-00-00', WC_Inventory_Overview_PO_Confidence::EXACT ) );
	}

	/**
	 * A real date with a real (non-unknown) confidence is known -- both
	 * ESTIMATED and EXACT qualify equally (D9's vocabulary is reused, not
	 * re-interpreted).
	 */
	public function test_has_known_date_true_for_real_date_and_known_confidence() {
		$this->assertTrue( WC_Inventory_Overview_Expected_Deadline::has_known_date( '2026-08-05', WC_Inventory_Overview_PO_Confidence::ESTIMATED ) );
		$this->assertTrue( WC_Inventory_Overview_Expected_Deadline::has_known_date( '2026-08-05', WC_Inventory_Overview_PO_Confidence::EXACT ) );
	}

	/**
	 * No knowable deadline returns null, not a fabricated date.
	 */
	public function test_deadline_null_when_not_knowable() {
		$this->assertNull( WC_Inventory_Overview_Expected_Deadline::deadline( null, WC_Inventory_Overview_PO_Confidence::ESTIMATED, 0 ) );
		$this->assertNull( WC_Inventory_Overview_Expected_Deadline::deadline( '2026-08-05', WC_Inventory_Overview_PO_Confidence::UNKNOWN, 0 ) );
	}

	/**
	 * grace_days = 0: the deadline is the expected_date itself.
	 */
	public function test_deadline_with_zero_grace_days() {
		$this->assertSame(
			'2026-08-05',
			WC_Inventory_Overview_Expected_Deadline::deadline( '2026-08-05', WC_Inventory_Overview_PO_Confidence::ESTIMATED, 0 )
		);
	}

	/**
	 * grace_days > 0: the deadline is expected_date + N calendar days.
	 */
	public function test_deadline_with_positive_grace_days() {
		$this->assertSame(
			'2026-08-08',
			WC_Inventory_Overview_Expected_Deadline::deadline( '2026-08-05', WC_Inventory_Overview_PO_Confidence::ESTIMATED, 3 )
		);
	}

	/**
	 * A negative grace_days input is clamped to zero, never subtracted.
	 */
	public function test_deadline_clamps_negative_grace_days_to_zero() {
		$this->assertSame(
			'2026-08-05',
			WC_Inventory_Overview_Expected_Deadline::deadline( '2026-08-05', WC_Inventory_Overview_PO_Confidence::ESTIMATED, -5 )
		);
	}

	/**
	 * sql_deadline_expression() produces the exact DATE_ADD() fragment,
	 * composable into a caller's own query -- no comparison operator, no
	 * eligibility check baked in.
	 */
	public function test_sql_deadline_expression_shape() {
		$this->assertSame(
			'DATE_ADD(po.expected_date, INTERVAL 3 DAY)',
			WC_Inventory_Overview_Expected_Deadline::sql_deadline_expression( 'po.expected_date', 3 )
		);
		$this->assertSame(
			'DATE_ADD(po.expected_date, INTERVAL 0 DAY)',
			WC_Inventory_Overview_Expected_Deadline::sql_deadline_expression( 'po.expected_date', -5 ),
			'Negative grace_days must be clamped to zero in the SQL fragment too.'
		);
	}

	/**
	 * sql_has_known_date_expression() produces a self-contained boolean
	 * fragment referencing exactly the two expressions passed in.
	 */
	public function test_sql_has_known_date_expression_shape() {
		$sql = WC_Inventory_Overview_Expected_Deadline::sql_has_known_date_expression( 'po.expected_date', 'po.expected_confidence' );

		$this->assertStringContainsString( 'po.expected_date', $sql );
		$this->assertStringContainsString( 'po.expected_confidence', $sql );
		$this->assertStringContainsString( "<> 'unknown'", $sql );
		$this->assertStringContainsString( 'IS NOT NULL', $sql );
	}
}
