<?php
/**
 * Bulk Draft PO creation orchestrator (M25 §11/§16/§28 WP-M25-2).
 *
 * A pure orchestrator: no $wpdb, no PO_Product_Validator call, no writes to
 * Replenishment_Defaults, and no duplicated Position/needs-reorder/supplier-
 * precedence logic. It only composes calls to
 * WC_Inventory_Overview_Replenishment_Planning_Service::build_plan()
 * (unmodified, called exactly once per commit),
 * WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk(),
 * WC_Inventory_Overview_Replenishment_Item_Lock::acquire()/release(), and
 * WC_Inventory_Overview_PO_Service::create_draft() (unmodified).
 *
 * Internal sequence (§11 steps 5-13, restated for this class's own scope --
 * steps 1-4 of §11 are the admin controller's job, WP-M25-4):
 *   1. Enforce MAX_COMMIT_LINES on the raw submitted line count.
 *   2. Validate shape (product_id/variation_id/qty), return
 *      wc_io_replen_commit_malformed on any structural violation.
 *   3. Normalize to canonical item_post_id, dedup, sort ascending
 *      (§48 Amendment C).
 *   4. Acquire item-level advisory locks in ascending order; lock-timeout
 *      items are excluded from every subsequent step, item-scoped
 *      (§48 Amendment E).
 *   5. Inside try/finally: one build_plan() call scoped to lock-acquired
 *      items -> cross-reference resolved/unresolved -> bulk conflicting-line
 *      check -> group survivors by fresh supplier_id ascending -> per-group
 *      create_draft() with quantities mapped by canonical identity key, never
 *      array position (§48 Amendment D).
 *   6. finally: release every acquired lock, unconditionally.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sole orchestrator of bulk Draft PO creation from a Replenishment Plan
 * selection.
 */
class WC_Inventory_Overview_Replenishment_Commit_Service {

	/**
	 * Maximum number of selected lines accepted per commit (§24), enforced
	 * here and independently at the admin-controller request-shape layer
	 * (WP-M25-4).
	 */
	const MAX_COMMIT_LINES = 100;

	/**
	 * Test-only failure-injection seams (WP-M25-3, §48 Amendment B). Both
	 * gated by WC_IO_PHPUNIT_RUNNING, mirroring
	 * WC_Inventory_Overview_Supplier_Merge_Service's private-flag + public
	 * test-only setter + private checkpoint-firing convention (M17).
	 *
	 * Seam A: fires immediately before the targeted group's real
	 * create_draft() call, having already been established by the fresh
	 * plan rebuild. Mutates fixture state via an injected callback (e.g.
	 * archives the target supplier) but never fabricates a WP_Error itself --
	 * create_draft() must discover the invalid state on its own.
	 *
	 * @var int|null
	 */
	private static $test_seam_a_target_supplier_id = null;

	/**
	 * Seam A's fixture-mutation callback: function( int $supplier_id ): void.
	 *
	 * @var callable|null
	 */
	private static $test_seam_a_callback = null;

	/**
	 * Seam B: 0-based group-processing index after which a RuntimeException
	 * is thrown, immediately after that group's create_draft() has already
	 * returned success but before the next group's call begins.
	 *
	 * @var int|null
	 */
	private static $test_interrupt_after_group_index = null;

