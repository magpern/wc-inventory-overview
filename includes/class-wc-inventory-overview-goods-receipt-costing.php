<?php
/**
 * Goods Receipt costing: FX/line parsing, landed allocation, and preview computation
 * (M4, extended M5 to parse an optional per-line po_line_id — pure parsing/carrying
 * only, no validation of the referenced PO line's existence/receivability here).
 *
 * Ported from WC_Inventory_Overview_Batch_Intake_Service's landed-allocation formula
 * (proportional by product line value, remainder to the last line) — unmodified math,
 * originally duplicated rather than shared because Batch_Intake_Service's allocation
 * logic was protected/internal to a feature slated for M6 removal (see the M4
 * implementation plan's Critical review — Hidden coupling). That trigger has now
 * fired: see below.
 *
 * Cost type slugs/labels are reused from WC_Inventory_Overview_Landed_Cost_Types
 * (M6) — extracted out of Batch_Intake_Service once M6 disabled that class's write
 * path, closing the hidden-coupling remediation trigger M4's own plan flagged.
 *
 * The weighted-average formula itself (old/new stock, average, inventory value) is
 * NOT reimplemented here for posting — only for the pure, read-only preview shown
 * before a receipt is posted. The actual mutation always runs through
 * WC_Inventory_Overview_Restock_Service::apply_purchase_line_change() inside
 * Goods_Receipt_Service, never through this class.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt cost parsing, allocation, and preview.
 */
class WC_Inventory_Overview_Goods_Receipt_Costing {

	/**
	 * Allowed landed-cost type slugs, reused from WC_Inventory_Overview_Landed_Cost_Types (M6).
	 *
	 * @return string[]
	 */
	public static function allowed_cost_types() {
		return WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types();
	}

	/**
	 * Landed-cost type slug => admin label, reused from WC_Inventory_Overview_Landed_Cost_Types (M6).
	 *
	 * @return array<string, string>
	 */
	public static function landed_cost_type_labels() {
		return WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels();
	}

	/**
	 * Allowed receipt currencies.
	 *
	 * @return string[]
	 */
	public static function allowed_receipt_currencies() {
		return WC_Inventory_Overview_Settings::allowed_purchase_currencies();
	}

