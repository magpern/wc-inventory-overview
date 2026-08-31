<?php
/**
 * Bridges WP_Error into Exception for Stock Adjustment transactions (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exception wrapping WP_Error for transaction rollback.
 */
class WC_Inventory_Overview_Stock_Adjustment_Posting_Exception extends Exception {

	/**
	 * @var WP_Error
	 */
	private $wp_error;

	/**
	 * @param WP_Error $error Error.
	 */
	public function __construct( WP_Error $error ) {
		$this->wp_error = $error;
		parent::__construct( $error->get_error_message() );
	}

	/**
	 * @return WP_Error
	 */
	public function get_wp_error(): WP_Error {
		return $this->wp_error;
	}
}
