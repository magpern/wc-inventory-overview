<?php
/**
 * M2-B: PO lifecycle transition table tests.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * Lifecycle tests.
 */
class Test_WC_IO_PO_Lifecycle extends WP_UnitTestCase {

	/**
	 * Vocabulary remains exactly four statuses.
	 */
	public function test_status_vocabulary_exactly_four() {
		$this->assertSame(
			array( 'draft', 'placed', 'cancelled', 'closed_short' ),
			WC_Inventory_Overview_PO_Statuses::all()
		);
		$this->assertCount( 4, WC_Inventory_Overview_PO_Lifecycle::transitions() );
	}

	/**
	 * Every allowed transition.
	 */
	public function test_allowed_transitions() {
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'draft', 'placed' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'draft', 'cancelled' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'placed', 'cancelled' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'placed', 'closed_short' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::assert_transition( 'draft', 'placed' ) );
	}

	/**
	 * Every rejected transition among the four statuses.
	 */
	public function test_rejected_transitions() {
		$rejected = array(
			array( 'draft', 'draft' ),
			array( 'draft', 'closed_short' ),
			array( 'placed', 'placed' ),
			array( 'placed', 'draft' ),
			array( 'cancelled', 'draft' ),
			array( 'cancelled', 'placed' ),
			array( 'cancelled', 'cancelled' ),
			array( 'cancelled', 'closed_short' ),
			array( 'closed_short', 'draft' ),
			array( 'closed_short', 'placed' ),
			array( 'closed_short', 'cancelled' ),
			array( 'closed_short', 'closed_short' ),
		);
		foreach ( $rejected as $pair ) {
			$this->assertFalse(
				WC_Inventory_Overview_PO_Lifecycle::can_transition( $pair[0], $pair[1] ),
				$pair[0] . ' → ' . $pair[1]
			);
			$this->assertWPError( WC_Inventory_Overview_PO_Lifecycle::assert_transition( $pair[0], $pair[1] ) );
		}
	}

	/**
	 * No outgoing transitions from cancelled.
	 */
	public function test_cancelled_has_no_outgoing() {
		$this->assertSame( array(), WC_Inventory_Overview_PO_Lifecycle::transitions()['cancelled'] );
		foreach ( WC_Inventory_Overview_PO_Statuses::all() as $to ) {
			$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'cancelled', $to ) );
		}
	}

	/**
	 * No outgoing transitions from closed_short.
	 */
	public function test_closed_short_has_no_outgoing() {
		$this->assertSame( array(), WC_Inventory_Overview_PO_Lifecycle::transitions()['closed_short'] );
		foreach ( WC_Inventory_Overview_PO_Statuses::all() as $to ) {
			$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'closed_short', $to ) );
		}
	}

	/**
	 * Terminal statuses reject edits; placed and draft allow them.
	 */
	public function test_editability() {
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::is_editable( 'draft' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::is_editable( 'placed' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::is_editable( 'cancelled' ) );
		$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::is_editable( 'closed_short' ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Lifecycle::assert_editable( 'cancelled' ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Lifecycle::assert_editable( 'closed_short' ) );
		$this->assertTrue( WC_Inventory_Overview_PO_Lifecycle::assert_editable( 'placed' ) );
	}

	/**
	 * Available actions follow the transition table; no reopen.
	 */
	public function test_available_actions_and_no_reopen() {
		$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::has_reopen() );

		$draft = WC_Inventory_Overview_PO_Lifecycle::available_actions( 'draft' );
		$this->assertContains( 'place', $draft );
		$this->assertContains( 'cancel', $draft );
		$this->assertContains( 'edit', $draft );
		$this->assertContains( 'delete_draft', $draft );
		$this->assertContains( 'duplicate', $draft );
		$this->assertNotContains( 'close_short', $draft );
		$this->assertNotContains( 'reopen', $draft );

		$placed = WC_Inventory_Overview_PO_Lifecycle::available_actions( 'placed' );
		$this->assertContains( 'cancel', $placed );
		$this->assertContains( 'close_short', $placed );
		$this->assertContains( 'edit', $placed );
		$this->assertNotContains( 'place', $placed );
		$this->assertNotContains( 'delete_draft', $placed );

		$terminal = WC_Inventory_Overview_PO_Lifecycle::available_actions( 'closed_short' );
		$this->assertContains( 'read', $terminal );
		$this->assertContains( 'duplicate', $terminal );
		$this->assertNotContains( 'edit', $terminal );
		$this->assertNotContains( 'cancel', $terminal );
		$this->assertNotContains( 'close_short', $terminal );
		$this->assertNotContains( 'reopen', $terminal );
	}

	/**
	 * Unknown statuses are rejected.
	 */
	public function test_unknown_status_rejected() {
		$this->assertFalse( WC_Inventory_Overview_PO_Lifecycle::can_transition( 'partially_received', 'received' ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Lifecycle::assert_transition( 'draft', 'received' ) );
		$this->assertWPError( WC_Inventory_Overview_PO_Lifecycle::assert_editable( 'received' ) );
		$this->assertSame( array(), WC_Inventory_Overview_PO_Lifecycle::available_actions( 'received' ) );
	}
}