	/**
	 * Parse receipt-level FX from POST (authoritative; never trust client-side converted totals).
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array{currency:string,exchange_rate_to_eur:float,exchange_rate_date:string}|WP_Error
	 */
	public static function parse_receipt_fx( array $src ) {
		$u   = static function ( $v ) {
			return is_string( $v ) ? wp_unslash( $v ) : (string) $v;
		};
		$cur = isset( $src['wc_io_gr_currency'] ) ? strtoupper( sanitize_text_field( $u( $src['wc_io_gr_currency'] ) ) ) : WC_Inventory_Overview_Settings::get_default_purchase_currency();
		if ( ! in_array( $cur, self::allowed_receipt_currencies(), true ) ) {
			return new WP_Error( 'wc_io_gr_currency', __( 'Currency must be EUR, USD, or SEK.', 'wc-inventory-overview' ) );
		}

		$date = isset( $src['wc_io_gr_exchange_rate_date'] ) ? sanitize_text_field( $u( $src['wc_io_gr_exchange_rate_date'] ) ) : '';
		if ( '' === $date ) {
			$date = wp_date( 'Y-m-d', null, wp_timezone() );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wc_io_gr_fx_date', __( 'Exchange rate date must be YYYY-MM-DD.', 'wc-inventory-overview' ) );
		}
		$dp = explode( '-', $date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			return new WP_Error( 'wc_io_gr_fx_date', __( 'Exchange rate date is not a valid calendar date.', 'wc-inventory-overview' ) );
		}

		$rate_raw = isset( $src['wc_io_gr_exchange_rate_to_eur'] ) ? $u( $src['wc_io_gr_exchange_rate_to_eur'] ) : '';
		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $cur ) {
			$rate = 1.0;
		} elseif ( '' === trim( $rate_raw ) ) {
			$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $cur, $date );
			if ( is_wp_error( $lk ) ) {
				return $lk;
			}
			$rate = (float) $lk['rate'];
		} else {
			$rate = (float) wc_format_decimal( $rate_raw, 8 );
		}

		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $cur ) {
			$rate = 1.0;
		} elseif ( $rate <= 0 ) {
			return new WP_Error( 'wc_io_gr_fx', __( 'Exchange rate to EUR must be greater than zero.', 'wc-inventory-overview' ) );
		}

		return array(
			'currency'             => $cur,
			'exchange_rate_to_eur' => $rate,
			'exchange_rate_date'   => $date,
		);
	}

	/**
	 * Allocate landed costs proportionally by product line value (EUR), remainder to the
	 * last line. Ported unchanged from Batch_Intake_Service::build_preview_from_post().
	 *
	 * @param array<int,float> $base_line_costs EUR base line cost per line, in line order.
	 * @param float            $landed_total    Total landed cost (EUR) to allocate.
	 * @param float            $product_subtotal Sum of $base_line_costs (passed explicitly to avoid recomputation drift).
	 * @return array<int,float> Allocated amount per line, same order/count as $base_line_costs.
	 */
	public static function allocate_landed( array $base_line_costs, float $landed_total, float $product_subtotal ): array {
		$n           = count( $base_line_costs );
		$allocations = array_fill( 0, $n, 0.0 );
		if ( $landed_total > 0 && $product_subtotal > 0 && $n > 0 ) {
			$sum_prev = 0.0;
			for ( $i = 0; $i < $n; $i++ ) {
				if ( $i < $n - 1 ) {
					$ratio             = $base_line_costs[ $i ] / $product_subtotal;
					$allocations[ $i ] = (float) wc_format_decimal( $landed_total * $ratio, 4 );
					$sum_prev          = (float) wc_format_decimal( $sum_prev + $allocations[ $i ], 4 );
				} else {
					$allocations[ $i ] = (float) wc_format_decimal( $landed_total - $sum_prev, 4 );
				}
			}
		}
		return $allocations;
	}

	/**
	 * Build preview data from raw POST-style array (e.g. $_POST). Pure computation —
	 * no DB writes, no stock mutation. Same math draft persistence and posting reuse.
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build_preview_from_post( array $src ) {
		$fx = self::parse_receipt_fx( $src );
		if ( is_wp_error( $fx ) ) {
			return $fx;
		}

		$header = self::parse_header( $src );
		$lines  = self::parse_product_lines( $src );
		$costs  = self::parse_landed_costs( $src, $fx );
		if ( is_wp_error( $costs ) ) {
			return $costs;
		}

		if ( empty( $lines ) ) {
			return new WP_Error( 'wc_io_gr_lines', __( 'Add at least one product line with a product, quantity, and unit cost.', 'wc-inventory-overview' ) );
		}

		$rcur  = $fx['currency'];
		$rrate = (float) $fx['exchange_rate_to_eur'];

		$validated_lines = array();
		foreach ( $lines as $idx => $line ) {
			$pid = (int) $line['product_id'];
			if ( $pid <= 0 ) {
				return new WP_Error(
					'wc_io_gr_product',
					sprintf(
						/* translators: %d: row number (1-based) */
						__( 'Product line %d: select a product or variation.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$qty = (float) wc_stock_amount( $line['qty'] );
			if ( $qty <= 0 ) {
				return new WP_Error(
					'wc_io_gr_qty',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: quantity must be greater than zero.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$entered_unit = (float) wc_format_decimal( (string) $line['entered_unit_cost'], 6 );
			if ( $entered_unit < 0 ) {
				return new WP_Error(
					'wc_io_gr_cost',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: unit cost cannot be negative.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}
			if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && $entered_unit <= 0.0 ) {
				return new WP_Error(
					'wc_io_gr_cost',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: unit cost must be greater than zero (see Settings: allow zero supplier cost).', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error(
					'wc_io_gr_product',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: product not found.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
				return new WP_Error(
					'wc_io_gr_type',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: select a variation or simple product, not a parent variable product.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
				return new WP_Error(
					'wc_io_gr_type',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: unsupported product type.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( ! $product->managing_stock() ) {
				return new WP_Error(
					'wc_io_gr_stock',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: enable stock management for this product.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$converted_unit_eur = (float) wc_format_decimal( $entered_unit * $rrate, 6 );
			$base_line_cost     = (float) wc_format_decimal( $converted_unit_eur * $qty, 4 );

			$validated_lines[] = array(
				'product_id'              => $pid,
				'product'                 => $product,
				'product_name'            => self::format_product_line_label( $product ),
				'qty'                     => $qty,
				'entered_unit_cost'       => $entered_unit,
				'entered_currency'        => $rcur,
				'exchange_rate_to_eur'    => $rrate,
				'converted_unit_cost_eur' => $converted_unit_eur,
				'base_line_cost'          => $base_line_cost,
				'po_line_id'              => isset( $line['po_line_id'] ) ? (int) $line['po_line_id'] : 0,
			);
		}

		$validated_landed_rows = array();
		foreach ( $costs as $cidx => $crow ) {
			$type = $crow['cost_type'];
			if ( '' === $type && (float) $crow['entered_amount'] <= 0 && '' === trim( (string) $crow['note'] ) ) {
				continue;
			}
			if ( '' === $type || ! in_array( $type, self::allowed_cost_types(), true ) ) {
				return new WP_Error(
					'wc_io_gr_landed_type',
					sprintf(
						/* translators: %d: row number */
						__( 'Landed cost row %d: choose a valid cost type.', 'wc-inventory-overview' ),
						$cidx + 1
					)
				);
			}
			$entered_amt = (float) $crow['entered_amount'];
			if ( $entered_amt < 0 ) {
				return new WP_Error(
					'wc_io_gr_landed_amt',
					sprintf(
						/* translators: %d: row number */
						__( 'Landed cost row %d: amount cannot be negative.', 'wc-inventory-overview' ),
						$cidx + 1
					)
				);
			}
			$validated_landed_rows[] = $crow;
		}

		$product_subtotal_entered = 0.0;
		foreach ( $validated_lines as $vl ) {
			$product_subtotal_entered = (float) wc_format_decimal( $product_subtotal_entered + ( $vl['entered_unit_cost'] * $vl['qty'] ), 4 );
		}

		$product_subtotal = 0.0;
		foreach ( $validated_lines as $vl ) {
			$product_subtotal = (float) wc_format_decimal( $product_subtotal + $vl['base_line_cost'], 4 );
		}

		$landed_total = 0.0;
		foreach ( $validated_landed_rows as $lr ) {
			$landed_total = (float) wc_format_decimal( $landed_total + (float) $lr['amount'], 4 );
		}

		$landed_total_entered = 0.0;
		foreach ( $validated_landed_rows as $lr ) {
			if ( $lr['entered_currency'] === $rcur ) {
				$landed_total_entered = (float) wc_format_decimal( $landed_total_entered + (float) $lr['entered_amount'], 4 );
			}
		}
		$receipt_total_entered = (float) wc_format_decimal( $product_subtotal_entered + $landed_total_entered, 4 );

		if ( $landed_total > 0 && $product_subtotal <= 0 ) {
			return new WP_Error(
				'wc_io_gr_allocate',
				__( 'Additional landed costs cannot be allocated when the product subtotal is zero.', 'wc-inventory-overview' )
			);
		}

		$base_costs  = array_map(
			static function ( $vl ) {
				return (float) $vl['base_line_cost'];
			},
			$validated_lines
		);
		$allocations = self::allocate_landed( $base_costs, $landed_total, $product_subtotal );

		$receipt_total = (float) wc_format_decimal( $product_subtotal + $landed_total, 4 );

		$out_lines = array();
		foreach ( $validated_lines as $i => $vl ) {
			// @var WC_Product $product
			$product   = $vl['product'];
			$allocated = $allocations[ $i ];
			$true_line = (float) wc_format_decimal( $vl['base_line_cost'] + $allocated, 4 );
			$true_unit = (float) wc_format_decimal( $true_line / $vl['qty'], 6 );

			$preview = self::preview_line( $product, $vl['qty'], $true_unit );

			$out_lines[] = array_merge(
				array(
					'product_id'              => $vl['product_id'],
					'product_name'            => $vl['product_name'],
					'qty'                     => $vl['qty'],
					'entered_unit_cost'       => $vl['entered_unit_cost'],
					'entered_currency'        => $vl['entered_currency'],
					'exchange_rate_to_eur'    => $vl['exchange_rate_to_eur'],
					'converted_unit_cost_eur' => $vl['converted_unit_cost_eur'],
					'base_line_cost'          => $vl['base_line_cost'],
					'allocated_landed'        => $allocated,
					'true_line_cost'          => $true_line,
					'true_unit_cost'          => $true_unit,
					'po_line_id'              => $vl['po_line_id'],
				),
				$preview
			);
		}

		return array(
			'header'                   => $header,
			'fx'                       => $fx,
			'product_subtotal_entered' => $product_subtotal_entered,
			'product_subtotal'         => $product_subtotal,
			'landed_total_entered'     => $landed_total_entered,
			'landed_total'             => $landed_total,
			'receipt_total_entered'    => $receipt_total_entered,
			'receipt_total'            => $receipt_total,
			'landed_rows'              => $validated_landed_rows,
			'lines'                    => $out_lines,
			'allocation_method'        => __( 'Proportional by product line value (EUR)', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Pure, read-only preview of a line's stock/cost effect. Reuses the identical
	 * weighted-average formula as WC_Inventory_Overview_Restock_Service::apply_purchase_line_change()
	 * but performs no persistence — the actual mutation always runs through that method.
	 *
	 * @param WC_Product $product        Product or variation.
	 * @param float      $qty            Quantity being received.
	 * @param float      $true_unit_cost True unit cost (EUR).
	 * @return array<string,mixed>
	 */
	public static function preview_line( WC_Product $product, float $qty, float $true_unit_cost ): array {
		$old_stock = $product->get_stock_quantity();
		$old_stock = ( null === $old_stock || '' === $old_stock ) ? 0.0 : (float) wc_stock_amount( $old_stock );

		$old_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
		$old_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );
		$old_inv     = ( null === $old_avg ) ? 0.0 : (float) wc_format_decimal( $old_stock * $old_avg, 4 );

		$added_stock = (float) wc_stock_amount( $qty );
		$added_value = (float) wc_format_decimal( $added_stock * $true_unit_cost, 4 );
		$new_stock   = (float) wc_format_decimal( $old_stock + $added_stock, 4 );
		$new_inv     = (float) wc_format_decimal( $old_inv + $added_value, 4 );
		$new_avg     = $new_stock > 0 ? (float) wc_format_decimal( $new_inv / $new_stock, 6 ) : 0.0;

		return array(
			'old_stock'             => $old_stock,
			'old_average_raw'       => $old_avg_raw,
			'old_average_unit_cost' => $old_avg,
			'old_inventory_value'   => $old_inv,
			'new_stock'             => $new_stock,
			'new_average_unit_cost' => $new_avg,
			'new_inventory_value'   => $new_inv,
		);
	}

	/**
	 * Parse the receipt header fields from POST.
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array{supplier_id: int, reference: string, note: string}
	 */
	protected static function parse_header( array $src ) {
		$u = static function ( $v ) {
			return is_string( $v ) ? wp_unslash( $v ) : (string) $v;
		};
		return array(
			'supplier_id' => isset( $src['wc_io_gr_supplier_id'] ) ? absint( $src['wc_io_gr_supplier_id'] ) : 0,
			'reference'   => isset( $src['wc_io_gr_reference'] ) ? sanitize_text_field( $u( $src['wc_io_gr_reference'] ) ) : '',
			'note'        => isset( $src['wc_io_gr_note'] ) ? sanitize_textarea_field( $u( $src['wc_io_gr_note'] ) ) : '',
		);
	}

	/**
	 * Parse raw product/qty/unit-cost/po_line_id line arrays from POST.
	 *
	 * The po_line_id value (M5) is 0 for a direct line (M4 behavior, unchanged) or
	 * positive when this line is submitted as receiving against that Purchase
	 * Order line — the referenced line's existence, receivability, and product
	 * match are validated by Goods_Receipt_Service at draft-build time (M5 plan
	 * §Class/service changes), not here — this method only parses the raw value.
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array<int, array{product_id: int, qty: string, entered_unit_cost: string, po_line_id: int}>
	 */
	protected static function parse_product_lines( array $src ) {
		$ids      = isset( $src['wc_io_gr_line_product'] ) && is_array( $src['wc_io_gr_line_product'] ) ? $src['wc_io_gr_line_product'] : array();
		$qtys     = isset( $src['wc_io_gr_line_qty'] ) && is_array( $src['wc_io_gr_line_qty'] ) ? $src['wc_io_gr_line_qty'] : array();
		$costs    = isset( $src['wc_io_gr_line_unit_cost'] ) && is_array( $src['wc_io_gr_line_unit_cost'] ) ? $src['wc_io_gr_line_unit_cost'] : array();
		$po_lines = isset( $src['wc_io_gr_line_po_line_id'] ) && is_array( $src['wc_io_gr_line_po_line_id'] ) ? $src['wc_io_gr_line_po_line_id'] : array();
		$max      = max( count( $ids ), count( $qtys ), count( $costs ) );
		$out      = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$pid = isset( $ids[ $i ] ) ? absint( $ids[ $i ] ) : 0;
			$qty = isset( $qtys[ $i ] ) ? (string) $qtys[ $i ] : '';
			$cst = isset( $costs[ $i ] ) ? (string) $costs[ $i ] : '';
			$pol = isset( $po_lines[ $i ] ) ? absint( $po_lines[ $i ] ) : 0;
			if ( $pid <= 0 && '' === trim( $qty ) && '' === trim( $cst ) ) {
				continue;
			}
			$out[] = array(
				'product_id'        => $pid,
				'qty'               => $qty,
				'entered_unit_cost' => $cst,
				'po_line_id'        => $pol,
			);
		}
		return $out;
	}

	/**
	 * Parse and validate landed-cost rows from POST.
	 *
	 * @param array<string, mixed> $src        Unslashed POST.
	 * @param array<string, mixed> $receipt_fx Output of parse_receipt_fx (not WP_Error).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	protected static function parse_landed_costs( array $src, array $receipt_fx ) {
		$types   = isset( $src['wc_io_gr_cost_type'] ) && is_array( $src['wc_io_gr_cost_type'] ) ? $src['wc_io_gr_cost_type'] : array();
		$amounts = isset( $src['wc_io_gr_cost_amount'] ) && is_array( $src['wc_io_gr_cost_amount'] ) ? $src['wc_io_gr_cost_amount'] : array();
		$notes   = isset( $src['wc_io_gr_cost_note'] ) && is_array( $src['wc_io_gr_cost_note'] ) ? $src['wc_io_gr_cost_note'] : array();
		$currs   = isset( $src['wc_io_gr_cost_currency'] ) && is_array( $src['wc_io_gr_cost_currency'] ) ? $src['wc_io_gr_cost_currency'] : array();
		$rates   = isset( $src['wc_io_gr_cost_exchange_rate'] ) && is_array( $src['wc_io_gr_cost_exchange_rate'] ) ? $src['wc_io_gr_cost_exchange_rate'] : array();
		$max     = max( count( $types ), count( $amounts ), count( $notes ), count( $currs ), count( $rates ) );
		$out     = array();

		for ( $i = 0; $i < $max; $i++ ) {
			$t      = isset( $types[ $i ] ) ? sanitize_key( (string) $types[ $i ] ) : '';
			$a      = isset( $amounts[ $i ] ) ? (string) $amounts[ $i ] : '';
			$n      = isset( $notes[ $i ] ) ? sanitize_text_field( wp_unslash( (string) $notes[ $i ] ) ) : '';
			$lc_raw = isset( $currs[ $i ] ) ? (string) $currs[ $i ] : '';
			$lc     = '' !== trim( $lc_raw ) ? strtoupper( sanitize_text_field( wp_unslash( $lc_raw ) ) ) : $receipt_fx['currency'];
			$rate_s = isset( $rates[ $i ] ) ? (string) $rates[ $i ] : '';

			$entered_amt = (float) wc_format_decimal( $a, 4 );
			if ( '' === $t && $entered_amt <= 0 && '' === trim( $n ) ) {
				continue;
			}

			if ( ! in_array( $lc, self::allowed_receipt_currencies(), true ) ) {
				return new WP_Error(
					'wc_io_gr_landed_currency',
					sprintf(
						/* translators: %d: row number */
						__( 'Landed cost row %d: currency must be EUR, USD, or SEK.', 'wc-inventory-overview' ),
						$i + 1
					)
				);
			}

			if ( '' === trim( $rate_s ) ) {
				if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $lc ) {
					$rate = 1.0;
				} elseif ( $lc === $receipt_fx['currency'] ) {
					$rate = (float) $receipt_fx['exchange_rate_to_eur'];
				} else {
					$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $lc, $receipt_fx['exchange_rate_date'] );
					if ( is_wp_error( $lk ) ) {
						return new WP_Error(
							$lk->get_error_code(),
							sprintf(
								/* translators: 1: row number, 2: message */
								__( 'Landed cost row %1$d: %2$s', 'wc-inventory-overview' ),
								$i + 1,
								$lk->get_error_message()
							)
						);
					}
					$rate = (float) $lk['rate'];
				}
			} else {
				$rate = (float) wc_format_decimal( $rate_s, 8 );
			}
			if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $lc ) {
				$rate = 1.0;
			} elseif ( $rate <= 0 ) {
				return new WP_Error(
					'wc_io_gr_landed_fx',
					sprintf(
						/* translators: %d: row number */
						__( 'Landed cost row %d: exchange rate to EUR must be greater than zero.', 'wc-inventory-overview' ),
						$i + 1
					)
				);
			}

			$conv = (float) wc_format_decimal( $entered_amt * $rate, 4 );

			$out[] = array(
				'cost_type'            => $t,
				'entered_currency'     => $lc,
				'exchange_rate_to_eur' => $rate,
				'entered_amount'       => $entered_amt,
				'converted_amount_eur' => $conv,
				'amount'               => $conv,
				'note'                 => $n,
			);
		}

		return $out;
	}

	/**
	 * Human-readable label for a product/variation line.
	 *
	 * @param WC_Product $product Variation or simple.
	 */
	protected static function format_product_line_label( WC_Product $product ): string {
		if ( $product->is_type( 'variation' ) ) {
			$parent      = wc_get_product( $product->get_parent_id() );
			$parent_name = $parent ? $parent->get_name() : '';
			$name        = $product->get_name();
			$attrs       = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
			$line        = '' !== $parent_name ? $parent_name . ' — ' . $name : $name;
			if ( $attrs ) {
				$line .= ' (' . $attrs . ')';
			}
			return $line;
		}
		return $product->get_name();
	}
}
