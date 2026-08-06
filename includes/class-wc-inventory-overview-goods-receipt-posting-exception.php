<?php
/**
 * WP_Error-to-Exception bridge for Goods_Receipt_Service transaction closures (M4).
 *
 * WC_Inventory_Overview_DB_Transaction::run() only catches Exception, never WP_Error
 * or Throwable — every fallible call inside a post()/void() transaction closure must
 * be routed through Goods_Receipt_Service::throw_if_error() so a WP_Error never
 * silently returns from inside an open transaction (which would commit a partial
 * mutation). See the M4 implementation plan's Transaction model section.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a WP_Error as a throwable Exception, preserving the original error.
 */
final class WC_Inventory_Overview_Goods_Receipt_Posting_Exception extends Exception {

	/**
	 * The original error being bridged.
	 *
	 * @var WP_Error
	 */
	private $wp_error;

	/**
	 * Wrap a WP_Error as a throwable exception.
	 *
	 * @param WP_Error $e Original error.
	 */
	public function __construct( WP_Error $e ) {
		$this->wp_error = $e;
		parent::__construct( $e->get_error_message() );
	}

	/**
	 * The original, unwrapped error.
	 *
	 * @return WP_Error
	 */
	public function get_wp_error(): WP_Error {
		return $this->wp_error;
	}
}
