<?php
/**
 * Item-level advisory locking for bulk replenishment commits (M25 §16).
 *
 * The plugin's first use of MariaDB named advisory locks (GET_LOCK/RELEASE_LOCK)
 * -- a schema-free, session-scoped primitive purpose-built for serializing an
 * arbitrary business key (here, a purchasable item's canonical item_post_id)
 * across a logical operation, since there is no `wc_io`-owned row representing
 * "a catalog item" that the plugin's existing FOR UPDATE convention could lock
 * (§16 rationale).
 *
 * Lock name convention: 'wc_io_replen_item_' . $item_post_id, where
 * $item_post_id is the same variation_id > 0 ? variation_id : product_id
 * convention used throughout M22-M24.
 *
 * Callers are responsible for canonical normalization (dedup + ascending
 * sort, §48 Amendment C) before calling acquire() -- this class defensively
 * re-sorts ascending regardless, so INV-M25-20 (ascending lock order) holds
 * even if a future caller violates that contract, but performs no
 * deduplication of its own (a caller-side concern, since only the caller
 * knows which duplicate's quantity should win).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * MariaDB advisory-lock wrapper for per-item replenishment-commit serialization.
 */
class WC_Inventory_Overview_Replenishment_Item_Lock {

	/**
	 * Default GET_LOCK() timeout in seconds.
	 */
	const DEFAULT_TIMEOUT_SECONDS = 5;

	/**
	 * Attempt to acquire an advisory lock for each given item, in ascending
	 * numeric order.
	 *
	 * Never throws and never fails the whole batch: an id whose lock could
	 * not be acquired within the timeout is simply absent from the returned
	 * array (§48 Amendment E -- lock failure is item-scoped, not batch-fatal).
	 *
	 * @param int[] $item_post_ids   Canonical item_post_ids (caller-normalized).
	 * @param int   $timeout_seconds GET_LOCK() timeout, per id.
	 * @return int[] Subset of $item_post_ids that were actually locked, ascending order.
	 */
	public static function acquire( array $item_post_ids, int $timeout_seconds = self::DEFAULT_TIMEOUT_SECONDS ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'absint', $item_post_ids ) ) );
		sort( $ids, SORT_NUMERIC );

		$acquired = array();
		foreach ( $ids as $item_post_id ) {
			if ( $item_post_id <= 0 ) {
				continue;
			}
			$result = $wpdb->get_var(
				$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $item_post_id ), $timeout_seconds )
			);
			if ( '1' === (string) $result ) {
				$acquired[] = $item_post_id;
			}
		}

		return $acquired;
	}

	/**
	 * Release advisory locks for the given items. Idempotent -- releasing an
	 * id this session never held (or already released) is a harmless no-op
	 * (RELEASE_LOCK() returns 0 or NULL in that case, never an error).
	 *
	 * MySQL/MariaDB also auto-releases any GET_LOCK() held by a connection
	 * when that connection closes, providing a safety net against orphaned
	 * locks even if this method is somehow never reached.
	 *
	 * @param int[] $item_post_ids Item ids to release.
	 */
	public static function release( array $item_post_ids ): void {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'absint', $item_post_ids ) ) );
		foreach ( $ids as $item_post_id ) {
			if ( $item_post_id <= 0 ) {
				continue;
			}
			$wpdb->query(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $item_post_id ) )
			);
		}
	}

	/**
	 * Deterministic lock name for an item.
	 *
	 * @param int $item_post_id Canonical item id.
	 */
	private static function lock_name( int $item_post_id ): string {
		return 'wc_io_replen_item_' . $item_post_id;
	}
}
