<?php
/**
 * Replenishment defaults persistence (M23).
 *
 * Sole owner of reads/writes for the two per-purchasable-item postmeta
 * keys this milestone introduces: an explicit preferred supplier and a
 * fixed default replenishment quantity. No other class reads or writes
 * these keys (INV-M23-12). No SQL here -- postmeta only, and supplier
 * eligibility checks delegate to WC_Inventory_Overview_Suppliers
 * (INV-M23-13).
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
 * variation-to-parent rollup (BR-M23-11).
 */
class WC_Inventory_Overview_Replenishment_Defaults {

	const META_PREFERRED_SUPPLIER = '_wc_io_preferred_supplier_id';
	const META_DEFAULT_QTY        = '_wc_io_default_replenishment_qty';

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
	 * @param int $item_post_id Simple product's own post id, or a variation's own post id.
	 * @param int $supplier_id  New supplier id, or 0 to clear.
	 * @return true|WP_Error
	 */
	public static function save_preferred_supplier( int $item_post_id, int $supplier_id ) {
		if ( $supplier_id <= 0 ) {
			delete_post_meta( $item_post_id, self::META_PREFERRED_SUPPLIER );
			return true;
		}

		if ( $supplier_id === self::get_preferred_supplier_id( $item_post_id ) ) {
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
	 * @param int        $item_post_id Simple product's own post id, or a variation's own post id.
	 * @param string|int|float|null $raw Raw submitted value.
	 * @return true|WP_Error
	 */
	public static function save_default_qty( int $item_post_id, $raw ) {
		$raw = is_string( $raw ) ? trim( $raw ) : $raw;
		if ( '' === $raw || null === $raw ) {
			delete_post_meta( $item_post_id, self::META_DEFAULT_QTY );
			return true;
		}

		$stripped = wc_format_decimal( $raw, false );
		if ( '' === $stripped || ! is_numeric( $stripped ) || (float) $stripped <= 0 ) {
			return new WP_Error(
				'wc_io_replenishment_qty_invalid',
				__( 'The default replenishment quantity must be a number greater than zero.', 'wc-inventory-overview' )
			);
		}

		update_post_meta( $item_post_id, self::META_DEFAULT_QTY, wc_format_decimal( $stripped, 4 ) );
		return true;
	}
}
