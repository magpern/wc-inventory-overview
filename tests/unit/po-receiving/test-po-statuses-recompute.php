<?php
/**
 * M5: WC_Inventory_Overview_PO_Statuses::recompute_for_receiving() as a pure,
 * direction-agnostic function (mirrors M4's void-correctness design principle:
 * current-state-relative, never "was this a post or a void").
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_PO_Receiving_Statuses_Recompute extends WP_UnitTestCase {

	/**
	 * placed -> partially_received: some received, some outstanding.
	 */
	public function test_placed_to_partially_received() {
		$this->assertSame(
			'partially_received',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'placed', 4.0, 6.0 )
		);
	}

	/**
	 * placed -> received: fully covered by one receipt (outstanding hits zero).
	 */
	public function test_placed_to_received() {
		$this->assertSame(
			'received',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'placed', 0.0, 10.0 )
		);
	}

	/**
	 * partially_received -> received: the remainder gets covered by a second receipt.
	 */
	public function test_partially_received_to_received() {
		$this->assertSame(
			'received',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'partially_received', 0.0, 10.0 )
		);
	}

	/**
	 * received -> partially_received: a void re-opens outstanding while some
	 * received quantity from another receipt survives.
	 */
	public function test_received_downgrades_to_partially_received_after_void() {
		$this->assertSame(
			'partially_received',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'received', 4.0, 6.0 )
		);
	}

	/**
	 * partially_received -> placed: a void zeroes received back out to exactly 0.
	 */
	public function test_partially_received_downgrades_to_placed_after_full_void() {
		$this->assertSame(
			'placed',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'partially_received', 10.0, 0.0 )
		);
	}

	/**
	 * received -> placed directly (both contributing receipts voided in one pass).
	 */
	public function test_received_downgrades_directly_to_placed() {
		$this->assertSame(
			'placed',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'received', 10.0, 0.0 )
		);
	}

	/**
	 * Non-receiving statuses (draft/cancelled/closed_short) always pass through
	 * unchanged — receiving against them is rejected earlier, this function is
	 * never even called with meaningfully different totals for them, but must be
	 * a safe no-op regardless.
	 */
	public function test_non_receiving_statuses_pass_through_unchanged() {
		$this->assertSame( 'draft', WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'draft', 10.0, 0.0 ) );
		$this->assertSame( 'cancelled', WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'cancelled', 0.0, 10.0 ) );
		$this->assertSame( 'closed_short', WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'closed_short', 0.0, 10.0 ) );
	}

	/**
	 * Direction-agnostic: the same (status, outstanding, received) triple produces
	 * the same answer whether it was reached by a post (totals went up) or a void
	 * (totals went down) — the function never looks at which happened, only the
	 * current totals.
	 */
	public function test_direction_agnostic_same_totals_same_answer() {
		$after_post = WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'placed', 4.0, 6.0 );
		$after_void = WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'received', 4.0, 6.0 );
		$this->assertSame( $after_post, $after_void );
		$this->assertSame( 'partially_received', $after_post );
	}

	/**
	 * Numeric precision: comparisons happen at 4-decimal rounding, never raw float
	 * equality — a totals pair that rounds to exactly 0 outstanding must resolve
	 * to 'received', not stay 'partially_received' due to floating-point noise.
	 */
	public function test_rounds_before_comparing() {
		$this->assertSame(
			'received',
			WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( 'placed', 0.00001, 10.0 )
		);
	}
}
