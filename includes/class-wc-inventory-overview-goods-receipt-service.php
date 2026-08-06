<?php
/**
 * Goods Receipt application service — sole inventory mutation entry point (M4).
 *
 * WC_Inventory_Overview_Goods_Receipt_Service is the ONLY public orchestration path
 * for every M4 inventory mutation. No controller, repository, UI class, AJAX
 * handler, REST endpoint, CLI command, import, or future integration may directly
 * call Restock_Service::apply_purchase_line_change() / apply_purchase_line_reversal().
 * All persistence during post() and void() is orchestrated here, inside exactly one
 * WC_Inventory_Overview_DB_Transaction::run() closure per call. This class never
 * calls WC_Inventory_Overview_DB_Transaction::rollback() directly — rollback is
 * exclusively run()'s job, triggered only by a thrown Exception (see throw_if_error()).
 *
 * Transaction lifetime: begins immediately before persistence (after cheap,
 * side-effect-free pre-validation and idempotency-token consumption have already
 * passed), ends immediately after persistence. Cache/transient invalidation and all
 * other irreversible side effects happen strictly after a successful commit, never
 * inside the closure.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt application service.
 */
class WC_Inventory_Overview_Goods_Receipt_Service {

	/**
	 * Bridge a fallible call's WP_Error return into a thrown Exception so it can
	 * never silently escape an open WC_Inventory_Overview_DB_Transaction::run()
	 * closure. Non-error values pass through unchanged.
	 *
	 * @param mixed $value Return value of a fallible call.
	 * @return mixed $value, when not a WP_Error.
	 * @throws WC_Inventory_Overview_Goods_Receipt_Posting_Exception When $value is a WP_Error.
	 */
	private static function throw_if_error( $value ) {
		if ( is_wp_error( $value ) ) {
			throw new WC_Inventory_Overview_Goods_Receipt_Posting_Exception( $value ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $value is a WP_Error object being wrapped, not output.
		}
		return $value;
	}

	/**
	 * Create a draft Goods Receipt (header + lines + landed costs) from parsed POST.
	 * Zero stock effect — drafts never touch WooCommerce stock or costing meta.
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return int|WP_Error New receipt id.
	 */
	public static function create_draft_from_post( array $src ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT ) ) {
			return new WP_Error( 'wc_io_gr_forbidden', __( 'You do not have permission to create Goods Receipts.', 'wc-inventory-overview' ) );
		}

