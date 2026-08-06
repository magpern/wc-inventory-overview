<?php
/**
 * Unit tests for WC_Inventory_Overview_Goods_Receipt_Lifecycle (M4).
 *
 * Transition table: draft→posted→voided only. Draft may be hard-deleted;
 * posted/voided may never be hard-deleted; no reopen action or transition exists.
 *
 * @package WC_Inventory_Overview_Tests
 */

class Test_WC_IO_Goods_Receipt_Lifecycle extends WP_UnitTestCase {

	/**
	 * Exactly three statuses, in the exact set the plan specifies.
	 */
	public function test_exactly_three_statuses() {
		$this->assertSame( array( 'draft', 'posted', 'voided' ), WC_Inventory_Overview_Goods_Receipt_Lifecycle::all() );
	}

	/**
	 * Only draft→posted and posted→voided transitions are allowed.
	 */
	public function test_allowed_transitions() {
		$L = 'WC_Inventory_Overview_Goods_Receipt_Lifecycle';
		$this->assertTrue( $L::can_transition( $L::STATUS_DRAFT, $L::STATUS_POSTED ) );
		$this->assertTrue( $L::can_transition( $L::STATUS_POSTED, $L::STATUS_VOIDED ) );

		$this->assertFalse( $L::can_transition( $L::STATUS_DRAFT, $L::STATUS_VOIDED ), 'No direct draft→voided transition.' );
		$this->assertFalse( $L::can_transition( $L::STATUS_POSTED, $L::STATUS_DRAFT ), 'No reopen: posted→draft is illegal.' );
		$this->assertFalse( $L::can_transition( $L::STATUS_VOIDED, $L::STATUS_DRAFT ), 'No reopen: voided→draft is illegal.' );
		$this->assertFalse( $L::can_transition( $L::STATUS_VOIDED, $L::STATUS_POSTED ), 'No reopen: voided→posted is illegal.' );
	}

	/**
	 * Voided is terminal; draft and posted are not.
	 */
	public function test_terminal_status() {
		$L = 'WC_Inventory_Overview_Goods_Receipt_Lifecycle';
		$this->assertTrue( $L::is_terminal( $L::STATUS_VOIDED ) );
		$this->assertFalse( $L::is_terminal( $L::STATUS_DRAFT ) );
		$this->assertFalse( $L::is_terminal( $L::STATUS_POSTED ) );
	}

	/**
	 * No reopen action or transition exists (architecture guard surface).
	 */
	public function test_has_reopen_is_false() {
		$this->assertFalse( WC_Inventory_Overview_Goods_Receipt_Lifecycle::has_reopen() );
	}

	/**
	 * Only draft is editable; only draft may be hard-deleted.
	 */
	public function test_editability_and_delete_draft_only() {
		$L = 'WC_Inventory_Overview_Goods_Receipt_Lifecycle';
		$this->assertTrue( $L::is_editable( $L::STATUS_DRAFT ) );
		$this->assertFalse( $L::is_editable( $L::STATUS_POSTED ) );
		$this->assertFalse( $L::is_editable( $L::STATUS_VOIDED ) );

		$this->assertTrue( $L::can_delete( $L::STATUS_DRAFT ) );
		$this->assertFalse( $L::can_delete( $L::STATUS_POSTED ) );
		$this->assertFalse( $L::can_delete( $L::STATUS_VOIDED ) );
	}

	/**
	 * assert_transition() rejects terminal-state and illegal transitions with WP_Error.
	 */
	public function test_assert_transition_rejects_illegal_and_terminal() {
		$L = 'WC_Inventory_Overview_Goods_Receipt_Lifecycle';

		$terminal = $L::assert_transition( $L::STATUS_VOIDED, $L::STATUS_POSTED );
		$this->assertWPError( $terminal );
		$this->assertSame( 'wc_io_gr_terminal', $terminal->get_error_code() );

		$illegal = $L::assert_transition( $L::STATUS_DRAFT, $L::STATUS_VOIDED );
		$this->assertWPError( $illegal );
		$this->assertSame( 'wc_io_gr_illegal_transition', $illegal->get_error_code() );

		$ok = $L::assert_transition( $L::STATUS_DRAFT, $L::STATUS_POSTED );
		$this->assertTrue( $ok );
	}

	/**
	 * available_actions() never includes a reopen-shaped action.
	 */
	public function test_available_actions_never_include_reopen() {
		$L = 'WC_Inventory_Overview_Goods_Receipt_Lifecycle';
		foreach ( $L::all() as $status ) {
			$actions = $L::available_actions( $status );
			$this->assertNotContains( 'reopen', $actions );
		}
		$this->assertSame(
			array( $L::ACTION_READ ),
			$L::available_actions( $L::STATUS_VOIDED ),
			'Voided receipts permit only viewing.'
		);
	}
}