	/**
	 * Commit a set of operator-selected replenishment lines.
	 *
	 * @param array<int, array{product_id:int|string, variation_id:int|string, qty:int|float|string}> $items Already
	 *        filtered to "selected" rows by the caller (the admin controller's own §7 two-phase parsing).
	 * @return array{created:array<int,array<string,mixed>>, failed:array<int,array<string,mixed>>, skipped:array<int,array<string,mixed>>}|WP_Error
	 *         WP_Error only for service-boundary/input-shape violations
	 *         (wc_io_replen_commit_too_many, wc_io_replen_commit_malformed).
	 *         Every other outcome -- including zero surviving items, and
	 *         including individual per-group create_draft() failures --
	 *         returns the {created, failed, skipped} array shape.
	 */
	public static function commit( array $items ) {
		if ( count( $items ) > self::MAX_COMMIT_LINES ) {
			return new WP_Error(
				'wc_io_replen_commit_too_many',
				sprintf(
					/* translators: %d: maximum allowed lines per commit. */
					__( 'A single commit may not include more than %d lines.', 'wc-inventory-overview' ),
					self::MAX_COMMIT_LINES
				)
			);
		}

		// §48 Amendment C: canonical item_post_id derivation, validation,
		// dedup (first-submitted-identity's quantity wins on a crafted
		// duplicate -- deterministic, documented), ascending sort. The
		// MAX_COMMIT_LINES cap above is intentionally checked against the
		// raw, pre-dedup count so duplicate identities can never be used to
		// smuggle more distinct lines past the cap, nor can they inflate it.
		$by_item = array();
		foreach ( $items as $raw ) {
			if ( ! is_array( $raw ) || ! array_key_exists( 'product_id', $raw ) || ! array_key_exists( 'variation_id', $raw ) || ! array_key_exists( 'qty', $raw ) ) {
				return new WP_Error( 'wc_io_replen_commit_malformed', __( 'Malformed replenishment commit request.', 'wc-inventory-overview' ) );
			}

			$product_id_raw   = $raw['product_id'];
			$variation_id_raw = $raw['variation_id'];
			$qty_raw          = $raw['qty'];

			if ( ! is_numeric( $product_id_raw ) || ! is_numeric( $variation_id_raw ) || ! is_numeric( $qty_raw ) ) {
				return new WP_Error( 'wc_io_replen_commit_malformed', __( 'Malformed replenishment commit request.', 'wc-inventory-overview' ) );
			}

			$product_id   = (int) $product_id_raw;
			$variation_id = (int) $variation_id_raw;
			$qty          = (float) $qty_raw;

			if ( $product_id < 0 || $variation_id < 0 || ( $product_id <= 0 && $variation_id <= 0 ) ) {
				return new WP_Error( 'wc_io_replen_commit_malformed', __( 'Malformed replenishment commit request.', 'wc-inventory-overview' ) );
			}
			if ( ! is_finite( $qty ) || $qty <= 0 || $qty > 1000000 ) {
				return new WP_Error( 'wc_io_replen_commit_malformed', __( 'Malformed replenishment commit request.', 'wc-inventory-overview' ) );
			}

			$item_post_id = $variation_id > 0 ? $variation_id : $product_id;

			if ( isset( $by_item[ $item_post_id ] ) ) {
				// Duplicate canonical identity in one request (array-index
				// tricks or otherwise) -- collapses to one canonical entry;
				// the first-submitted row's quantity wins. Never double-locked,
				// double-created, or counted twice toward MAX_COMMIT_LINES.
				continue;
			}

			$by_item[ $item_post_id ] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'qty'          => $qty,
			);
		}

		$item_post_ids = array_map( 'intval', array_keys( $by_item ) );
		sort( $item_post_ids, SORT_NUMERIC );

		$created = array();
		$failed  = array();
		$skipped = array();

		if ( empty( $item_post_ids ) ) {
			return array(
				'created' => $created,
				'failed'  => $failed,
				'skipped' => $skipped,
			);
		}

		$acquired     = WC_Inventory_Overview_Replenishment_Item_Lock::acquire( $item_post_ids );
		$acquired_set = array_flip( $acquired );

		foreach ( $item_post_ids as $item_post_id ) {
			if ( ! isset( $acquired_set[ $item_post_id ] ) ) {
				$skipped[] = self::skip_entry( $by_item[ $item_post_id ], 'concurrent_commit_in_progress' );
			}
		}

		try {
			if ( ! empty( $acquired ) ) {
				self::process_locked_items( $acquired, $by_item, $created, $failed, $skipped );
			}
		} finally {
			WC_Inventory_Overview_Replenishment_Item_Lock::release( $acquired );
		}

