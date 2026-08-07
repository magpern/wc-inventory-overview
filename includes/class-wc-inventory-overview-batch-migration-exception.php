<?php
/**
 * WP_Error-to-Exception bridge for Batch_Migration_Service transaction closures (M6).
 *
 * Mirrors WC_Inventory_Overview_Goods_Receipt_Posting_Exception's role for M4/M5:
 * WC_Inventory_Overview_DB_Transaction::run() only catches Exception, never WP_Error
 * or Throwable, so every fallible call inside a migrate_batch()/rollback_batch()
 * transaction closure must be routed through
 * WC_Inventory_Overview_Batch_Migration_Service::throw_if_error() so a WP_Error never
 * silently returns from inside an open transaction (which would commit a partial
 * migration). A dedicated class, not a reuse of the Goods Receipt posting exception,
 * because migration is a distinct write path with its own Invariant M6-1 (one
 * transaction per batch) — see the M6 implementation plan's Migration invariants.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wraps a WP_Error as a throwable Exception, preserving the original error.
 */
final class WC_Inventory_Overview_Batch_Migration_Exception extends Exception {

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
