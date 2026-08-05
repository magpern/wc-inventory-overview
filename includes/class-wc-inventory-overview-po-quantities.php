<?php
/**
 * Purchase Order quantity helpers (M2 form of INV-4).
 *
 * Outstanding = max(0, qty_ordered − qty_cancelled). No qty_received.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pure quantity calculations.
 */
class WC_Inventory_Overview_PO_Quantities {

	/**
	 * Outstanding quantity (M2 INV-4 reduction).
	 *
	 * @param float|int|string $qty_ordered   Ordered.
	 * @param float|int|string $qty_cancelled Cancelled.
	 */
	public static function outstanding( $qty_ordered, $qty_cancelled ): float {
		return max( 0.0, (float) $qty_ordered - (float) $qty_cancelled );
	}

	/**
	 * Validate ordered/cancelled pair for a line.
	 *
	 * @param float|int|string $qty_ordered   Ordered.
	 * @param float|int|string $qty_cancelled Cancelled.
	 * @return true|WP_Error
	 */
	public static function validate_quantities( $qty_ordered, $qty_cancelled ) {
		$ordered   = (float) $qty_ordered;
		$cancelled = (float) $qty_cancelled;

		if ( $ordered <= 0 ) {
			return new WP_Error( 'wc_io_po_qty_ordered', 'qty_ordered must be greater than zero' );
		}
		if ( $cancelled < 0 ) {
			return new WP_Error( 'wc_io_po_qty_cancelled', 'qty_cancelled must be >= 0' );
		}
		if ( $cancelled > $ordered ) {
			return new WP_Error( 'wc_io_po_qty_cancelled', 'qty_cancelled must not exceed qty_ordered' );
		}
		return true;
	}

	/**
	 * Validate unit cost.
	 *
	 * @param float|int|string $unit_cost Unit cost.
	 * @return true|WP_Error
	 */
	public static function validate_unit_cost( $unit_cost ) {
		if ( (float) $unit_cost < 0 ) {
			return new WP_Error( 'wc_io_po_unit_cost', 'unit_cost must be >= 0' );
		}
		return true;
	}
}
