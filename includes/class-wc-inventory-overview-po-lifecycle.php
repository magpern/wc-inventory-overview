<?php
/**
 * Purchase Order lifecycle: single transition table (M2-B, extended M5).
 *
 * Sole source of truth for *manual* (operator-gated) transitions, available
 * actions, editability, and terminal-state detection. No persistence writes.
 * The two M5 receiving statuses (`partially_received`/`received`) are
 * auto-transitioned by WC_Inventory_Overview_PO_Receiving_Sync and never
 * appear as a manual transition target here — see transitions() docblock.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO lifecycle rules.
 */
class WC_Inventory_Overview_PO_Lifecycle {

	const ACTION_PLACE        = 'place';
	const ACTION_CANCEL       = 'cancel';
	const ACTION_CLOSE_SHORT  = 'close_short';
	const ACTION_EDIT         = 'edit';
	const ACTION_DELETE_DRAFT = 'delete_draft';
	const ACTION_DUPLICATE    = 'duplicate';
	const ACTION_READ         = 'read';

	/**
	 * Allowed *manual* (operator-gated) status transitions. Key is from-status;
	 * value is list of to-statuses. Terminal statuses have empty lists (absorbing).
	 *
	 * `partially_received`/`received` are deliberately never a *target* value
	 * anywhere in this table — those transitions are auto-only, reachable
	 * exclusively through WC_Inventory_Overview_PO_Receiving_Sync, which writes
	 * status directly and never calls assert_transition()/can_transition() (M5
	 * plan §Receiving-status ownership). `partially_received` still needs its
	 * own row here as a *from*-status so cancel/close_short remain available
	 * while a PO is partially received; `received` has an empty list — no
	 * manual action is available once fully received (its only exit is the
	 * automatic downgrade via a receipt void, which likewise bypasses this table).
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function transitions(): array {
		return array(
			WC_Inventory_Overview_PO_Statuses::DRAFT     => array(
				WC_Inventory_Overview_PO_Statuses::PLACED,
				WC_Inventory_Overview_PO_Statuses::CANCELLED,
			),
			WC_Inventory_Overview_PO_Statuses::PLACED    => array(
				WC_Inventory_Overview_PO_Statuses::CANCELLED,
				WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
			),
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED => array(
				WC_Inventory_Overview_PO_Statuses::CANCELLED,
				WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT,
			),
			WC_Inventory_Overview_PO_Statuses::RECEIVED  => array(),
			WC_Inventory_Overview_PO_Statuses::CANCELLED => array(),
			WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT => array(),
		);
	}

	/**
	 * Whether a transition from $from to $to is allowed.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $from ) || ! WC_Inventory_Overview_PO_Statuses::is_valid( $to ) ) {
			return false;
		}
		$table   = self::transitions();
		$allowed = isset( $table[ $from ] ) ? $table[ $from ] : array();
		return in_array( $to, $allowed, true );
	}

	/**
	 * Assert a transition is allowed. Returns true or WP_Error.
	 *
	 * @param string $from Current status.
	 * @param string $to   Target status.
	 * @return true|WP_Error
	 */
	public static function assert_transition( string $from, string $to ) {
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $from ) ) {
			return new WP_Error( 'wc_io_po_invalid_status', sprintf( 'Unknown PO status: %s', $from ) );
		}
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $to ) ) {
			return new WP_Error( 'wc_io_po_invalid_status', sprintf( 'Unknown PO status: %s', $to ) );
		}
		if ( WC_Inventory_Overview_PO_Statuses::is_terminal( $from ) ) {
			return new WP_Error(
				'wc_io_po_terminal',
				sprintf( 'Purchase order is terminal (%s); no further transitions are allowed', $from )
			);
		}
		if ( ! self::can_transition( $from, $to ) ) {
			return new WP_Error(
				'wc_io_po_illegal_transition',
				sprintf( 'Illegal PO transition: %s → %s', $from, $to )
			);
		}
		return true;
	}

	/**
	 * Whether header/line edits are permitted in this status.
	 *
	 * Draft and placed are editable. Terminal statuses are not. There is no reopen.
	 *
	 * @param string $status Status.
	 */
	public static function is_editable( string $status ): bool {
		return WC_Inventory_Overview_PO_Statuses::DRAFT === $status
			|| WC_Inventory_Overview_PO_Statuses::PLACED === $status;
	}

	/**
	 * Assert the PO may be edited. Rejects terminal and unknown statuses.
	 *
	 * @param string $status Status.
	 * @return true|WP_Error
	 */
	public static function assert_editable( string $status ) {
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $status ) ) {
			return new WP_Error( 'wc_io_po_invalid_status', sprintf( 'Unknown PO status: %s', $status ) );
		}
		if ( ! self::is_editable( $status ) ) {
			return new WP_Error(
				'wc_io_po_terminal',
				sprintf( 'Purchase order status "%s" does not permit edits', $status )
			);
		}
		return true;
	}

	/**
	 * Whether hard-delete is allowed (draft only).
	 *
	 * @param string $status Status.
	 */
	public static function can_delete( string $status ): bool {
		return WC_Inventory_Overview_PO_Statuses::DRAFT === $status;
	}

	/**
	 * Whether duplicate is allowed. Available from every valid status (M2-C implements it).
	 *
	 * @param string $status Status.
	 */
	public static function can_duplicate( string $status ): bool {
		return WC_Inventory_Overview_PO_Statuses::is_valid( $status );
	}

	/**
	 * Actions available for a status. Driven by the transition table plus
	 * edit/delete/duplicate/read rules. No reopen action exists.
	 *
	 * @param string $status Status.
	 * @return array<int,string>
	 */
	public static function available_actions( string $status ): array {
		if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $status ) ) {
			return array();
		}

		$actions = array( self::ACTION_READ, self::ACTION_DUPLICATE );

		if ( self::is_editable( $status ) ) {
			$actions[] = self::ACTION_EDIT;
		}

		if ( self::can_delete( $status ) ) {
			$actions[] = self::ACTION_DELETE_DRAFT;
		}

		if ( self::can_transition( $status, WC_Inventory_Overview_PO_Statuses::PLACED ) ) {
			$actions[] = self::ACTION_PLACE;
		}
		if ( self::can_transition( $status, WC_Inventory_Overview_PO_Statuses::CANCELLED ) ) {
			$actions[] = self::ACTION_CANCEL;
		}
		if ( self::can_transition( $status, WC_Inventory_Overview_PO_Statuses::CLOSED_SHORT ) ) {
			$actions[] = self::ACTION_CLOSE_SHORT;
		}

		return $actions;
	}

	/**
	 * Whether an action is available for a status.
	 *
	 * @param string $status Status.
	 * @param string $action Action constant.
	 */
	public static function can_perform( string $status, string $action ): bool {
		return in_array( $action, self::available_actions( $status ), true );
	}

	/**
	 * Confirm no reopen action or transition exists (architecture guard surface).
	 */
	public static function has_reopen(): bool {
		return false;
	}

	/**
	 * Human-readable labels for lifecycle actions (admin buttons).
	 *
	 * @return array<string,string>
	 */
	public static function action_labels(): array {
		return array(
			self::ACTION_PLACE        => __( 'Place', 'wc-inventory-overview' ),
			self::ACTION_CANCEL       => __( 'Cancel', 'wc-inventory-overview' ),
			self::ACTION_CLOSE_SHORT  => __( 'Close Short', 'wc-inventory-overview' ),
			self::ACTION_EDIT         => __( 'Save', 'wc-inventory-overview' ),
			self::ACTION_DELETE_DRAFT => __( 'Delete Draft', 'wc-inventory-overview' ),
			self::ACTION_DUPLICATE    => __( 'Duplicate', 'wc-inventory-overview' ),
			self::ACTION_READ         => __( 'View', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Label for an action key.
	 *
	 * @param string $action Action.
	 */
	public static function action_label( string $action ): string {
		$labels = self::action_labels();
		return isset( $labels[ $action ] ) ? $labels[ $action ] : $action;
	}
}