		$preview = WC_Inventory_Overview_Goods_Receipt_Costing::build_preview_from_post( $src );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$supplier_id = (int) $preview['header']['supplier_id'];
		$supplier    = null;
		if ( $supplier_id > 0 ) {
			$supplier = WC_Inventory_Overview_Suppliers::get( $supplier_id );
			if ( is_wp_error( $supplier ) ) {
				return new WP_Error( 'wc_io_gr_supplier', __( 'Selected supplier could not be found.', 'wc-inventory-overview' ) );
			}
		}

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$receipt_id = $txn->run(
				function () use ( $preview, $supplier_id, $supplier ) {
					$header_row = array(
						'supplier_id'              => $supplier_id > 0 ? $supplier_id : null,
						'supplier_name_snapshot'   => $supplier ? (string) $supplier['name'] : null,
						'currency'                 => $preview['fx']['currency'],
						'exchange_rate_to_eur'     => $preview['fx']['exchange_rate_to_eur'],
						'exchange_rate_date'       => $preview['fx']['exchange_rate_date'],
						'product_subtotal_entered' => $preview['product_subtotal_entered'],
						'landed_total_entered'     => $preview['landed_total_entered'],
						'receipt_total_entered'    => $preview['receipt_total_entered'],
						'product_subtotal'         => $preview['product_subtotal'],
						'landed_total'             => $preview['landed_total'],
						'receipt_total'            => $preview['receipt_total'],
						'reference'                => $preview['header']['reference'],
						'note'                     => $preview['header']['note'],
					);

					$receipt_id = self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::create_draft( $header_row ) );

					self::persist_draft_lines_and_costs( $receipt_id, $preview );

					return $receipt_id;
				}
			);
		} catch ( WC_Inventory_Overview_Goods_Receipt_Posting_Exception $e ) {
			return $e->get_wp_error();
		}

		return $receipt_id;
	}

	/**
	 * Update a draft Goods Receipt's header/lines/costs from parsed POST. Draft-only
	 * (WC_Inventory_Overview_Goods_Receipt_Lifecycle::assert_editable()). Replaces all
	 * lines/costs (drafts have no partial-edit history requirement).
	 *
	 * @param int                  $id  Receipt id.
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return int|WP_Error Receipt id.
	 */
	public static function update_draft_from_post( int $id, array $src ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT ) ) {
			return new WP_Error( 'wc_io_gr_forbidden', __( 'You do not have permission to edit Goods Receipts.', 'wc-inventory-overview' ) );
		}

		$preview = WC_Inventory_Overview_Goods_Receipt_Costing::build_preview_from_post( $src );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		$supplier_id = (int) $preview['header']['supplier_id'];
		$supplier    = null;
		if ( $supplier_id > 0 ) {
			$supplier = WC_Inventory_Overview_Suppliers::get( $supplier_id );
			if ( is_wp_error( $supplier ) ) {
				return new WP_Error( 'wc_io_gr_supplier', __( 'Selected supplier could not be found.', 'wc-inventory-overview' ) );
			}
		}

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $preview, $supplier_id, $supplier ) {
					$receipt = self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::get( $id ) );
					self::throw_if_error( WC_Inventory_Overview_Goods_Receipt_Lifecycle::assert_editable( $receipt['status'] ) );

					$header_row = array(
						'supplier_id'              => $supplier_id > 0 ? $supplier_id : null,
						'supplier_name_snapshot'   => $supplier ? (string) $supplier['name'] : null,
						'currency'                 => $preview['fx']['currency'],
						'exchange_rate_to_eur'     => $preview['fx']['exchange_rate_to_eur'],
						'exchange_rate_date'       => $preview['fx']['exchange_rate_date'],
						'product_subtotal_entered' => $preview['product_subtotal_entered'],
						'landed_total_entered'     => $preview['landed_total_entered'],
						'receipt_total_entered'    => $preview['receipt_total_entered'],
						'product_subtotal'         => $preview['product_subtotal'],
						'landed_total'             => $preview['landed_total'],
						'receipt_total'            => $preview['receipt_total'],
						'reference'                => $preview['header']['reference'],
						'note'                     => $preview['header']['note'],
					);
					self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::update_fields( $id, $header_row ) );

					WC_Inventory_Overview_Receipt_Lines::delete_for_receipt( $id );
					WC_Inventory_Overview_Receipt_Costs::delete_for_receipt( $id );
					self::persist_draft_lines_and_costs( $id, $preview );
				}
			);
		} catch ( WC_Inventory_Overview_Goods_Receipt_Posting_Exception $e ) {
			return $e->get_wp_error();
		}

		return $id;
	}

	/**
	 * Hard-delete a draft Goods Receipt (header, lines, costs). Draft-only.
	 *
	 * @param int $id Receipt id.
	 * @return true|WP_Error
	 */
	public static function delete_draft( int $id ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::DELETE_RECEIPT ) ) {
			return new WP_Error( 'wc_io_gr_forbidden', __( 'You do not have permission to delete Goods Receipts.', 'wc-inventory-overview' ) );
		}

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id ) {
					$receipt = self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::get( $id ) );
					if ( ! WC_Inventory_Overview_Goods_Receipt_Lifecycle::can_delete( $receipt['status'] ) ) {
						self::throw_if_error( new WP_Error( 'wc_io_gr_delete_not_draft', __( 'Only draft Goods Receipts may be deleted.', 'wc-inventory-overview' ) ) );
					}
					WC_Inventory_Overview_Receipt_Lines::delete_for_receipt( $id );
					WC_Inventory_Overview_Receipt_Costs::delete_for_receipt( $id );
					self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::delete_draft_record( $id ) );
				}
			);
		} catch ( WC_Inventory_Overview_Goods_Receipt_Posting_Exception $e ) {
			return $e->get_wp_error();
		}

		return true;
	}

	/**
	 * Post a draft Goods Receipt: the transactional stock + weighted-average-cost
	 * mutation. Sole entry point for M4 inventory mutation.
	 *
	 * Idempotency token is consumed as the very first statement — a request whose
	 * token fails to consume performs zero DB work of any kind. The compare-and-swap
	 * status UPDATE is the first write inside the transaction (fail-fast on a
	 * concurrent duplicate before any stock mutation is attempted).
	 *
	 * @param int    $id    Receipt id.
	 * @param string $token One-shot request token (context 'gr_post').
	 * @return array<string, mixed>|WP_Error Posted receipt header row.
	 */
	public static function post( int $id, string $token ) {
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, 'gr_post' ) ) {
			return new WP_Error( 'wc_io_gr_token', __( 'This form has already been submitted or has expired. Please go back and try again.', 'wc-inventory-overview' ) );
		}

		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT ) ) {
			return new WP_Error( 'wc_io_gr_forbidden', __( 'You do not have permission to post Goods Receipts.', 'wc-inventory-overview' ) );
		}

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT !== $receipt['status'] ) {
			return new WP_Error( 'wc_io_gr_not_draft', __( 'Only a draft Goods Receipt can be posted.', 'wc-inventory-overview' ) );
		}
		$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $id );
		if ( empty( $lines ) ) {
			return new WP_Error( 'wc_io_gr_no_lines', __( 'Add at least one line before posting.', 'wc-inventory-overview' ) );
		}

		$user_id             = get_current_user_id();
		$touched_product_ids = array();

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $lines, $user_id, &$touched_product_ids ) {
					$affected = WC_Inventory_Overview_Goods_Receipts::compare_and_swap_post( $id, $user_id );
					if ( 1 !== $affected ) {
						self::throw_if_error(
							new WP_Error( 'wc_io_gr_post_race', __( 'This Goods Receipt is no longer a draft (it may already have been posted).', 'wc-inventory-overview' ) )
						);
					}

					$receipt_row = self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::get( $id ) );
					$supplier_id = isset( $receipt_row['supplier_id'] ) ? (int) $receipt_row['supplier_id'] : 0;

					foreach ( $lines as $line ) {
						$purchasable_id = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];

						$applied = self::throw_if_error(
							WC_Inventory_Overview_Restock_Service::apply_purchase_line_change(
								$purchasable_id,
								(float) $line['qty'],
								(float) $line['true_unit_cost']
							)
						);

						self::throw_if_error(
							WC_Inventory_Overview_Receipt_Lines::persist_posting_snapshot( (int) $line['id'], self::snapshot_result_from_applied( $applied ) )
						);

						$movement_row = array_merge(
							$applied['movement'],
							array(
								'total_value'   => (float) wc_format_decimal( $line['true_line_cost'], 4 ),
								'unit_cost'     => (float) wc_format_decimal( $line['true_unit_cost'], 6 ),
								'supplier_name' => (string) ( $receipt_row['supplier_name_snapshot'] ?? '' ),
								'note'          => sprintf( 'Goods Receipt %s', $receipt_row['receipt_number'] ),
								'reference_id'  => $id,
								'supplier_id'   => $supplier_id,
								'user_id'       => $user_id,
							)
						);

						$movement_ok = WC_Inventory_Overview_Movements::insert_goods_receipt( $movement_row );
						if ( ! $movement_ok ) {
							self::throw_if_error( new WP_Error( 'wc_io_gr_movement', __( 'Could not write movement log.', 'wc-inventory-overview' ) ) );
						}

						$touched_product_ids[] = $purchasable_id;
					}
				}
			);
		} catch ( WC_Inventory_Overview_Goods_Receipt_Posting_Exception $e ) {
			// A rolled-back Restock_Service call already ran WC_Product::save(), which
			// wrote through WordPress's own object cache (wp_cache_set() inside
			// update_post_meta()) synchronously — a write the SQL ROLLBACK cannot
			// reach, since the object cache (Redis on this deployment) is not part of
			// the MySQL transaction. Without this, a failed post leaves the cache
			// showing stock/cost values that were never actually committed. This is
			// pure invalidation (forcing a cold re-read of the now-correctly-rolled-
			// back row), not an announcement of a mutation that happened — safe
			// regardless of commit/rollback outcome, unlike the commit-only rule for
			// genuinely irreversible side effects (webhooks, emails, hooks).
			self::invalidate_product_caches( $touched_product_ids );
			return $e->get_wp_error();
		}

		self::invalidate_product_caches( $touched_product_ids );

		return WC_Inventory_Overview_Goods_Receipts::get( $id );
	}

	/**
	 * Void a posted Goods Receipt: transactional, current-state-relative reversal of
	 * only this receipt's own contribution.
	 *
	 * @param int    $id          Receipt id.
	 * @param string $void_reason Free-text reason (required).
	 * @param string $token       One-shot request token (context 'gr_void').
	 * @return array<string, mixed>|WP_Error Voided receipt header row.
	 */
	public static function void( int $id, string $void_reason, string $token ) {
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, 'gr_void' ) ) {
			return new WP_Error( 'wc_io_gr_token', __( 'This form has already been submitted or has expired. Please go back and try again.', 'wc-inventory-overview' ) );
		}

		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_RECEIPT ) ) {
			return new WP_Error( 'wc_io_gr_forbidden', __( 'You do not have permission to void Goods Receipts.', 'wc-inventory-overview' ) );
		}

		$void_reason = trim( (string) $void_reason );
		if ( '' === $void_reason ) {
			return new WP_Error( 'wc_io_gr_void_reason', __( 'A void reason is required.', 'wc-inventory-overview' ) );
		}

		$receipt = WC_Inventory_Overview_Goods_Receipts::get( $id );
		if ( is_wp_error( $receipt ) ) {
			return $receipt;
		}
		if ( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED !== $receipt['status'] ) {
			return new WP_Error( 'wc_io_gr_not_posted', __( 'Only a posted Goods Receipt can be voided.', 'wc-inventory-overview' ) );
		}
		$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $id );

		$user_id             = get_current_user_id();
		$touched_product_ids = array();

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $lines, $user_id, $void_reason, &$touched_product_ids ) {
					$affected = WC_Inventory_Overview_Goods_Receipts::compare_and_swap_void( $id, $user_id, $void_reason );
					if ( 1 !== $affected ) {
						self::throw_if_error(
							new WP_Error( 'wc_io_gr_void_race', __( 'This Goods Receipt is no longer posted (it may already have been voided).', 'wc-inventory-overview' ) )
						);
					}

					$receipt_row = self::throw_if_error( WC_Inventory_Overview_Goods_Receipts::get( $id ) );
					$supplier_id = isset( $receipt_row['supplier_id'] ) ? (int) $receipt_row['supplier_id'] : 0;

					foreach ( $lines as $line ) {
						$purchasable_id = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];

						$reversal_qty   = (float) $line['qty'];
						$reversal_value = (float) wc_format_decimal( $reversal_qty * (float) $line['true_unit_cost'], 4 );

						$applied = self::throw_if_error(
							WC_Inventory_Overview_Restock_Service::apply_purchase_line_reversal(
								$purchasable_id,
								$reversal_qty,
								$reversal_value
							)
						);

						$movement_row = array_merge(
							$applied['movement'],
							array(
								'supplier_name' => (string) ( $receipt_row['supplier_name_snapshot'] ?? '' ),
								'note'          => sprintf( 'Void of Goods Receipt %s', $receipt_row['receipt_number'] ),
								'reference_id'  => $id,
								'supplier_id'   => $supplier_id,
								'user_id'       => $user_id,
							)
						);

						$movement_ok = WC_Inventory_Overview_Movements::insert_goods_receipt_void( $movement_row );
						if ( ! $movement_ok ) {
							self::throw_if_error( new WP_Error( 'wc_io_gr_movement', __( 'Could not write movement log.', 'wc-inventory-overview' ) ) );
						}

						$touched_product_ids[] = $purchasable_id;
					}
				}
			);
		} catch ( WC_Inventory_Overview_Goods_Receipt_Posting_Exception $e ) {
			// See the identical comment in post() — a rolled-back reversal already
			// wrote through the object cache and must be invalidated even on failure.
			self::invalidate_product_caches( $touched_product_ids );
			return $e->get_wp_error();
		}

		self::invalidate_product_caches( $touched_product_ids );

		return WC_Inventory_Overview_Goods_Receipts::get( $id );
	}

	/**
	 * Get a receipt by id.
	 *
	 * @param int $id Receipt id.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function get( int $id ) {
		return WC_Inventory_Overview_Goods_Receipts::get( $id );
	}

	/**
	 * Persist a preview's lines and landed cost rows as draft rows. Draft rows carry
	 * their computed true_unit_cost/true_line_cost (needed at post time) but leave
	 * old_stock/new_stock/average/value at their zero defaults — those are only
	 * known and persisted at posting time (persist_posting_snapshot()), never at
	 * draft-creation/edit time (zero stock effect invariant).
	 *
	 * @param int                  $receipt_id Receipt id.
	 * @param array<string, mixed> $preview    Output of Goods_Receipt_Costing::build_preview_from_post().
	 */
	private static function persist_draft_lines_and_costs( int $receipt_id, array $preview ): void {
		foreach ( $preview['lines'] as $index => $line ) {
			$identity = self::resolve_product_identity( (int) $line['product_id'] );

			self::throw_if_error(
				WC_Inventory_Overview_Receipt_Lines::create(
					$receipt_id,
					array(
						'line_index'              => $index,
						'product_id'              => $identity['product_id'],
						'variation_id'            => $identity['variation_id'],
						'sku_snapshot'            => $identity['sku_snapshot'],
						'name_snapshot'           => $identity['name_snapshot'],
						'qty'                     => $line['qty'],
						'entered_currency'        => $line['entered_currency'],
						'exchange_rate_to_eur'    => $line['exchange_rate_to_eur'],
						'entered_unit_cost'       => $line['entered_unit_cost'],
						'converted_unit_cost_eur' => $line['converted_unit_cost_eur'],
						'base_line_cost'          => $line['base_line_cost'],
						'allocated_landed_cost'   => $line['allocated_landed'],
						'true_line_cost'          => $line['true_line_cost'],
						'true_unit_cost'          => $line['true_unit_cost'],
					)
				)
			);
		}

		foreach ( $preview['landed_rows'] as $row ) {
			self::throw_if_error(
				WC_Inventory_Overview_Receipt_Costs::create( $receipt_id, $row )
			);
		}
	}

	/**
	 * Split a raw picker product id (simple product id or variation id) into
	 * (parent) product_id + variation_id + snapshots, matching the PO/Batch
	 * line-storage convention.
	 *
	 * @param int $raw_id Raw product/variation id.
	 * @return array{product_id:int,variation_id:int,sku_snapshot:string,name_snapshot:string}
	 */
	private static function resolve_product_identity( int $raw_id ): array {
		$product = wc_get_product( $raw_id );
		if ( ! $product instanceof WC_Product ) {
			return array(
				'product_id'    => $raw_id,
				'variation_id'  => 0,
				'sku_snapshot'  => '',
				'name_snapshot' => '',
			);
		}
		if ( $product->is_type( 'variation' ) ) {
			return array(
				'product_id'    => (int) $product->get_parent_id(),
				'variation_id'  => (int) $product->get_id(),
				'sku_snapshot'  => (string) $product->get_sku(),
				'name_snapshot' => (string) $product->get_name(),
			);
		}
		return array(
			'product_id'    => (int) $product->get_id(),
			'variation_id'  => 0,
			'sku_snapshot'  => (string) $product->get_sku(),
			'name_snapshot' => (string) $product->get_name(),
		);
	}

	/**
	 * Map Restock_Service::apply_purchase_line_change()'s 'movement' sub-array keys
	 * to Receipt_Lines::persist_posting_snapshot()'s expected keys.
	 *
	 * @param array<string, mixed> $applied Return of apply_purchase_line_change().
	 * @return array<string, mixed>
	 */
	private static function snapshot_result_from_applied( array $applied ): array {
		$m = $applied['movement'];
		return array(
			'old_stock'             => $m['old_stock'],
			'new_stock'             => $m['new_stock'],
			'old_average_unit_cost' => $m['old_average_unit_cost'],
			'new_average_unit_cost' => $m['new_average_unit_cost'],
			'old_inventory_value'   => $m['old_inventory_value'],
			'new_inventory_value'   => $m['new_inventory_value'],
		);
	}

	/**
	 * Invalidate product/variation object cache, postmeta cache, transients, and the
	 * global product cache version. Called both strictly after a successful commit
	 * (so the fresh state is visible) and strictly after a caught rollback exception
	 * (so a phantom, never-committed value left behind by WooCommerce's own
	 * synchronous wp_cache_set() inside update_post_meta() — which a SQL ROLLBACK
	 * cannot reach — never leaks into a subsequent read on a persistent object cache
	 * backend). Never called inside a transaction closure itself.
	 *
	 * Calling wc_delete_product_transients() alone is insufficient here: it only clears
	 * WooCommerce's own transients (price-range caches etc.), not the WordPress
	 * core 'posts'/'post_meta' object-cache groups that get_stock_quantity() and
	 * every custom cost-meta reader ultimately read through — clean_post_cache()
	 * is required to force those back to a cold, DB-accurate state.
	 *
	 * @param array<int,int> $purchasable_ids Touched variation or simple product ids.
	 */
	private static function invalidate_product_caches( array $purchasable_ids ): void {
		$parents = array();
		foreach ( array_unique( $purchasable_ids ) as $pid ) {
			clean_post_cache( $pid );
			wc_delete_product_transients( $pid );
			$product = wc_get_product( $pid );
			if ( $product && $product->is_type( 'variation' ) ) {
				$parents[ (int) $product->get_parent_id() ] = true;
			}
		}
		foreach ( array_keys( $parents ) as $parent_id ) {
			clean_post_cache( $parent_id );
			wc_delete_product_transients( $parent_id );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}
	}
}
