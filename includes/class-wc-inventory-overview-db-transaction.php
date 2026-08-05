<?php
/**
 * Database transaction helper for WC Inventory Overview
 *
 * Provides a safe, reusable wrapper for MySQL/MariaDB transactions with SAVEPOINT support.
 * Designed to be composed by services that require atomic multi-statement mutations.
 *
 * First real consumer: M2 Purchase Order service (create/place/cancel/close/duplicate and
 * line mutations). Goods receipts (M4/M5) will reuse the same helper.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Inventory_Overview_DB_Transaction
 *
 * Wraps wpdb transaction methods with explicit error handling, savepoint support,
 * and a convenience `run()` method for lambda-based work.
 *
 * Usage:
 *
 *   $txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );
 *   $txn->run( function() {
 *       // do work that might throw exceptions
 *       some_stock_mutation();
 *       some_other_mutation();
 *   });
 *   // If any exception is thrown, $txn->run() automatically rolls back and rethrows.
 *   // On success, all mutations are committed.
 */
class WC_Inventory_Overview_DB_Transaction {

	/**
	 * The global wpdb instance.
	 *
	 * @var wpdb
	 */
	private $wpdb;

	/**
	 * Whether a transaction is currently active.
	 *
	 * @var bool
	 */
	private $in_transaction = false;

	/**
	 * Stack of savepoint levels for nested transactions.
	 *
	 * @var int
	 */
	private $savepoint_level = 0;

	/**
	 * Constructor.
	 *
	 * @param wpdb $wpdb The WordPress database object.
	 */
	public function __construct( wpdb $wpdb ) {
		$this->wpdb = $wpdb;
	}

	/**
	 * Begin a transaction.
	 *
	 * For nested calls, creates a SAVEPOINT instead.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function begin(): bool {
		if ( $this->in_transaction ) {
			// Nested transaction: use savepoint.
			$this->savepoint_level++;
			$savepoint_name = 'sp_' . $this->savepoint_level;
			$this->wpdb->query( "SAVEPOINT {$savepoint_name}" );
			return true;
		}

		// Start a new top-level transaction.
		$result = $this->wpdb->query( 'START TRANSACTION' );
		if ( false !== $result ) {
			$this->in_transaction = true;
			$this->savepoint_level = 0;
			return true;
		}

		return false;
	}

	/**
	 * Commit the current transaction.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function commit(): bool {
		if ( ! $this->in_transaction ) {
			return false; // No transaction active.
		}

		if ( $this->savepoint_level > 0 ) {
			// Pop the savepoint.
			$this->savepoint_level--;
			return true;
		}

		// Commit the top-level transaction.
		$result = $this->wpdb->query( 'COMMIT' );
		if ( false !== $result ) {
			$this->in_transaction = false;
			return true;
		}

		return false;
	}

	/**
	 * Roll back the current transaction.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function rollback(): bool {
		if ( ! $this->in_transaction ) {
			return false; // No transaction active.
		}

		if ( $this->savepoint_level > 0 ) {
			// Rollback to the current savepoint.
			$savepoint_name = 'sp_' . $this->savepoint_level;
			$this->wpdb->query( "ROLLBACK TO SAVEPOINT {$savepoint_name}" );
			$this->savepoint_level--;
			return true;
		}

		// Rollback the top-level transaction.
		$result = $this->wpdb->query( 'ROLLBACK' );
		if ( false !== $result ) {
			$this->in_transaction = false;
			$this->savepoint_level = 0;
			return true;
		}

		return false;
	}

	/**
	 * Check if a transaction is currently active.
	 *
	 * @return bool True if a transaction is active.
	 */
	public function is_active(): bool {
		return $this->in_transaction;
	}

	/**
	 * Execute a callable within a transaction, automatically rolling back on exception.
	 *
	 * @param callable $work A callable that performs the work. Should throw an exception on failure.
	 * @return mixed The return value of $work.
	 * @throws Exception If $work throws an exception, or if transaction operations fail.
	 */
	public function run( callable $work ) {
		try {
			$this->begin();
			$result = $work();
			$this->commit();
			return $result;
		} catch ( Exception $e ) {
			if ( $this->in_transaction ) {
				$this->rollback();
			}
			throw $e;
		}
	}
}
