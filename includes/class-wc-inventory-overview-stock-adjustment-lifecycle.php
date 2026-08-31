<?php
/**
 * Stock Adjustment lifecycle (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustment lifecycle rules.
 */
class WC_Inventory_Overview_Stock_Adjustment_Lifecycle {

	const STATUS_DRAFT  = 'draft';
	const STATUS_POSTED = 'posted';
	const STATUS_VOIDED = 'voided';

	const KIND_PERSONAL_USE = 'personal_use';

	const ACTION_POST         = 'post';
	const ACTION_VOID         = 'void';
	const ACTION_EDIT         = 'edit';
	const ACTION_DELETE_DRAFT = 'delete_draft';
	const ACTION_READ         = 'read';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::STATUS_DRAFT, self::STATUS_POSTED, self::STATUS_VOIDED );
	}

	/**
	 * @param string $status Status.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
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
	 * @param string $from From.
	 * @param string $to   To.
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
	 * @param string $status Status.
	 */
	public static function is_editable( string $status ): bool {
		return self::STATUS_DRAFT === $status;
	}

	/**
	 * @param string $status Status.
	 * @return true|WP_Error
	 */
	public static function assert_editable( string $status ) {
		if ( ! self::is_valid( $status ) ) {
			return new WP_Error( 'wc_io_sa_invalid_status', sprintf( 'Unknown Stock Adjustment status: %s', $status ) );
		}
		if ( ! self::is_editable( $status ) ) {
			return new WP_Error(
				'wc_io_sa_not_draft',
				sprintf( 'Stock Adjustment status "%s" does not permit edits', $status )
			);
		}
		return true;
	}

	/**
	 * @param string $status Status.
	 */
	public static function can_delete( string $status ): bool {
		return self::STATUS_DRAFT === $status;
	}

	/**
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

	/**
	 * @param string $kind Kind.
	 */
	public static function kind_label( string $kind ): string {
		if ( self::KIND_PERSONAL_USE === $kind ) {
			return __( 'Personal use', 'wc-inventory-overview' );
		}
		return $kind;
	}

	/**
	 * @param string $status Status.
	 * @return array<int,string>
	 */
	public static function available_actions( string $status ): array {
		switch ( $status ) {
			case self::STATUS_DRAFT:
				return array( self::ACTION_EDIT, self::ACTION_DELETE_DRAFT, self::ACTION_POST );
			case self::STATUS_POSTED:
				return array( self::ACTION_READ, self::ACTION_VOID );
			case self::STATUS_VOIDED:
				return array( self::ACTION_READ );
			default:
				return array();
		}
	}
}
