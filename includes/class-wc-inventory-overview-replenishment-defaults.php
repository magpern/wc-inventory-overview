<?php
/**
 * Replenishment defaults persistence (M23 + M26).
 *
 * Sole owner of reads/writes for the two per-purchasable-item postmeta
 * keys: an explicit preferred supplier and a fixed default replenishment
 * quantity. No other class reads or writes these keys (INV-M23-12 /
 * INV-M26-1). No SQL here -- postmeta only, and supplier eligibility
 * checks delegate to WC_Inventory_Overview_Suppliers (INV-M23-13 /
 * INV-M26-4).
 *
 * M26 adds apply_to_variations() (COPY/APPLY NOW onto variation children),
 * shared non-mutating normalizers, and a hard 100-variation product cap.
 * Never writes variable-parent meta (BR-M26-4 / INV-M26-3).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Per-item (simple product or variation) replenishment defaults.
 *
 * Item identity matches M22's own convention exactly (§7/BR-M23-12): a
 * simple product's own post id, or a variation's own post id -- never the
 * parent of a variation. No parent-to-variation inheritance and no
 * variation-to-parent rollup (BR-M23-11 / BR-M26-19).
 */
class WC_Inventory_Overview_Replenishment_Defaults {

	const META_PREFERRED_SUPPLIER = '_wc_io_preferred_supplier_id';
	const META_DEFAULT_QTY        = '_wc_io_default_replenishment_qty';

	/**
	 * Hard product limit for M26 bulk-apply (BR-M26-14 / INV-M26-11).
	 * Products with more variations must use per-variation M23 panels.
	 */
	const MAX_APPLY_VARIATIONS = 100;

	/**
	 * Test-only mid-write failure injection (gated by WC_IO_PHPUNIT_RUNNING).
	 *
	 * Shape: array{ after_success_count: int } — fail on the next field
	 * write after this many successful field operations in the current
	 * apply_to_variations() call (0 = fail before the first write).
	 *
	 * @var array{after_success_count:int}|null
	 */
	private static $test_write_fail = null;

	/**
	 * Successful field-operation counter for the active apply_to_variations()
	 * call (used only with the test seam).
	 *
	 * @var int
	 */
	private static $test_success_ops = 0;