		return array(
			'created' => $created,
			'failed'  => $failed,
			'skipped' => $skipped,
		);
	}

	/**
	 * Steps 7-12 of §11: bounded revalidation through per-group creation,
	 * for the already lock-acquired item set. Broken out of commit() only
	 * for readability -- still a single call site, still inside commit()'s
	 * own try/finally.
	 *
	 * @param int[]                          $acquired Lock-acquired canonical item ids.
	 * @param array<int,array<string,mixed>> $by_item  item_post_id => submitted {product_id, variation_id, qty}.
	 * @param array<int,array<string,mixed>> $created  By reference; appended to.
	 * @param array<int,array<string,mixed>> $failed   By reference; appended to.
	 * @param array<int,array<string,mixed>> $skipped  By reference; appended to.
	 */
	private static function process_locked_items( array $acquired, array $by_item, array &$created, array &$failed, array &$skipped ): void {
		// INV-M25-13: exactly one build_plan() call per commit, scoped to
		// the lock-acquired items only (never per-line, never per-group).
		$plan = WC_Inventory_Overview_Replenishment_Planning_Service::build_plan( array(), $acquired );

		$resolved = array();
		foreach ( $plan['groups'] as $group ) {
			foreach ( $group['lines'] as $line ) {
				$item_post_id              = $line['variation_id'] > 0 ? $line['variation_id'] : $line['product_id'];
				$resolved[ $item_post_id ] = array(
					'supplier_id'   => (int) $group['supplier_id'],
					'supplier_name' => (string) $group['supplier_name'],
					'currency'      => (string) $group['currency'],
					'line'          => $line,
				);
			}
		}

		$unresolved_reason = array();
		foreach ( $plan['unresolved'] as $u ) {
			$item_post_id                       = $u['variation_id'] > 0 ? $u['variation_id'] : $u['product_id'];
			$unresolved_reason[ $item_post_id ] = $u['reason'];
		}

		$survivors = array();
		foreach ( $acquired as $item_post_id ) {
			if ( isset( $resolved[ $item_post_id ] ) ) {
				$survivors[ $item_post_id ] = $resolved[ $item_post_id ];
				continue;
			}
			if ( isset( $unresolved_reason[ $item_post_id ] ) ) {
				$skipped[] = self::skip_entry( $by_item[ $item_post_id ], $unresolved_reason[ $item_post_id ] );
				continue;
			}
			// Present in the request and lock-acquired, but absent from
			// build_plan()'s output entirely (BR-M25-7): either the item no
			// longer needs reordering (Position improved since render) or it
			// can no longer be resolved to a real product/variation at all.
			// A cheap existence check (no Position/needs-reorder logic of
			// any kind, INV-M25-3/4) distinguishes the two skip reasons.
			$exists    = false !== wc_get_product( $item_post_id );
			$skipped[] = self::skip_entry( $by_item[ $item_post_id ], $exists ? 'no_longer_needs_reorder' : 'not_found' );
		}

		if ( empty( $survivors ) ) {
			return;
		}

		// §16/§48 Amendment A: one bulk conflicting-line check, under lock,
		// against the exact frozen conflict-status set (draft, placed,
		// partially_received).
		$survivor_product_ids   = array();
		$survivor_variation_ids = array();
		foreach ( $survivors as $data ) {
			if ( $data['line']['variation_id'] > 0 ) {
				$survivor_variation_ids[] = $data['line']['variation_id'];
			} else {
				$survivor_product_ids[] = $data['line']['product_id'];
			}
		}
		$conflicting     = WC_Inventory_Overview_Purchase_Order_Lines::list_open_or_draft_item_ids_bulk( $survivor_product_ids, $survivor_variation_ids );
		$conflicting_set = array_flip( $conflicting );

		$eligible = array();
		foreach ( $survivors as $item_post_id => $data ) {
			if ( isset( $conflicting_set[ $item_post_id ] ) ) {
				$skipped[] = self::skip_entry( $by_item[ $item_post_id ], 'already_has_open_po_line' );
				continue;
			}
			$eligible[ $item_post_id ] = $data;
		}

		if ( empty( $eligible ) ) {
			return;
		}

		// §17: group by fresh supplier_id, ascending.
		$groups = array();
		foreach ( $eligible as $item_post_id => $data ) {
			$supplier_id = $data['supplier_id'];
			if ( ! isset( $groups[ $supplier_id ] ) ) {
				$groups[ $supplier_id ] = array(
					'supplier_id'   => $supplier_id,
					'supplier_name' => $data['supplier_name'],
					'currency'      => $data['currency'],
					'item_ids'      => array(),
				);
			}
			$groups[ $supplier_id ]['item_ids'][] = $item_post_id;
		}
		ksort( $groups, SORT_NUMERIC );

		$group_index = 0;
		foreach ( $groups as $supplier_id => $group ) {
			self::maybe_fire_seam_a( $supplier_id );

			$lines_payload = array();
			foreach ( $group['item_ids'] as $item_post_id ) {
				// §48 Amendment D: quantity mapped to identity by exact
				// canonical key, never by array index/order.
				$lines_payload[] = array(
					'product_id'   => $eligible[ $item_post_id ]['line']['product_id'],
					'variation_id' => $eligible[ $item_post_id ]['line']['variation_id'],
					'qty_ordered'  => $by_item[ $item_post_id ]['qty'],
				);
			}

			$header = array(
				'supplier_id' => $supplier_id,
				'note'        => 'Created from Replenishment Planning',
			);

			$result = WC_Inventory_Overview_PO_Service::create_draft( $header, $lines_payload );

			if ( is_wp_error( $result ) ) {
				foreach ( $group['item_ids'] as $item_post_id ) {
					$failed[] = array(
						'product_id'    => $eligible[ $item_post_id ]['line']['product_id'],
						'variation_id'  => $eligible[ $item_post_id ]['line']['variation_id'],
						'name'          => $eligible[ $item_post_id ]['line']['name'],
						'supplier_id'   => $supplier_id,
						'supplier_name' => $group['supplier_name'],
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
					);
				}
			} else {
				foreach ( $group['item_ids'] as $item_post_id ) {
					$created[] = array(
						'product_id'    => $eligible[ $item_post_id ]['line']['product_id'],
						'variation_id'  => $eligible[ $item_post_id ]['line']['variation_id'],
						'name'          => $eligible[ $item_post_id ]['line']['name'],
						'po_id'         => (int) $result,
						'supplier_id'   => $supplier_id,
						'supplier_name' => $group['supplier_name'],
					);
				}
			}

			self::maybe_interrupt( $group_index );
			++$group_index;
		}
	}

	/**
	 * Build a skipped[] entry.
	 *
	 * @param array{product_id:int,variation_id:int,qty:float} $submitted Submitted identity.
	 * @param string                                           $reason    Skip reason code.
	 * @return array<string,mixed>
	 */
	private static function skip_entry( array $submitted, string $reason ): array {
		return array(
			'product_id'   => $submitted['product_id'],
			'variation_id' => $submitted['variation_id'],
			'reason'       => $reason,
		);
	}

	/**
	 * Test-only: arm Seam A (WP-M25-3, §48 Amendment B). No-op unless
	 * WC_IO_PHPUNIT_RUNNING is defined.
	 *
	 * @param int|null      $target_supplier_id Supplier id of the group to target, or null to disarm.
	 * @param callable|null $callback           function( int $supplier_id ): void, invoked once, immediately before that group's create_draft() call.
	 */
	public static function set_test_seam_a( ?int $target_supplier_id, ?callable $callback = null ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		self::$test_seam_a_target_supplier_id = $target_supplier_id;
		self::$test_seam_a_callback           = $callback;
	}

	/**
	 * Test-only: arm Seam B (WP-M25-3, §48 Amendment B). No-op unless
	 * WC_IO_PHPUNIT_RUNNING is defined.
	 *
	 * @param int|null $after_group_index 0-based group-processing index after which to throw, or null to disarm.
	 */
	public static function set_test_interrupt_after_group_index( ?int $after_group_index ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		self::$test_interrupt_after_group_index = $after_group_index;
	}

	/**
	 * Test-only: disarm both seams. Safe to call unconditionally from a
	 * test's tearDown().
	 */
	public static function reset_test_seams(): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		self::$test_seam_a_target_supplier_id   = null;
		self::$test_seam_a_callback             = null;
		self::$test_interrupt_after_group_index = null;
	}

	/**
	 * Fire Seam A if armed for this supplier. No-op in production.
	 *
	 * @param int $supplier_id Supplier id about to be processed.
	 */
	private static function maybe_fire_seam_a( int $supplier_id ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		if ( null === self::$test_seam_a_target_supplier_id || self::$test_seam_a_target_supplier_id !== $supplier_id ) {
			return;
		}
		$callback = self::$test_seam_a_callback;
		// Disarm before firing -- this seam fires at most once per commit().
		self::$test_seam_a_target_supplier_id = null;
		self::$test_seam_a_callback           = null;
		if ( is_callable( $callback ) ) {
			$callback( $supplier_id );
		}
	}

	/**
	 * Fire Seam B if armed for this group index. No-op in production.
	 *
	 * @param int $group_index 0-based index of the group just processed.
	 * @throws RuntimeException Test-injected catastrophic interruption.
	 */
	private static function maybe_interrupt( int $group_index ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		if ( null === self::$test_interrupt_after_group_index || self::$test_interrupt_after_group_index !== $group_index ) {
			return;
		}
		self::$test_interrupt_after_group_index = null;
		throw new RuntimeException( 'wc_io_test_injected_interrupt:after_group_' . $group_index ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test-only, fixed literal string, never user input.
	}
}
