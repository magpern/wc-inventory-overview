<?php
/**
 * Purchase Order application service boundary (M2-A skeleton).
 *
 * Declares the sole intended mutation entry point for M2-C. Methods that perform
 * lifecycle transitions are stubs that return WP_Error until M2-C implements them.
 * Repository create/read helpers are available for architecture validation only.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO application service boundary.
 */
class WC_Inventory_Overview_PO_Service {

	/**
	 * Create a draft PO header via the repository. Event emission is deferred to M2-C.
	 *
	 * @param array<string,mixed> $data Header data.
	 * @return int|WP_Error
	 */
	public static function create_draft( array $data ) {
		return WC_Inventory_Overview_Purchase_Orders::create_draft( $data );
	}

	/**
	 * Get a purchase order by id.
	 *
	 * @param int $id PO id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		return WC_Inventory_Overview_Purchase_Orders::get( $id );
	}

	/**
	 * Place a draft PO. Implemented in M2-C.
	 *
	 * @param int $id PO id.
	 * @return WP_Error
	 */
	public static function place( int $id ) {
		return self::not_implemented( 'place' );
	}

	/**
	 * Cancel a PO. Implemented in M2-C.
	 *
	 * @param int $id PO id.
	 * @return WP_Error
	 */
	public static function cancel( int $id ) {
		return self::not_implemented( 'cancel' );
	}

	/**
	 * Close a PO short. Implemented in M2-C.
	 *
	 * @param int $id PO id.
	 * @return WP_Error
	 */
	public static function close_short( int $id ) {
		return self::not_implemented( 'close_short' );
	}

	/**
	 * Duplicate a PO into a new draft. Implemented in M2-C.
	 *
	 * @param int $id PO id.
	 * @return WP_Error
	 */
	public static function duplicate( int $id ) {
		return self::not_implemented( 'duplicate' );
	}

	/**
	 * Return a reserved-for-later WP_Error for unimplemented lifecycle methods.
	 *
	 * @param string $method Method name.
	 * @return WP_Error
	 */
	private static function not_implemented( string $method ) {
		return new WP_Error(
			'wc_io_po_not_implemented',
			sprintf( 'PO service method "%s" is reserved for M2-C and is not implemented in M2-A', $method )
		);
	}
}
