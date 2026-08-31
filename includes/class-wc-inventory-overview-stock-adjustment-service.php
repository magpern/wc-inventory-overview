<?php
/**
 * Stock Adjustment application service — sole outbound personal-use mutator (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustment application service.
 */
class WC_Inventory_Overview_Stock_Adjustment_Service {

	const MAX_LINES = 100;

	/**
	 * @param mixed $value Return value.
	 * @return mixed
	 * @throws WC_Inventory_Overview_Stock_Adjustment_Posting_Exception When WP_Error.
	 */
	private static function throw_if_error( $value ) {
		if ( is_wp_error( $value ) ) {
			throw new WC_Inventory_Overview_Stock_Adjustment_Posting_Exception( $value );
		}
		return $value;
	}

	/**
	 * Create an empty draft personal-use adjustment.
	 *
	 * @return int|WP_Error
	 */
	public static function create_draft() {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) {
			return new WP_Error( 'wc_io_sa_forbidden', __( 'You do not have permission to create Stock Adjustments.', 'wc-inventory-overview' ) );
		}

		return WC_Inventory_Overview_Stock_Adjustments::create_draft(
			array(
				'adjustment_kind' => WC_Inventory_Overview_Stock_Adjustment_Lifecycle::KIND_PERSONAL_USE,
			)
		);
	}

	/**
	 * Replace draft lines from parsed POST (draft-only).
	 *
	 * @param int                  $id  Adjustment id.
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return int|WP_Error
	 */
	public static function save_draft_from_post( int $id, array $src ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) {
			return new WP_Error( 'wc_io_sa_forbidden', __( 'You do not have permission to edit Stock Adjustments.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			return $header;
		}

		$editable = WC_Inventory_Overview_Stock_Adjustment_Lifecycle::assert_editable( (string) $header['status'] );
		if ( is_wp_error( $editable ) ) {
			return $editable;
		}

		$note = isset( $src['note'] ) ? sanitize_textarea_field( (string) $src['note'] ) : '';
		$lines_raw = isset( $src['lines'] ) && is_array( $src['lines'] ) ? $src['lines'] : array();

		$parsed = self::parse_lines( $lines_raw );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $note, $parsed ) {
					self::throw_if_error( WC_Inventory_Overview_Stock_Adjustments::update_draft( $id, array( 'note' => $note ) ) );
					self::throw_if_error( WC_Inventory_Overview_Stock_Adjustment_Lines::delete_all_for_adjustment( $id ) );

					foreach ( $parsed as $idx => $line ) {
						self::throw_if_error(
							WC_Inventory_Overview_Stock_Adjustment_Lines::create(
								$id,
								array_merge(
									$line,
									array( 'line_index' => $idx )
								)
							)
						);
					}
				}
			);
		} catch ( WC_Inventory_Overview_Stock_Adjustment_Posting_Exception $e ) {
			return $e->get_wp_error();
		}

		return $id;
	}

	/**
	 * Delete a draft adjustment.
	 *
	 * @param int $id Adjustment id.
	 * @return true|WP_Error
	 */
	public static function delete_draft( int $id ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) {
			return new WP_Error( 'wc_io_sa_forbidden', __( 'You do not have permission to delete Stock Adjustments.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			return $header;
		}

		if ( ! WC_Inventory_Overview_Stock_Adjustment_Lifecycle::can_delete( (string) $header['status'] ) ) {
			return new WP_Error( 'wc_io_sa_not_draft', __( 'Only draft Stock Adjustments can be deleted.', 'wc-inventory-overview' ) );
		}

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id ) {
					self::throw_if_error( WC_Inventory_Overview_Stock_Adjustment_Lines::delete_all_for_adjustment( $id ) );
					self::throw_if_error( WC_Inventory_Overview_Stock_Adjustments::delete_draft_record( $id ) );
				}
			);
		} catch ( WC_Inventory_Overview_Stock_Adjustment_Posting_Exception $e ) {
			return $e->get_wp_error();
		}

		return true;
	}

	/**
	 * Post a draft Stock Adjustment.
	 *
	 * @param int    $id    Adjustment id.
	 * @param string $token Request token (sa_post).
	 * @return array<string,mixed>|WP_Error
	 */
	public static function post( int $id, string $token ) {
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, 'sa_post' ) ) {
			return new WP_Error( 'wc_io_sa_token', __( 'This form has already been submitted or has expired. Please go back and try again.', 'wc-inventory-overview' ) );
		}

		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_STOCK_ADJUSTMENT ) ) {
			return new WP_Error( 'wc_io_sa_forbidden', __( 'You do not have permission to post Stock Adjustments.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			return $header;
		}

		if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT !== $header['status'] ) {
			return new WP_Error( 'wc_io_sa_not_draft', __( 'Only a draft Stock Adjustment can be posted.', 'wc-inventory-overview' ) );
		}

		$note = trim( (string) ( $header['note'] ?? '' ) );
		if ( '' === $note ) {
			return new WP_Error( 'wc_io_sa_note_required', __( 'A note is required before posting personal use.', 'wc-inventory-overview' ) );
		}

		$lines = WC_Inventory_Overview_Stock_Adjustment_Lines::list_for_adjustment( $id );
		if ( empty( $lines ) ) {
			return new WP_Error( 'wc_io_sa_no_lines', __( 'Add at least one line before posting.', 'wc-inventory-overview' ) );
		}

		if ( count( $lines ) > self::MAX_LINES ) {
			return new WP_Error(
				'wc_io_sa_max_lines',
				sprintf(
					/* translators: %d: max lines */
					__( 'A Stock Adjustment cannot contain more than %d lines.', 'wc-inventory-overview' ),
					self::MAX_LINES
				)
			);
		}

		$sorted = self::sort_lines_by_item( $lines );
		foreach ( $sorted as $line ) {
			if ( (float) $line['qty'] <= 0 ) {
				return new WP_Error( 'wc_io_sa_qty', __( 'Each line quantity must be greater than zero.', 'wc-inventory-overview' ) );
			}
		}

		$user_id             = get_current_user_id();
		$touched_product_ids = array();
		$compensation_stack  = array();

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $header, $sorted, $user_id, $note, &$touched_product_ids, &$compensation_stack ) {
					foreach ( $sorted as $line ) {
						$item_id = WC_Inventory_Overview_Stock_Adjustment_Lines::item_post_id( $line );
						$qty     = (float) $line['qty'];

						$applied = self::throw_if_error(
							WC_Inventory_Overview_Restock_Service::apply_outbound_line_change( $item_id, $qty )
						);

						$compensation_stack[] = array(
							'line_id'  => $item_id,
							'snapshot' => $applied['snapshot'],
						);

						$movement_row = array_merge(
							$applied['movement'],
							array(
								'reference_id' => $id,
								'note'         => sprintf(
									/* translators: %1$s: adjustment number, %2$s: operator note */
									__( 'Personal use %1$s — %2$s', 'wc-inventory-overview' ),
									(string) $header['adjustment_number'],
									$note
								),
								'user_id'      => $user_id,
							)
						);

						$movement_ok = WC_Inventory_Overview_Movements::insert_personal_use( $movement_row );
						if ( ! $movement_ok ) {
							$restore = self::restore_compensation_stack( $compensation_stack );
							if ( is_wp_error( $restore ) ) {
								self::throw_if_error( $restore );
							}
							self::throw_if_error( new WP_Error( 'wc_io_sa_movement', __( 'Could not write movement log.', 'wc-inventory-overview' ) ) );
						}

						self::throw_if_error(
							WC_Inventory_Overview_Stock_Adjustment_Lines::persist_posting_snapshot(
								(int) $line['id'],
								array_merge(
									$applied['movement'],
									array(
										'unit_cost_at_post' => $applied['posting']['unit_cost_at_post'],
										'value_removed'     => $applied['posting']['value_removed'],
									)
								)
							)
						);

						$touched_product_ids[] = $item_id;
					}

					$affected = WC_Inventory_Overview_Stock_Adjustments::compare_and_swap_post( $id, $user_id );
					if ( 1 !== $affected ) {
						$restore = self::restore_compensation_stack( $compensation_stack );
						if ( is_wp_error( $restore ) ) {
							self::throw_if_error( $restore );
						}
						self::throw_if_error(
							new WP_Error( 'wc_io_sa_post_race', __( 'This Stock Adjustment is no longer a draft (it may already have been posted).', 'wc-inventory-overview' ) )
						);
					}
				}
			);
		} catch ( WC_Inventory_Overview_Stock_Adjustment_Posting_Exception $e ) {
			$restore = self::restore_compensation_stack( $compensation_stack );
			self::invalidate_product_caches( $touched_product_ids );
			if ( is_wp_error( $restore ) ) {
				return $restore;
			}
			return $e->get_wp_error();
		}

		self::invalidate_product_caches( $touched_product_ids );

		/**
		 * Internal hook after a Stock Adjustment is posted. Not a public API.
		 *
		 * @param int                  $id     Adjustment id.
		 * @param array<string,mixed>  $header Header row.
		 */
		do_action( 'wc_io_stock_adjustment_posted', $id, WC_Inventory_Overview_Stock_Adjustments::get( $id ) );

		return WC_Inventory_Overview_Stock_Adjustments::get( $id );
	}

	/**
	 * Void a posted Stock Adjustment.
	 *
	 * @param int    $id          Adjustment id.
	 * @param string $token       Request token (sa_void).
	 * @param string $void_reason Mandatory reason.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function void( int $id, string $token, string $void_reason ) {
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, 'sa_void' ) ) {
			return new WP_Error( 'wc_io_sa_token', __( 'This form has already been submitted or has expired. Please go back and try again.', 'wc-inventory-overview' ) );
		}

		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_STOCK_ADJUSTMENT ) ) {
			return new WP_Error( 'wc_io_sa_forbidden', __( 'You do not have permission to void Stock Adjustments.', 'wc-inventory-overview' ) );
		}

		$void_reason = trim( sanitize_textarea_field( $void_reason ) );
		if ( '' === $void_reason ) {
			return new WP_Error( 'wc_io_sa_void_reason', __( 'A void reason is required.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			return $header;
		}

		if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED !== $header['status'] ) {
			return new WP_Error( 'wc_io_sa_not_posted', __( 'Only a posted Stock Adjustment can be voided.', 'wc-inventory-overview' ) );
		}

		$lines = WC_Inventory_Overview_Stock_Adjustment_Lines::list_for_adjustment( $id );
		$sorted = self::sort_lines_by_item( $lines );

		$user_id             = get_current_user_id();
		$touched_product_ids = array();
		$compensation_stack  = array();

		global $wpdb;
		$txn = new WC_Inventory_Overview_DB_Transaction( $wpdb );

		try {
			$txn->run(
				function () use ( $id, $header, $sorted, $user_id, $void_reason, &$touched_product_ids, &$compensation_stack ) {
					$affected = WC_Inventory_Overview_Stock_Adjustments::compare_and_swap_void( $id, $user_id, $void_reason );
					if ( 1 !== $affected ) {
						self::throw_if_error(
							new WP_Error( 'wc_io_sa_void_race', __( 'This Stock Adjustment is no longer posted (it may already have been voided).', 'wc-inventory-overview' ) )
						);
					}

					foreach ( $sorted as $line ) {
						$item_id = WC_Inventory_Overview_Stock_Adjustment_Lines::item_post_id( $line );
						$qty     = (float) $line['qty'];
						$value   = (float) $line['value_removed'];

						$applied = self::throw_if_error(
							WC_Inventory_Overview_Restock_Service::apply_outbound_line_reversal( $item_id, $qty, $value )
						);

						$compensation_stack[] = array(
							'line_id'  => $item_id,
							'snapshot' => $applied['snapshot'],
						);

						$movement_row = array_merge(
							$applied['movement'],
							array(
								'reference_id' => $id,
								'note'         => sprintf(
									/* translators: %1$s: adjustment number, %2$s: void reason */
									__( 'Void personal use %1$s — %2$s', 'wc-inventory-overview' ),
									(string) $header['adjustment_number'],
									$void_reason
								),
								'user_id'      => $user_id,
							)
						);

						$movement_ok = WC_Inventory_Overview_Movements::insert_personal_use_void( $movement_row );
						if ( ! $movement_ok ) {
							$restore = self::restore_compensation_stack( $compensation_stack );
							if ( is_wp_error( $restore ) ) {
								self::throw_if_error( $restore );
							}
							self::throw_if_error( new WP_Error( 'wc_io_sa_movement', __( 'Could not write void movement log.', 'wc-inventory-overview' ) ) );
						}

						$touched_product_ids[] = $item_id;
					}
				}
			);
		} catch ( WC_Inventory_Overview_Stock_Adjustment_Posting_Exception $e ) {
			$restore = self::restore_compensation_stack( $compensation_stack );
			self::invalidate_product_caches( $touched_product_ids );
			if ( is_wp_error( $restore ) ) {
				return $restore;
			}
			return $e->get_wp_error();
		}

		self::invalidate_product_caches( $touched_product_ids );

		/**
		 * Internal hook after a Stock Adjustment is voided. Not a public API.
		 *
		 * @param int $id Adjustment id.
		 */
		do_action( 'wc_io_stock_adjustment_voided', $id );

		return WC_Inventory_Overview_Stock_Adjustments::get( $id );
	}

	/**
	 * Preview outbound cost for one line (read-only).
	 *
	 * @param int   $item_post_id Product/variation id.
	 * @param float $qty          Qty.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function preview_line( int $item_post_id, float $qty ) {
		$product = wc_get_product( $item_post_id );
		if ( ! $product ) {
			return new WP_Error( 'wc_io_product', __( 'Product not found.', 'wc-inventory-overview' ) );
		}

		$stock = $product->get_stock_quantity();
		$on_hand = ( null === $stock || '' === $stock ) ? 0.0 : (float) wc_stock_amount( $stock );
		$avg     = WC_Inventory_Overview_Costing::get_average_float( $product );
		$val     = WC_Inventory_Overview_Restock_Service::read_inventory_value_at_post( $product, $on_hand, $avg );

		$zero_cost_allowed = WC_Inventory_Overview_Settings::allow_zero_supplier_cost();
		$warning           = '';

		if ( null === $avg ) {
			return new WP_Error( 'wc_io_sa_no_cost', __( 'No inventory cost on record.', 'wc-inventory-overview' ) );
		}

		if ( (float) $avg <= 0.0 && $zero_cost_allowed ) {
			$warning = __( 'Zero unit cost will be recorded.', 'wc-inventory-overview' );
		}

		$qty = (float) wc_stock_amount( $qty );
		if ( $qty <= 0 ) {
			return new WP_Error( 'wc_io_qty', __( 'Quantity must be greater than zero.', 'wc-inventory-overview' ) );
		}

		$value_removed = (float) wc_format_decimal( $qty * (float) $avg, 4 );
		$new_stock     = max( 0.0, (float) wc_format_decimal( $on_hand - $qty, 4 ) );
		$new_value     = max( 0.0, (float) wc_format_decimal( $val - $value_removed, 4 ) );

		return array(
			'on_hand'           => $on_hand,
			'avg_cost'          => (float) $avg,
			'value_removed'     => $value_removed,
			'resulting_stock'   => $new_stock,
			'resulting_value'   => $new_value,
			'zero_cost_warning' => $warning,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $lines_raw Raw lines from POST.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function parse_lines( array $lines_raw ) {
		if ( count( $lines_raw ) > self::MAX_LINES ) {
			return new WP_Error(
				'wc_io_sa_max_lines',
				sprintf(
					/* translators: %d: max lines */
					__( 'A Stock Adjustment cannot contain more than %d lines.', 'wc-inventory-overview' ),
					self::MAX_LINES
				)
			);
		}

		$parsed   = array();
		$seen_ids = array();

		foreach ( $lines_raw as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$product_id   = isset( $raw['product_id'] ) ? absint( $raw['product_id'] ) : 0;
			$variation_id = isset( $raw['variation_id'] ) ? absint( $raw['variation_id'] ) : 0;
			$qty          = isset( $raw['qty'] ) ? (float) wc_stock_amount( $raw['qty'] ) : 0.0;

			if ( $qty <= 0 ) {
				return new WP_Error( 'wc_io_sa_qty', __( 'Each line quantity must be greater than zero.', 'wc-inventory-overview' ) );
			}

			$valid = WC_Inventory_Overview_PO_Product_Validator::validate( $product_id, $variation_id );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}

			$item_id = $variation_id > 0 ? $variation_id : $product_id;
			if ( isset( $seen_ids[ $item_id ] ) ) {
				return new WP_Error( 'wc_io_sa_duplicate_line', __( 'Each product may appear only once on a Stock Adjustment.', 'wc-inventory-overview' ) );
			}
			$seen_ids[ $item_id ] = true;

			$product = wc_get_product( $item_id );
			if ( ! $product ) {
				return new WP_Error( 'wc_io_product', __( 'Product not found.', 'wc-inventory-overview' ) );
			}

			$parsed[] = array(
				'product_id'    => $variation_id > 0 ? (int) $product->get_parent_id() : $product_id,
				'variation_id'  => $variation_id,
				'qty'           => $qty,
				'sku_snapshot'  => $product->get_sku(),
				'name_snapshot' => $product->get_name(),
			);
		}

		return $parsed;
	}

	/**
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @return array<int,array<string,mixed>>
	 */
	private static function sort_lines_by_item( array $lines ): array {
		usort(
			$lines,
			static function ( $a, $b ) {
				$ida = WC_Inventory_Overview_Stock_Adjustment_Lines::item_post_id( $a );
				$idb = WC_Inventory_Overview_Stock_Adjustment_Lines::item_post_id( $b );
				return $ida <=> $idb;
			}
		);
		return $lines;
	}

	/**
	 * @param array<int,array{line_id:int,snapshot:array<string,mixed>}> $stack Stack.
	 * @return true|WP_Error
	 */
	private static function restore_compensation_stack( array $stack ) {
		$stack = array_reverse( $stack );
		foreach ( $stack as $entry ) {
			$result = WC_Inventory_Overview_Restock_Service::restore_snapshot( (int) $entry['line_id'], $entry['snapshot'] );
			if ( is_wp_error( $result ) ) {
				if ( function_exists( 'wc_get_logger' ) ) {
					wc_get_logger()->error(
						$result->get_error_message(),
						array(
							'source'  => 'wc-inventory-overview',
							'line_id' => (int) $entry['line_id'],
						)
					);
				}
				return $result;
			}
		}
		return true;
	}

	/**
	 * @param array<int,int> $product_ids Product ids.
	 */
	private static function invalidate_product_caches( array $product_ids ) {
		$product_ids = array_unique( array_filter( array_map( 'absint', $product_ids ) ) );
		foreach ( $product_ids as $pid ) {
			wc_delete_product_transients( $pid );
			$product = wc_get_product( $pid );
			if ( $product && $product->is_type( 'variation' ) ) {
				wc_delete_product_transients( (int) $product->get_parent_id() );
			}
		}
		if ( class_exists( 'WC_Cache_Helper' ) && ! empty( $product_ids ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}
	}
}