	/**
	 * Get the configured preferred supplier id for an item.
	 *
	 * No eligibility check here -- eligibility is always a use-time concern
	 * of the caller (§9), not this storage class.
	 *
	 * @param int $item_post_id Simple product's own post id, or a variation's own post id.
	 * @return int Supplier id, or 0 if unset.
	 */
	public static function get_preferred_supplier_id( int $item_post_id ): int {
		return absint( get_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER, true ) );
	}

	/**
	 * Get the configured default replenishment quantity for an item.
	 *
	 * @param int $item_post_id Simple product's own post id, or a variation's own post id.
	 * @return float Quantity, or 0.0 if unset.
	 */
	public static function get_default_qty( int $item_post_id ): float {
		$raw = get_post_meta( $item_post_id, self::META_DEFAULT_QTY, true );
		return ( '' === $raw || null === $raw ) ? 0.0 : (float) $raw;
	}

	/**
	 * Bulk sibling of get_preferred_supplier_id()/get_default_qty() (M24
	 * WP-M24-4, §21): both values for MANY items in one pass. Primes
	 * WordPress's own post-meta object cache for the whole id list with
	 * update_meta_cache() (1 query total, WordPress core's own bulk-postmeta
	 * primitive -- the same mechanism WP_Query's own postmeta priming uses),
	 * then reads through the unmodified single-item getters above so there
	 * is exactly one meta-reading code path in this class, never a second,
	 * bulk-only reimplementation (the two single-item getters remain
	 * byte-for-byte unmodified, INV-M24-8) -- the subsequent get_post_meta()
	 * calls are served entirely from the primed cache, 0 additional queries.
	 *
	 * @param int[] $item_post_ids Simple product/variation post ids.
	 * @return array<int,array{preferred_supplier_id:int,default_qty:float}> Keyed by item post id.
	 */
	public static function get_bulk( array $item_post_ids ): array {
		$item_post_ids = array_values( array_unique( array_filter( array_map( 'absint', $item_post_ids ) ) ) );
		if ( empty( $item_post_ids ) ) {
			return array();
		}

		update_meta_cache( 'post', $item_post_ids );

		$result = array();
		foreach ( $item_post_ids as $item_post_id ) {
			$result[ $item_post_id ] = array(
				'preferred_supplier_id' => self::get_preferred_supplier_id( $item_post_id ),
				'default_qty'           => self::get_default_qty( $item_post_id ),
			);
		}
		return $result;
	}

	/**
	 * Save (or clear) the preferred supplier for an item.
	 *
	 * BR-M23-6: a newly chosen id must resolve to a currently eligible
	 * supplier. BR-M23-7: resubmitting the value already stored is always
	 * accepted as a no-op, even if that supplier has since become
	 * ineligible -- this is the silent-clobber guard: without it, a
	 * dropdown built only from active suppliers wouldn't contain an
	 * already-stored-but-now-archived id, so saving any unrelated product
	 * field would otherwise silently reset the preference to 0.
	 * BR-M23-10: id 0 is an explicit clear.
	 *
	 * Bulk Set does NOT use this stale same-id shortcut — see
	 * normalize_preferred_supplier_for_bulk_set() / BR-M26-21.
	 *
	 * @param int $item_post_id Simple product's own post id, or a variation's own post id.
	 * @param int $supplier_id  New supplier id, or 0 to clear.
	 * @return true|WP_Error
	 */
	public static function save_preferred_supplier( int $item_post_id, int $supplier_id ) {
		if ( $supplier_id <= 0 ) {
			delete_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER );
			return true;
		}

		if ( self::get_preferred_supplier_id( $item_post_id ) === $supplier_id ) {
			return true;
		}

		$supplier_row = WC_Inventory_Overview_Suppliers::get( $supplier_id );
		if ( is_wp_error( $supplier_row ) || ! WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_row ) ) {
			return new WP_Error(
				'wc_io_replenishment_supplier_ineligible',
				__( 'The selected preferred supplier is not currently eligible (must be active and not merged).', 'wc-inventory-overview' )
			);
		}

		update_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER, $supplier_id );
		return true;
	}

	/**
	 * Save (or clear) the default replenishment quantity for an item.
	 *
	 * BR-M23-14/BR-M23-15: numeric, > 0, up to 4 decimals, no upper bound
	 * -- the same rule WC_Inventory_Overview_PO_Quantities already applies
	 * to qty_ordered, reused rather than reinvented. Blank/empty clears.
	 * Stored via wc_format_decimal() (§13) so the value flowing into
	 * render_line_row()'s value="…" slot is exact and free of locale/float
	 * noise.
	 *
	 * Normalization lives in normalize_default_qty() so M26 bulk-apply
	 * shares one rule source (INV-M26-5).
	 *
	 * @param int                   $item_post_id Simple product's own post id, or a variation's own post id.
	 * @param string|int|float|null $raw Raw submitted value.
	 * @return true|WP_Error
	 */
	public static function save_default_qty( int $item_post_id, $raw ) {
		$normalized = self::normalize_default_qty( $raw );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( 'clear' === $normalized['action'] ) {
			delete_post_meta( $item_post_id, self::META_DEFAULT_QTY );
			return true;
		}

		update_post_meta( $item_post_id, self::META_DEFAULT_QTY, $normalized['value'] );
		return true;
	}

	/**
	 * Apply replenishment default changes to variation children (M26).
	 *
	 * COPY/APPLY NOW only — never inheritance, never parent meta writes.
	 * Guarantees validation atomicity (predictable validation failures →
	 * zero writes). Does not guarantee write atomicity (no rollback).
	 *
	 * Counter semantics (BR-M26-24): successful-operation counts including
	 * idempotent already-equal / already-absent no-ops.
	 *
	 * @param int   $parent_product_id Variable parent product post id.
	 * @param int[] $variation_ids     Target variation post ids.
	 * @param array $changes {
	 *     Field update flags and values for the bulk apply.
	 *
	 *     @type bool                  $update_preferred_supplier Whether to touch supplier.
	 *     @type int                   $preferred_supplier_id     Required if update; 0 = clear.
	 *     @type bool                  $update_default_qty        Whether to touch qty.
	 *     @type string|int|float|null $default_qty               Required if update; ''|null = clear.
	 * }
	 * @return array{variations_updated:int,supplier_updates:int,qty_updates:int}|WP_Error
	 */
	public static function apply_to_variations( int $parent_product_id, array $variation_ids, array $changes ) {
		self::$test_success_ops = 0;

		$update_supplier = ! empty( $changes['update_preferred_supplier'] );
		$update_qty      = ! empty( $changes['update_default_qty'] );

		if ( ! $update_supplier && ! $update_qty ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_nothing_to_apply',
				__( 'No replenishment default field was selected to apply.', 'wc-inventory-overview' )
			);
		}

		$variation_ids = array_values( array_map( 'absint', $variation_ids ) );
		$count         = count( $variation_ids );

		if ( $count > self::MAX_APPLY_VARIATIONS ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_too_many',
				sprintf(
					/* translators: 1: variation count, 2: max allowed */
					__( 'This product has %1$d variations. Replenishment bulk apply supports at most %2$d variations. Edit variation defaults individually, or reduce the number of variations.', 'wc-inventory-overview' ),
					$count,
					self::MAX_APPLY_VARIATIONS
				)
			);
		}

		$parent = wc_get_product( $parent_product_id );
		if ( ! $parent || ! $parent->is_type( 'variable' ) ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_invalid_parent',
				__( 'Replenishment bulk apply requires a variable product parent.', 'wc-inventory-overview' )
			);
		}

		if ( ! current_user_can( 'edit_product', $parent_product_id ) ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_forbidden',
				__( 'You do not have permission to edit this product.', 'wc-inventory-overview' )
			);
		}

		$allowed_children = array_map( 'absint', $parent->get_children() );
		$allowed_lookup   = array_fill_keys( $allowed_children, true );

		foreach ( $variation_ids as $variation_id ) {
			if ( $variation_id <= 0 || ! isset( $allowed_lookup[ $variation_id ] ) ) {
				return new WP_Error(
					'wc_io_replenishment_bulk_foreign_variation',
					__( 'One or more target variations do not belong to this product.', 'wc-inventory-overview' )
				);
			}

			$variation = wc_get_product( $variation_id );
			if ( ! $variation || ! $variation->is_type( 'variation' ) ) {
				return new WP_Error(
					'wc_io_replenishment_bulk_invalid_variation',
					__( 'One or more target ids are not valid product variations.', 'wc-inventory-overview' )
				);
			}

			if ( ! current_user_can( 'edit_product', $variation_id ) ) {
				return new WP_Error(
					'wc_io_replenishment_bulk_forbidden',
					__( 'You do not have permission to edit one or more variations.', 'wc-inventory-overview' )
				);
			}
		}

		$supplier_norm = null;
		$qty_norm      = null;

		if ( $update_supplier ) {
			$supplier_norm = self::normalize_preferred_supplier_for_bulk_set(
				isset( $changes['preferred_supplier_id'] ) ? (int) $changes['preferred_supplier_id'] : 0
			);
			if ( is_wp_error( $supplier_norm ) ) {
				return $supplier_norm;
			}
		}

		if ( $update_qty ) {
			$qty_norm = self::normalize_default_qty(
				array_key_exists( 'default_qty', $changes ) ? $changes['default_qty'] : null
			);
			if ( is_wp_error( $qty_norm ) ) {
				return $qty_norm;
			}
		}

		$variations_updated = 0;
		$supplier_updates   = 0;
		$qty_updates        = 0;

		foreach ( $variation_ids as $variation_id ) {
			if ( $update_supplier ) {
				$op      = ( 'clear' === $supplier_norm['action'] ) ? 'clear' : 'set';
				$sid     = ( 'set' === $op ) ? (int) $supplier_norm['supplier_id'] : 0;
				$written = self::write_preferred_supplier_for_bulk( $variation_id, $op, $sid );
				if ( is_wp_error( $written ) ) {
					$written->add_data(
						array(
							'variations_updated'  => $variations_updated,
							'supplier_updates'    => $supplier_updates,
							'qty_updates'         => $qty_updates,
							'failed_variation_id' => $variation_id,
							'failed_field'        => 'preferred_supplier',
							'failed_operation'    => $op,
						)
					);
					return $written;
				}
				++$supplier_updates;
			}

			if ( $update_qty ) {
				$op      = ( 'clear' === $qty_norm['action'] ) ? 'clear' : 'set';
				$value   = ( 'set' === $op ) ? $qty_norm['value'] : '';
				$written = self::write_default_qty_for_bulk( $variation_id, $op, $value );
				if ( is_wp_error( $written ) ) {
					$written->add_data(
						array(
							'variations_updated'  => $variations_updated,
							'supplier_updates'    => $supplier_updates,
							'qty_updates'         => $qty_updates,
							'failed_variation_id' => $variation_id,
							'failed_field'        => 'default_qty',
							'failed_operation'    => $op,
						)
					);
					return $written;
				}
				++$qty_updates;
			}

			++$variations_updated;
		}

		return array(
			'variations_updated' => $variations_updated,
			'supplier_updates'   => $supplier_updates,
			'qty_updates'        => $qty_updates,
		);
	}

	/**
	 * Test-only: arm mid-write failure after N successful field ops.
	 *
	 * @param array{after_success_count:int}|null $config Null disarms.
	 */
	public static function set_test_write_fail( ?array $config ): void {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) ) {
			return;
		}
		self::$test_write_fail = $config;
	}

	/**
	 * Test-only: disarm mid-write failure injection.
	 */
	public static function reset_test_write_fail(): void {
		self::$test_write_fail  = null;
		self::$test_success_ops = 0;
	}

	/**
	 * Non-mutating qty normalization shared by save_default_qty and bulk apply.
	 *
	 * @param string|int|float|null $raw Raw value.
	 * @return array{action:'clear'}|array{action:'set',value:string}|WP_Error
	 */
	private static function normalize_default_qty( $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;
		if ( '' === $raw || null === $raw ) {
			return array( 'action' => 'clear' );
		}

		$stripped = wc_format_decimal( $raw, false );
		if ( '' === $stripped || ! is_numeric( $stripped ) || (float) $stripped <= 0 ) {
			return new WP_Error(
				'wc_io_replenishment_qty_invalid',
				__( 'The default replenishment quantity must be a number greater than zero.', 'wc-inventory-overview' )
			);
		}

		return array(
			'action' => 'set',
			'value'  => wc_format_decimal( $stripped, 4 ),
		);
	}

	/**
	 * Bulk Set supplier normalizer — currently eligible only (no stale shortcut).
	 *
	 * @param int $supplier_id Supplier id, or 0 to clear.
	 * @return array{action:'clear'}|array{action:'set',supplier_id:int}|WP_Error
	 */
	private static function normalize_preferred_supplier_for_bulk_set( int $supplier_id ) {
		if ( $supplier_id <= 0 ) {
			return array( 'action' => 'clear' );
		}

		$supplier_row = WC_Inventory_Overview_Suppliers::get( $supplier_id );
		if ( is_wp_error( $supplier_row ) || ! WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_row ) ) {
			return new WP_Error(
				'wc_io_replenishment_supplier_ineligible',
				__( 'The selected preferred supplier is not currently eligible (must be active and not merged).', 'wc-inventory-overview' )
			);
		}

		return array(
			'action'      => 'set',
			'supplier_id' => $supplier_id,
		);
	}

	/**
	 * Idempotent bulk writer for preferred supplier (Amendment 9).
	 *
	 * @param int    $item_post_id Variation post id.
	 * @param string $operation    'set'|'clear'.
	 * @param int    $supplier_id  Target id when setting.
	 * @return true|WP_Error
	 */
	private static function write_preferred_supplier_for_bulk( int $item_post_id, string $operation, int $supplier_id ) {
		$injected = self::maybe_inject_test_write_fail( $item_post_id, 'preferred_supplier', $operation );
		if ( is_wp_error( $injected ) ) {
			return $injected;
		}

		if ( 'clear' === $operation ) {
			$existing = get_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER, true );
			if ( '' === $existing || null === $existing || false === $existing ) {
				++self::$test_success_ops;
				return true;
			}
			delete_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER );
			++self::$test_success_ops;
			return true;
		}

		if ( self::get_preferred_supplier_id( $item_post_id ) === $supplier_id ) {
			++self::$test_success_ops;
			return true;
		}

		$result = update_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER, $supplier_id );
		if ( false === $result ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_write_failed',
				__( 'Failed to update the preferred supplier on a variation.', 'wc-inventory-overview' )
			);
		}

		++self::$test_success_ops;
		return true;
	}

	/**
	 * Idempotent bulk writer for default qty (Amendment 9).
	 *
	 * @param int    $item_post_id Variation post id.
	 * @param string $operation    'set'|'clear'.
	 * @param string $value        Formatted decimal string when setting.
	 * @return true|WP_Error
	 */
	private static function write_default_qty_for_bulk( int $item_post_id, string $operation, string $value ) {
		$injected = self::maybe_inject_test_write_fail( $item_post_id, 'default_qty', $operation );
		if ( is_wp_error( $injected ) ) {
			return $injected;
		}

		if ( 'clear' === $operation ) {
			$existing = get_post_meta( $item_post_id, self::META_DEFAULT_QTY, true );
			if ( '' === $existing || null === $existing || false === $existing ) {
				++self::$test_success_ops;
				return true;
			}
			delete_post_meta( $item_post_id, self::META_DEFAULT_QTY );
			++self::$test_success_ops;
			return true;
		}

		$existing = get_post_meta( $item_post_id, self::META_DEFAULT_QTY, true );
		if ( (string) $existing === (string) $value ) {
			++self::$test_success_ops;
			return true;
		}

		$result = update_post_meta( $item_post_id, self::META_DEFAULT_QTY, $value );
		if ( false === $result ) {
			return new WP_Error(
				'wc_io_replenishment_bulk_write_failed',
				__( 'Failed to update the default replenishment quantity on a variation.', 'wc-inventory-overview' )
			);
		}

		++self::$test_success_ops;
		return true;
	}

	/**
	 * Optional PHPUnit-only write-failure injection seam.
	 *
	 * @param int    $variation_id Variation about to be written.
	 * @param string $field        preferred_supplier|default_qty.
	 * @param string $operation    set|clear.
	 * @return true|WP_Error
	 */
	private static function maybe_inject_test_write_fail( int $variation_id, string $field, string $operation ) {
		if ( ! defined( 'WC_IO_PHPUNIT_RUNNING' ) || null === self::$test_write_fail ) {
			return true;
		}

		$after = isset( self::$test_write_fail['after_success_count'] )
			? (int) self::$test_write_fail['after_success_count']
			: -1;

		if ( $after < 0 || self::$test_success_ops !== $after ) {
			return true;
		}

		return new WP_Error(
			'wc_io_replenishment_bulk_write_failed',
			sprintf(
				/* translators: 1: variation id, 2: field name */
				__( 'Injected write failure for variation %1$d field %2$s.', 'wc-inventory-overview' ),
				$variation_id,
				$field
			)
		);
	}
}
