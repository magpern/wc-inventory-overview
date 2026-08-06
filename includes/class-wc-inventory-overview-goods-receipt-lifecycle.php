<?php
/**
 * Goods Receipt lifecycle: single transition table (M4).
 *
 * Sole source of truth for transitions, available actions, editability, and
 * terminal-state detection. No persistence writes. Mirrors
 * WC_Inventory_Overview_PO_Lifecycle for a simpler three-state lifecycle.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt lifecycle rules.
 */
class WC_Inventory_Overview_Goods_Receipt_Lifecycle {

	const STATUS_DRAFT  = 'draft';
	const STATUS_POSTED = 'posted';
	const STATUS_VOIDED = 'voided';

	const ACTION_POST         = 'post';
	const ACTION_VOID         = 'void';
	const ACTION_EDIT         = 'edit';
	const ACTION_DELETE_DRAFT = 'delete_draft';
	const ACTION_READ         = 'read';

	/**
	 * All valid statuses.
	 *
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_VOIDED );
	}

	/**
	 * Whether a status string is one of the three valid values.
	 *
	 * @param string $status Status.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Terminal statuses have empty transition lists (absorbing). Voided is terminal;
	 * there is no reopen (D10).
	 *
	 * @return array<string,array<int,string>>
	 */
	public static function transitions(): array {
		return array(
			self::STATUS_DRAFT  => array( self::STATUS_POSTED ),
			self::STATUS_POSTED => array( self::STATUS_VOIDED ),
			self::STATUS_VOIDED => array(),
		);
	}

	/**
	 * Whether a status is terminal (voided — no further transitions).
	 *
	 * @param string $status Status.
	 */
	public static function is_terminal( string $status ): bool {
		return self::STATUS_VOIDED === $status;
	}

	/**
	 * Whether a transition from $from to $to is allowed.
	 *
	 * @param string $from From status.
	 * @param string $to   To status.
	 */
	public static function can_transition( string $from, string $to ): bool {
		if ( ! self::is_valid( $from ) || ! self::is_valid( $to ) ) {
			return false;
		}
		$table   = self::transitions();
		$allowed = isset( $table[ $from ] ) ? $table[ $from ] : array();
		return in_array( $to, $allowed, true );
	}

	/**
	 * Assert a transition is allowed. Returns true or WP_Error.
	 *
	 * @param string $from From status.
	 * @param string $to   To status.
	 * @return true|WP_Error
	 */
	public static function assert_transition( string $from, string $to ) {
		if ( ! self::is_valid( $from ) ) {
			return new WP_Error( 'wc_io_gr_invalid_status', sprintf( 'Unknown Goods Receipt status: %s', $from ) );
		}
		if ( ! self::is_valid( $to ) ) {
			return new WP_Error( 'wc_io_gr_invalid_status', sprintf( 'Unknown Goods Receipt status: %s', $to ) );
		}
		if ( self::is_terminal( $from ) ) {
			return new WP_Error(
				'wc_io_gr_terminal',
				sprintf( 'Goods Receipt is terminal (%s); no further transitions are allowed', $from )
			);
		}
		if ( ! self::can_transition( $from, $to ) ) {
			return new WP_Error(
				'wc_io_gr_illegal_transition',
				sprintf( 'Illegal Goods Receipt transition: %s → %s', $from, $to )
			);
		}
		return true;
	}

	/**
	 * Whether header/line edits are permitted in this status. Draft only — after
	 * posting, every field is immutable except status/voided_at/voided_by/void_reason
	 * (enforced by the service, not here).
	 *
	 * @param string $status Status.
	 */
	public static function is_editable( string $status ): bool {
		return self::STATUS_DRAFT === $status;
	}

	/**
	 * Assert the receipt may be edited. Rejects posted/voided and unknown statuses.
	 *
	 * @param string $status Status.
	 * @return true|WP_Error
	 */
	public static function assert_editable( string $status ) {
		if ( ! self::is_valid( $status ) ) {
			return new WP_Error( 'wc_io_gr_invalid_status', sprintf( 'Unknown Goods Receipt status: %s', $status ) );
		}
		if ( ! self::is_editable( $status ) ) {
			return new WP_Error(
				'wc_io_gr_not_draft',
				sprintf( 'Goods Receipt status "%s" does not permit edits', $status )
			);
		}
		return true;
	}

	/**
	 * Whether hard-delete is allowed (draft only). Posted/voided receipts may never
	 * be hard-deleted.
	 *
	 * @param string $status Status.
	 */
	public static function can_delete( string $status ): bool {
		return self::STATUS_DRAFT === $status;
	}

	/**
	 * Actions available for a status. No reopen action exists.
	 *
	 * @param string $status Status.
	 * @return array<int,string>
	 */
	public static function available_actions( string $status ): array {
		if ( ! self::is_valid( $status ) ) {
			return array();
		}

		$actions = array( self::ACTION_READ );

		if ( self::is_editable( $status ) ) {
			$actions[] = self::ACTION_EDIT;
		}
		if ( self::can_delete( $status ) ) {
			$actions[] = self::ACTION_DELETE_DRAFT;
		}
		if ( self::can_transition( $status, self::STATUS_POSTED ) ) {
			$actions[] = self::ACTION_POST;
		}
		if ( self::can_transition( $status, self::STATUS_VOIDED ) ) {
			$actions[] = self::ACTION_VOID;
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
			self::ACTION_POST         => __( 'Post', 'wc-inventory-overview' ),
			self::ACTION_VOID         => __( 'Void', 'wc-inventory-overview' ),
			self::ACTION_EDIT         => __( 'Save', 'wc-inventory-overview' ),
			self::ACTION_DELETE_DRAFT => __( 'Delete Draft', 'wc-inventory-overview' ),
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

	/**
	 * Human-readable label for a status (admin display).
	 *
	 * @param string $status Status.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			self::STATUS_DRAFT  => __( 'Draft', 'wc-inventory-overview' ),
			self::STATUS_POSTED => __( 'Posted', 'wc-inventory-overview' ),
			self::STATUS_VOIDED => __( 'Voided', 'wc-inventory-overview' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}
}
