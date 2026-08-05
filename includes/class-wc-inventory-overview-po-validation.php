<?php
/**
 * Purchase Order header and line validation (M2-B).
 *
 * Pure validation — no persistence writes. Place readiness aggregates header,
 * lines, supplier, currency, quantities, and INV-8.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO validation rules.
 */
class WC_Inventory_Overview_PO_Validation {

	const CURRENCIES = array( 'EUR', 'USD', 'SEK' );

	/**
	 * Validate header fields (status, currency, confidence, optional dates).
	 *
	 * @param array<string,mixed> $header Header-like array.
	 * @return true|WP_Error
	 */
	public static function validate_header( array $header ) {
		if ( isset( $header['status'] ) ) {
			$status = (string) $header['status'];
			if ( ! WC_Inventory_Overview_PO_Statuses::is_valid( $status ) ) {
				return new WP_Error( 'wc_io_po_invalid_status', sprintf( 'Unknown PO status: %s', $status ) );
			}
		}

		if ( array_key_exists( 'currency', $header ) ) {
			$currency = strtoupper( sanitize_key( (string) $header['currency'] ) );
			if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
				return new WP_Error( 'wc_io_po_currency', 'Invalid currency. Must be EUR, USD, or SEK.' );
			}
		}

		if ( array_key_exists( 'expected_confidence', $header ) && null !== $header['expected_confidence'] && '' !== $header['expected_confidence'] ) {
			$conf = WC_Inventory_Overview_PO_Confidence::validate( (string) $header['expected_confidence'] );
			if ( is_wp_error( $conf ) ) {
				return $conf;
			}
		}

		if ( ! empty( $header['expected_date'] ) ) {
			$date = self::validate_date( (string) $header['expected_date'] );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
		}

		return true;
	}

	/**
	 * Validate a single line (quantities, cost, confidence override, INV-8 optional).
	 *
	 * @param array<string,mixed> $line            Line data.
	 * @param bool                $validate_product Whether to run INV-8 (needs WC products).
	 * @return true|WP_Error
	 */
	public static function validate_line( array $line, bool $validate_product = true ) {
		$qty = WC_Inventory_Overview_PO_Quantities::validate_quantities(
			$line['qty_ordered'] ?? 0,
			$line['qty_cancelled'] ?? 0
		);
		if ( is_wp_error( $qty ) ) {
			return $qty;
		}

		$cost = WC_Inventory_Overview_PO_Quantities::validate_unit_cost( $line['unit_cost'] ?? 0 );
		if ( is_wp_error( $cost ) ) {
			return $cost;
		}

		if ( isset( $line['expected_confidence'] ) && null !== $line['expected_confidence'] && '' !== $line['expected_confidence'] ) {
			$conf = WC_Inventory_Overview_PO_Confidence::validate( (string) $line['expected_confidence'] );
			if ( is_wp_error( $conf ) ) {
				return $conf;
			}
		}

		if ( ! empty( $line['expected_date'] ) ) {
			$date = self::validate_date( (string) $line['expected_date'] );
			if ( is_wp_error( $date ) ) {
				return $date;
			}
		}

		if ( $validate_product ) {
			$product = WC_Inventory_Overview_PO_Product_Validator::validate(
				isset( $line['product_id'] ) ? (int) $line['product_id'] : 0,
				isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0
			);
			if ( is_wp_error( $product ) ) {
				return $product;
			}
		}

		return true;
	}

	/**
	 * Validate readiness to place: editable transition, supplier, lines, INV-8.
	 *
	 * @param array<string,mixed>            $header    PO header row.
	 * @param array<int,array<string,mixed>> $lines Line rows.
	 * @param array<string,mixed>|null       $supplier  Supplier row or null if missing.
	 * @return true|WP_Error
	 */
	public static function validate_for_place( array $header, array $lines, $supplier ) {
		$status     = isset( $header['status'] ) ? (string) $header['status'] : '';
		$transition = WC_Inventory_Overview_PO_Lifecycle::assert_transition(
			$status,
			WC_Inventory_Overview_PO_Statuses::PLACED
		);
		if ( is_wp_error( $transition ) ) {
			return $transition;
		}

		$header_ok = self::validate_header( $header );
		if ( is_wp_error( $header_ok ) ) {
			return $header_ok;
		}

		if ( ! is_array( $supplier ) || empty( $supplier['id'] ) ) {
			return new WP_Error( 'wc_io_po_supplier', 'A resolvable supplier is required to place a purchase order' );
		}
		if ( isset( $supplier['status'] ) && WC_Inventory_Overview_Suppliers::STATUS_ACTIVE !== $supplier['status'] ) {
			return new WP_Error( 'wc_io_po_supplier', 'Supplier must be active to place a purchase order' );
		}

		$currency = isset( $header['currency'] ) ? strtoupper( sanitize_key( (string) $header['currency'] ) ) : '';
		if ( ! in_array( $currency, self::CURRENCIES, true ) ) {
			return new WP_Error( 'wc_io_po_currency', 'A valid three-letter currency is required to place' );
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'wc_io_po_lines', 'At least one line is required to place a purchase order' );
		}

		foreach ( $lines as $index => $line ) {
			$line_ok = self::validate_line( $line, true );
			if ( is_wp_error( $line_ok ) ) {
				return new WP_Error(
					$line_ok->get_error_code(),
					sprintf( 'Line %d: %s', $index + 1, $line_ok->get_error_message() )
				);
			}
		}

		return true;
	}

	/**
	 * Validate a Y-m-d date string.
	 *
	 * @param string $date Date.
	 * @return true|WP_Error
	 */
	public static function validate_date( string $date ) {
		$date = trim( $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wc_io_po_date', 'Date must be Y-m-d' );
		}
		$parts = array_map( 'intval', explode( '-', $date ) );
		if ( ! checkdate( $parts[1], $parts[2], $parts[0] ) ) {
			return new WP_Error( 'wc_io_po_date', 'Invalid calendar date' );
		}
		return true;
	}
}
