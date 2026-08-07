<?php
/**
 * Batch Intake: shared validation, landed allocation, preview, and apply.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse POST/REQUEST arrays, validate, allocate landed costs, compute weighted-average preview.
 *
 * @deprecated M6 (v1.23.0) — disabled-not-deleted. The create/apply/preview
 * entry points that reached this class (admin_post_wc_io_batch_apply,
 * wp_ajax_wc_io_batch_preview) were removed from WC_Inventory_Overview_Plugin
 * in M6; this class is retained, unreachable, only so the legacy
 * apply_batch_from_post()/rollback_batch_apply() logic that produced
 * wc_io_purchase_batches* history remains available for reference during a
 * migration audit. Slated for physical removal in M8 (see the M6
 * implementation plan's Retirement strategy — "Disabled, not deleted").
 */
class WC_Inventory_Overview_Batch_Intake_Service {

	/**
	 * Allowed landed cost type slugs => admin label.
	 *
	 * @deprecated M6 — delegates to WC_Inventory_Overview_Landed_Cost_Types,
	 * the extraction target for this vocabulary (M6 §Retirement strategy).
	 * @return array<string, string>
	 */
	public static function landed_cost_type_labels() {
		return WC_Inventory_Overview_Landed_Cost_Types::landed_cost_type_labels();
	}

	/**
	 * @deprecated M6 — delegates to WC_Inventory_Overview_Landed_Cost_Types.
	 * @return string[]
	 */
	public static function allowed_cost_types() {
		return WC_Inventory_Overview_Landed_Cost_Types::allowed_cost_types();
	}

	/**
	 * @return string[]
	 */
	public static function allowed_batch_currencies() {
		return WC_Inventory_Overview_Settings::allowed_purchase_currencies();
	}

	/**
	 * Parse batch FX from POST (authoritative; never trust client-side converted totals).
	 *
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array{purchase_currency:string,exchange_rate_to_eur:float,exchange_rate_date:string}|WP_Error
	 */
	protected static function parse_batch_fx( array $src ) {
		$u = static function ( $v ) {
			return is_string( $v ) ? wp_unslash( $v ) : (string) $v;
		};
		$cur = isset( $src['wc_io_batch_purchase_currency'] ) ? strtoupper( sanitize_text_field( $u( $src['wc_io_batch_purchase_currency'] ) ) ) : WC_Inventory_Overview_Settings::get_default_purchase_currency();
		if ( ! in_array( $cur, self::allowed_batch_currencies(), true ) ) {
			return new WP_Error( 'wc_io_batch_currency', __( 'Purchase currency must be EUR, USD, or SEK.', 'wc-inventory-overview' ) );
		}

		$date = isset( $src['wc_io_batch_exchange_rate_date'] ) ? sanitize_text_field( $u( $src['wc_io_batch_exchange_rate_date'] ) ) : '';
		if ( '' === $date ) {
			$date = wp_date( 'Y-m-d', null, wp_timezone() );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wc_io_batch_fx_date', __( 'Exchange rate date must be YYYY-MM-DD.', 'wc-inventory-overview' ) );
		}
		$dp = explode( '-', $date );
		if ( 3 !== count( $dp ) || ! checkdate( (int) $dp[1], (int) $dp[2], (int) $dp[0] ) ) {
			return new WP_Error( 'wc_io_batch_fx_date', __( 'Exchange rate date is not a valid calendar date.', 'wc-inventory-overview' ) );
		}

		$rate_raw = isset( $src['wc_io_batch_exchange_rate_to_eur'] ) ? $u( $src['wc_io_batch_exchange_rate_to_eur'] ) : '';
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
			return new WP_Error( 'wc_io_batch_fx', __( 'Exchange rate to EUR must be greater than zero.', 'wc-inventory-overview' ) );
		}

		return array(
			'purchase_currency'    => $cur,
			'exchange_rate_to_eur' => $rate,
			'exchange_rate_date'   => $date,
		);
	}

	/**
	 * Build preview data from raw POST-style array (e.g. $_POST).
	 *
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build_preview_from_post( array $src ) {
		$fx = self::parse_batch_fx( $src );
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
			return new WP_Error( 'wc_io_batch_lines', __( 'Add at least one product line with a product, quantity, and line total.', 'wc-inventory-overview' ) );
		}

		$pcur  = $fx['purchase_currency'];
		$brate = (float) $fx['exchange_rate_to_eur'];

		$validated_lines = array();
		foreach ( $lines as $idx => $line ) {
			$pid = (int) $line['product_id'];
			if ( $pid <= 0 ) {
				return new WP_Error(
					'wc_io_batch_product',
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
					'wc_io_batch_qty',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: quantity must be greater than zero.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$entered_line = (float) wc_format_decimal( (string) $line['base_line_cost'], 4 );
			if ( $entered_line < 0 ) {
				return new WP_Error(
					'wc_io_batch_cost',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: total line cost cannot be negative.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && $entered_line <= 0.0 ) {
				return new WP_Error(
					'wc_io_batch_cost',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: total line cost must be greater than zero (see Settings: allow zero supplier cost).', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$product = wc_get_product( $pid );
			if ( ! $product instanceof WC_Product ) {
				return new WP_Error(
					'wc_io_batch_product',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: product not found.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
				return new WP_Error(
					'wc_io_batch_type',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: select a variation or simple product, not a parent variable product.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
				return new WP_Error(
					'wc_io_batch_type',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: unsupported product type.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			if ( ! $product->managing_stock() ) {
				return new WP_Error(
					'wc_io_batch_stock',
					sprintf(
						/* translators: %d: row number */
						__( 'Product line %d: enable stock management for this product.', 'wc-inventory-overview' ),
						$idx + 1
					)
				);
			}

			$converted_eur = (float) wc_format_decimal( $entered_line * $brate, 4 );

			$validated_lines[] = array(
				'product_id'              => $pid,
				'product'                 => $product,
				'product_name'            => self::format_product_line_label( $product ),
				'qty'                     => $qty,
				'entered_line_cost'       => $entered_line,
				'entered_currency'        => $pcur,
				'exchange_rate_to_eur'    => $brate,
				'converted_line_cost_eur' => $converted_eur,
				'base_line_cost'          => $converted_eur,
				'base_unit_cost'          => (float) wc_format_decimal( $converted_eur / $qty, 6 ),
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
					'wc_io_batch_landed_type',
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
					'wc_io_batch_landed_amt',
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
			$product_subtotal_entered = (float) wc_format_decimal( $product_subtotal_entered + $vl['entered_line_cost'], 4 );
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
			if ( $lr['entered_currency'] === $pcur ) {
				$landed_total_entered = (float) wc_format_decimal( $landed_total_entered + (float) $lr['entered_amount'], 4 );
			}
		}
		$batch_total_entered = (float) wc_format_decimal( $product_subtotal_entered + $landed_total_entered, 4 );

		if ( $landed_total > 0 && $product_subtotal <= 0 ) {
			return new WP_Error(
				'wc_io_batch_allocate',
				__( 'Additional landed costs cannot be allocated when the product subtotal is zero.', 'wc-inventory-overview' )
			);
		}

		$n = count( $validated_lines );
		$allocations = array_fill( 0, $n, 0.0 );
		if ( $landed_total > 0 && $product_subtotal > 0 ) {
			$sum_prev = 0.0;
			for ( $i = 0; $i < $n; $i++ ) {
				if ( $i < $n - 1 ) {
					$ratio             = $validated_lines[ $i ]['base_line_cost'] / $product_subtotal;
					$allocations[ $i ] = (float) wc_format_decimal( $landed_total * $ratio, 4 );
					$sum_prev          = (float) wc_format_decimal( $sum_prev + $allocations[ $i ], 4 );
				} else {
					$allocations[ $i ] = (float) wc_format_decimal( $landed_total - $sum_prev, 4 );
				}
			}
		}

		$batch_total = (float) wc_format_decimal( $product_subtotal + $landed_total, 4 );

		$out_lines = array();
		foreach ( $validated_lines as $i => $vl ) {
			/** @var WC_Product $product */
			$product     = $vl['product'];
			$allocated   = $allocations[ $i ];
			$true_line   = (float) wc_format_decimal( $vl['base_line_cost'] + $allocated, 4 );
			$true_unit   = (float) wc_format_decimal( $true_line / $vl['qty'], 6 );

			$old_stock = $product->get_stock_quantity();
			$old_stock = ( null === $old_stock || '' === $old_stock ) ? 0.0 : (float) wc_stock_amount( $old_stock );
			$old_avg_raw = WC_Inventory_Overview_Costing::get_average_raw( $product );
			$old_avg     = WC_Inventory_Overview_Costing::get_average_float( $product );
			$old_inv     = ( null === $old_avg )
				? 0.0
				: (float) wc_format_decimal( $old_stock * $old_avg, 4 );

			$added_stock = (float) wc_stock_amount( $vl['qty'] );
			$added_value = (float) wc_format_decimal( $added_stock * $true_unit, 4 );
			$new_stock   = (float) wc_format_decimal( $old_stock + $added_stock, 4 );
			$new_inv     = (float) wc_format_decimal( $old_inv + $added_value, 4 );
			$new_avg     = $new_stock > 0 ? (float) wc_format_decimal( $new_inv / $new_stock, 6 ) : 0.0;

			$out_lines[] = array(
				'product_id'              => $vl['product_id'],
				'product_name'            => $vl['product_name'],
				'qty'                     => $vl['qty'],
				'entered_line_cost'       => $vl['entered_line_cost'],
				'entered_currency'        => $vl['entered_currency'],
				'exchange_rate_to_eur'    => $vl['exchange_rate_to_eur'],
				'converted_line_cost_eur' => $vl['converted_line_cost_eur'],
				'base_line_cost'          => $vl['base_line_cost'],
				'base_unit_cost'          => $vl['base_unit_cost'],
				'allocated_landed'        => $allocated,
				'true_line_cost'          => $true_line,
				'true_unit_cost'          => $true_unit,
				'old_stock'               => $old_stock,
				'old_average_raw'         => $old_avg_raw,
				'old_average'             => $old_avg,
				'old_inventory_value'     => $old_inv,
				'preview_new_stock'       => $new_stock,
				'preview_new_average'     => $new_avg,
				'preview_new_inv_value'   => $new_inv,
			);
		}

		return array(
			'header'                   => $header,
			'fx'                       => $fx,
			'product_subtotal_entered' => $product_subtotal_entered,
			'product_subtotal'         => $product_subtotal,
			'landed_total_entered'     => $landed_total_entered,
			'landed_total'             => $landed_total,
			'batch_total_entered'      => $batch_total_entered,
			'batch_total'              => $batch_total,
			'landed_rows'              => $validated_landed_rows,
			'lines'                    => $out_lines,
			'allocation_method'        => __( 'Proportional by product line value (EUR)', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Apply a validated batch: DB rows, stock, costs, movements. Re-validates via build_preview_from_post (same math as preview).
	 *
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return int|WP_Error Batch ID on success.
	 */
	public static function apply_batch_from_post( array $src ) {
		$confirmed = isset( $src['wc_io_batch_confirm'] ) && (string) $src['wc_io_batch_confirm'] === '1';
		if ( ! $confirmed ) {
			return new WP_Error( 'wc_io_batch_confirm', __( 'Confirm the batch before applying.', 'wc-inventory-overview' ) );
		}

		$preview = self::build_preview_from_post( $src );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		foreach ( $preview['lines'] as $row ) {
			if ( ! WC_Inventory_Overview_Settings::allow_zero_supplier_cost() && (float) $row['true_unit_cost'] <= 0.0 ) {
				return new WP_Error(
					'wc_io_batch_true_unit',
					__( 'True unit cost must be greater than zero for each line (see Settings: allow zero supplier cost).', 'wc-inventory-overview' )
				);
			}
		}

		global $wpdb;

		$header = $preview['header'];
		$fx     = $preview['fx'];
		$batches = $wpdb->prefix . 'wc_io_purchase_batches';

		$ins_batch = $wpdb->insert(
			$batches,
			array(
				'supplier_name'            => $header['supplier_name'],
				'reference'                => $header['reference'],
				'purchase_currency'        => $fx['purchase_currency'],
				'exchange_rate_to_eur'     => wc_format_decimal( $fx['exchange_rate_to_eur'], 8 ),
				'exchange_rate_date'       => $fx['exchange_rate_date'],
				'product_subtotal_entered' => wc_format_decimal( $preview['product_subtotal_entered'], 4 ),
				'landed_total_entered'     => wc_format_decimal( $preview['landed_total_entered'], 4 ),
				'batch_total_entered'      => wc_format_decimal( $preview['batch_total_entered'], 4 ),
				'product_subtotal'         => wc_format_decimal( $preview['product_subtotal'], 4 ),
				'landed_total'             => wc_format_decimal( $preview['landed_total'], 4 ),
				'batch_total'              => wc_format_decimal( $preview['batch_total'], 4 ),
				'note'                     => $header['note'],
				'user_id'                  => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $ins_batch ) {
			return new WP_Error( 'wc_io_batch_db', __( 'Could not create batch record.', 'wc-inventory-overview' ) );
		}

		$batch_id = (int) $wpdb->insert_id;
		if ( $batch_id <= 0 ) {
			return new WP_Error( 'wc_io_batch_db', __( 'Could not read batch ID.', 'wc-inventory-overview' ) );
		}

		$completed = array();

		foreach ( $preview['lines'] as $idx => $row ) {
			$line_id = (int) $row['product_id'];
			$applied = WC_Inventory_Overview_Restock_Service::apply_purchase_line_change(
				$line_id,
				(float) $row['qty'],
				(float) $row['true_unit_cost']
			);

			if ( is_wp_error( $applied ) ) {
				self::rollback_batch_apply( $batch_id, $completed );
				return $applied;
			}

			$note = self::build_movement_note_for_line( $batch_id, $header, $row, $preview );

			$mrow = array_merge(
				$applied['movement'],
				array(
					'total_value'   => (float) wc_format_decimal( $row['true_line_cost'], 4 ),
					'unit_cost'     => (float) wc_format_decimal( $row['true_unit_cost'], 6 ),
					'supplier_name' => $header['supplier_name'],
					'note'          => $note,
				)
			);

			$mov_ok = WC_Inventory_Overview_Movements::insert_purchase_batch( $mrow );
			if ( ! $mov_ok ) {
				WC_Inventory_Overview_Restock_Service::restore_snapshot( $applied['line_id'], $applied['snapshot'] );
				self::rollback_batch_apply( $batch_id, $completed );
				return new WP_Error(
					'wc_io_batch_movement',
					sprintf(
						/* translators: %s: DB error */
						__( 'Could not write movement log. %s', 'wc-inventory-overview' ),
						$wpdb->last_error ? $wpdb->last_error : __( 'Unknown database error.', 'wc-inventory-overview' )
					)
				);
			}

			$completed[] = array(
				'movement_id' => (int) $wpdb->insert_id,
				'line_id'     => $applied['line_id'],
				'snapshot'    => $applied['snapshot'],
			);
		}

		$costs_table = $wpdb->prefix . 'wc_io_purchase_batch_costs';
		foreach ( $preview['landed_rows'] as $lr ) {
			$ins_c = $wpdb->insert(
				$costs_table,
				array(
					'batch_id'               => $batch_id,
					'cost_type'              => $lr['cost_type'],
					'entered_currency'       => $lr['entered_currency'],
					'exchange_rate_to_eur'   => wc_format_decimal( $lr['exchange_rate_to_eur'], 8 ),
					'entered_amount'         => wc_format_decimal( $lr['entered_amount'], 4 ),
					'converted_amount_eur'   => wc_format_decimal( $lr['converted_amount_eur'], 4 ),
					'amount'                 => wc_format_decimal( $lr['amount'], 4 ),
					'note'                   => $lr['note'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ins_c ) {
				self::rollback_batch_apply( $batch_id, $completed );
				return new WP_Error( 'wc_io_batch_db', __( 'Could not save landed cost rows.', 'wc-inventory-overview' ) );
			}
		}

		$lines_table = $wpdb->prefix . 'wc_io_purchase_batch_lines';
		foreach ( $preview['lines'] as $row ) {
			$product = wc_get_product( (int) $row['product_id'] );
			if ( ! $product instanceof WC_Product ) {
				self::rollback_batch_apply( $batch_id, $completed );
				return new WP_Error( 'wc_io_batch_product', __( 'Product missing while saving batch lines.', 'wc-inventory-overview' ) );
			}
			$parent_id    = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
			$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;

			$old_avg_sql = null === $row['old_average_raw'] || '' === $row['old_average_raw']
				? null
				: wc_format_decimal( $row['old_average_raw'], 6 );

			$line_insert = array(
				'batch_id'                => $batch_id,
				'product_id'              => $parent_id,
				'variation_id'            => $variation_id,
				'quantity'                => wc_format_decimal( $row['qty'], 4 ),
				'entered_currency'        => $row['entered_currency'],
				'exchange_rate_to_eur'    => wc_format_decimal( $row['exchange_rate_to_eur'], 8 ),
				'entered_line_cost'       => wc_format_decimal( $row['entered_line_cost'], 4 ),
				'converted_line_cost_eur' => wc_format_decimal( $row['converted_line_cost_eur'], 4 ),
				'base_line_cost'          => wc_format_decimal( $row['base_line_cost'], 4 ),
				'allocated_landed_cost'   => wc_format_decimal( $row['allocated_landed'], 4 ),
				'true_line_cost'          => wc_format_decimal( $row['true_line_cost'], 4 ),
				'true_unit_cost'          => wc_format_decimal( $row['true_unit_cost'], 6 ),
				'old_stock'               => wc_format_decimal( $row['old_stock'], 4 ),
				'new_stock'               => wc_format_decimal( $row['preview_new_stock'], 4 ),
			);
			$line_formats = array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );
			if ( null !== $old_avg_sql ) {
				$line_insert['old_average_unit_cost'] = $old_avg_sql;
				$line_formats[]                       = '%s';
			}
			$line_insert['new_average_unit_cost']   = wc_format_decimal( $row['preview_new_average'], 6 );
			$line_insert['old_inventory_value']    = wc_format_decimal( $row['old_inventory_value'], 4 );
			$line_insert['new_inventory_value']    = wc_format_decimal( $row['preview_new_inv_value'], 4 );
			$line_formats[] = '%s';
			$line_formats[] = '%s';
			$line_formats[] = '%s';

			$ins_l = $wpdb->insert( $lines_table, $line_insert, $line_formats );

			if ( false === $ins_l ) {
				self::rollback_batch_apply( $batch_id, $completed );
				return new WP_Error( 'wc_io_batch_db', __( 'Could not save batch line rows.', 'wc-inventory-overview' ) );
			}
		}

		foreach ( $preview['lines'] as $row ) {
			$pid = (int) $row['product_id'];
			wc_delete_product_transients( $pid );
			$p = wc_get_product( $pid );
			if ( $p && $p->is_type( 'variation' ) ) {
				wc_delete_product_transients( (int) $p->get_parent_id() );
			}
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
		}

		return $batch_id;
	}

	/**
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param array<int, array{movement_id: int, line_id: int, snapshot: array<string, mixed>}> $completed Applied lines in order.
	 */
	protected static function rollback_batch_apply( $batch_id, array $completed ) {
		global $wpdb;

		$mov_table = WC_Inventory_Overview_Movements::table_name();
		foreach ( array_reverse( $completed ) as $done ) {
			$wpdb->delete( $mov_table, array( 'id' => (int) $done['movement_id'] ), array( '%d' ) );
			WC_Inventory_Overview_Restock_Service::restore_snapshot( (int) $done['line_id'], $done['snapshot'] );
		}

		$wpdb->delete( $wpdb->prefix . 'wc_io_purchase_batch_lines', array( 'batch_id' => (int) $batch_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wc_io_purchase_batch_costs', array( 'batch_id' => (int) $batch_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'wc_io_purchase_batches', array( 'id' => (int) $batch_id ), array( '%d' ) );
	}

	/**
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param array<string, mixed> $header   Parsed header.
	 * @param array<string, mixed> $line_row One preview line.
	 * @param array<string, mixed> $preview  Full preview.
	 */
	protected static function build_movement_note_for_line( $batch_id, array $header, array $line_row, array $preview ): string {
		$ref = '' !== trim( (string) $header['reference'] ) ? $header['reference'] : '—';
		$fx  = isset( $preview['fx'] ) && is_array( $preview['fx'] ) ? $preview['fx'] : array(
			'purchase_currency'    => 'EUR',
			'exchange_rate_to_eur' => 1.0,
			'exchange_rate_date'   => '',
		);
		$lines = array(
			sprintf( 'Batch ID: %d', (int) $batch_id ),
			sprintf( 'Reference: %s', $ref ),
			sprintf(
				/* translators: 1: currency code, 2: rate to EUR, 3: date */
				__( 'Purchase currency: %1$s; Exchange rate to EUR: %2$s; Exchange rate date: %3$s', 'wc-inventory-overview' ),
				$fx['purchase_currency'],
				wc_format_decimal( (string) $fx['exchange_rate_to_eur'], 8 ),
				'' !== trim( (string) $fx['exchange_rate_date'] ) ? $fx['exchange_rate_date'] : '—'
			),
			'',
		);
		if ( '' !== trim( (string) $header['note'] ) ) {
			$lines[] = sprintf(
				/* translators: %s: batch note */
				__( 'Batch note: %s', 'wc-inventory-overview' ),
				$header['note']
			);
			$lines[] = '';
		}
		$entered = wc_format_decimal( (string) $line_row['entered_line_cost'], 4 ) . ' ' . $line_row['entered_currency'];
		$lines[] = sprintf(
			/* translators: 1: entered subtotal with currency, 2: converted EUR, 3: allocated landed EUR, 4: true unit EUR */
			__( 'Entered subtotal: %1$s; Converted EUR: %2$s; Allocated landed EUR: %3$s; True unit EUR: %4$s', 'wc-inventory-overview' ),
			$entered,
			wc_format_decimal( (string) $line_row['converted_line_cost_eur'], 4 ),
			wc_format_decimal( (string) $line_row['allocated_landed'], 4 ),
			wc_format_decimal( (string) $line_row['true_unit_cost'], 6 )
		);
		if ( ! empty( $preview['landed_rows'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Additional landed costs (EUR after row FX):', 'wc-inventory-overview' );
			$labels = self::landed_cost_type_labels();
			foreach ( $preview['landed_rows'] as $lr ) {
				$lb = isset( $labels[ $lr['cost_type'] ] ) ? $labels[ $lr['cost_type'] ] : $lr['cost_type'];
				$lines[] = sprintf(
					'%1$s: %2$s %3$s → %4$s EUR',
					$lb,
					wc_format_decimal( (string) $lr['entered_amount'], 4 ),
					$lr['entered_currency'],
					wc_format_decimal( (string) $lr['converted_amount_eur'], 4 )
				);
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array{supplier_name: string, reference: string, note: string}
	 */
	protected static function parse_header( array $src ) {
		$u = static function ( $v ) {
			return is_string( $v ) ? wp_unslash( $v ) : (string) $v;
		};
		return array(
			'supplier_name' => isset( $src['wc_io_batch_supplier'] ) ? sanitize_text_field( $u( $src['wc_io_batch_supplier'] ) ) : '',
			'reference'     => isset( $src['wc_io_batch_reference'] ) ? sanitize_text_field( $u( $src['wc_io_batch_reference'] ) ) : '',
			'note'          => isset( $src['wc_io_batch_note'] ) ? sanitize_textarea_field( $u( $src['wc_io_batch_note'] ) ) : '',
		);
	}

	/**
	 * @param array<string, mixed> $src Unslashed POST.
	 * @return array<int, array{product_id: int, qty: string, base_line_cost: string}>
	 */
	protected static function parse_product_lines( array $src ) {
		$ids   = isset( $src['wc_io_batch_line_product'] ) && is_array( $src['wc_io_batch_line_product'] ) ? $src['wc_io_batch_line_product'] : array();
		$qtys  = isset( $src['wc_io_batch_line_qty'] ) && is_array( $src['wc_io_batch_line_qty'] ) ? $src['wc_io_batch_line_qty'] : array();
		$costs = isset( $src['wc_io_batch_line_cost'] ) && is_array( $src['wc_io_batch_line_cost'] ) ? $src['wc_io_batch_line_cost'] : array();
		$max   = max( count( $ids ), count( $qtys ), count( $costs ) );
		$out   = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$pid = isset( $ids[ $i ] ) ? absint( $ids[ $i ] ) : 0;
			$qty = isset( $qtys[ $i ] ) ? (string) $qtys[ $i ] : '';
			$cst = isset( $costs[ $i ] ) ? (string) $costs[ $i ] : '';
			if ( $pid <= 0 && '' === trim( $qty ) && '' === trim( $cst ) ) {
				continue;
			}
			$out[] = array(
				'product_id'     => $pid,
				'qty'            => $qty,
				'base_line_cost' => $cst,
			);
		}
		return $out;
	}

	/**
	 * @param array<string, mixed> $src      Unslashed POST.
	 * @param array<string, mixed> $batch_fx Output of parse_batch_fx (not WP_Error).
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	protected static function parse_landed_costs( array $src, array $batch_fx ) {
		$types   = isset( $src['wc_io_batch_cost_type'] ) && is_array( $src['wc_io_batch_cost_type'] ) ? $src['wc_io_batch_cost_type'] : array();
		$amounts = isset( $src['wc_io_batch_cost_amount'] ) && is_array( $src['wc_io_batch_cost_amount'] ) ? $src['wc_io_batch_cost_amount'] : array();
		$notes   = isset( $src['wc_io_batch_cost_note'] ) && is_array( $src['wc_io_batch_cost_note'] ) ? $src['wc_io_batch_cost_note'] : array();
		$currs   = isset( $src['wc_io_batch_cost_currency'] ) && is_array( $src['wc_io_batch_cost_currency'] ) ? $src['wc_io_batch_cost_currency'] : array();
		$rates   = isset( $src['wc_io_batch_cost_exchange_rate'] ) && is_array( $src['wc_io_batch_cost_exchange_rate'] ) ? $src['wc_io_batch_cost_exchange_rate'] : array();
		$max     = max( count( $types ), count( $amounts ), count( $notes ), count( $currs ), count( $rates ) );
		$out     = array();

		for ( $i = 0; $i < $max; $i++ ) {
			$t = isset( $types[ $i ] ) ? sanitize_key( (string) $types[ $i ] ) : '';
			$a = isset( $amounts[ $i ] ) ? (string) $amounts[ $i ] : '';
			$n = isset( $notes[ $i ] ) ? sanitize_text_field( wp_unslash( (string) $notes[ $i ] ) ) : '';
			$lc_raw = isset( $currs[ $i ] ) ? (string) $currs[ $i ] : '';
			$lc     = '' !== trim( $lc_raw ) ? strtoupper( sanitize_text_field( wp_unslash( $lc_raw ) ) ) : $batch_fx['purchase_currency'];
			$rate_s = isset( $rates[ $i ] ) ? (string) $rates[ $i ] : '';

			$entered_amt = (float) wc_format_decimal( $a, 4 );
			if ( '' === $t && $entered_amt <= 0 && '' === trim( $n ) ) {
				continue;
			}

			if ( ! in_array( $lc, self::allowed_batch_currencies(), true ) ) {
				return new WP_Error(
					'wc_io_batch_landed_currency',
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
				} elseif ( $lc === $batch_fx['purchase_currency'] ) {
					$rate = (float) $batch_fx['exchange_rate_to_eur'];
				} else {
					$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $lc, $batch_fx['exchange_rate_date'] );
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
					'wc_io_batch_landed_fx',
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
	 * @param WC_Product $product Variation or simple.
	 */
	protected static function format_product_line_label( WC_Product $product ): string {
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$parent_name = $parent ? $parent->get_name() : '';
			$name        = $product->get_name();
			$attrs       = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
			$line        = $parent_name !== '' ? $parent_name . ' — ' . $name : $name;
			if ( $attrs ) {
				$line .= ' (' . $attrs . ')';
			}
			return $line;
		}
		return $product->get_name();
	}

	/**
	 * Plain amount + ISO currency for preview (not WooCommerce formatted money).
	 *
	 * @param float|string $amount   Decimal amount.
	 * @param string       $currency ISO code.
	 */
	protected static function format_amount_currency( $amount, string $currency ): string {
		return trim( wc_format_decimal( (string) $amount, 4 ) . ' ' . strtoupper( $currency ) );
	}

	/**
	 * Render preview table + summaries (escaped HTML).
	 *
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param array<string, mixed> $result Output of build_preview_from_post.
	 */
	public static function render_preview_markup( array $result ): string {
		$fx = isset( $result['fx'] ) && is_array( $result['fx'] ) ? $result['fx'] : array(
			'purchase_currency'    => 'EUR',
			'exchange_rate_to_eur' => 1.0,
			'exchange_rate_date'   => '',
		);
		$pcur = isset( $fx['purchase_currency'] ) ? (string) $fx['purchase_currency'] : 'EUR';

		ob_start();
		echo '<div class="wc-io-batch-preview-inner">';
		echo '<p class="wc-io-batch-preview-banner notice notice-info inline"><strong>' . esc_html__( 'Preview only — nothing has been saved and stock has not changed.', 'wc-inventory-overview' ) . '</strong> ';
		echo esc_html__( 'Numbers below match what Apply Batch will use after you confirm.', 'wc-inventory-overview' ) . '</p>';

		echo '<div class="wc-io-batch-preview-scroll">';
		echo '<table class="widefat striped wc-io-batch-preview-table"><thead><tr>';
		echo '<th scope="col" class="wc-io-batch-preview-col--product">' . esc_html__( 'Product / variation', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num" title="' . esc_attr__( 'Stock on hand before this batch', 'wc-inventory-overview' ) . '">' . esc_html__( 'Current stock', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num" title="' . esc_attr__( 'Weighted average unit cost before this batch', 'wc-inventory-overview' ) . '">' . esc_html__( 'Current avg. unit (EUR)', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num" title="' . esc_attr__( 'Inventory value before this batch', 'wc-inventory-overview' ) . '">' . esc_html__( 'Current inv. value', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Qty in batch', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--batch-fx">' . esc_html__( 'Entered subtotal', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--batch-fx">' . esc_html__( 'Currency', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--batch-fx">' . esc_html__( 'FX rate', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--eur-focus">' . esc_html__( 'Converted EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--eur-focus" title="' . esc_attr__( 'Share of additional landed costs allocated to this line', 'wc-inventory-overview' ) . '">' . esc_html__( 'Allocated landed EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--eur-focus">' . esc_html__( 'True line EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-th--eur-focus">' . esc_html__( 'True unit EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-col--accent" title="' . esc_attr__( 'Stock after apply', 'wc-inventory-overview' ) . '">' . esc_html__( 'New stock', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-col--accent" title="' . esc_attr__( 'Weighted average unit cost after apply', 'wc-inventory-overview' ) . '">' . esc_html__( 'New avg. unit (EUR)', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num wc-io-batch-preview-col--accent" title="' . esc_attr__( 'Inventory value after apply', 'wc-inventory-overview' ) . '">' . esc_html__( 'New inv. value', 'wc-inventory-overview' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $result['lines'] as $row ) {
			$old_avg = $row['old_average'];
			$old_avg_html = ( null === $old_avg || '' === $old_avg )
				? '—'
				: wp_kses_post( wc_price( (float) $old_avg ) );
			$old_inv_html = ( null === $old_avg || '' === $old_avg )
				? '—'
				: wp_kses_post( wc_price( (float) $row['old_inventory_value'] ) );

			$ent_cur = isset( $row['entered_currency'] ) ? (string) $row['entered_currency'] : $pcur;
			$ent_amt = isset( $row['entered_line_cost'] ) ? $row['entered_line_cost'] : $row['base_line_cost'];
			$conv    = isset( $row['converted_line_cost_eur'] ) ? $row['converted_line_cost_eur'] : $row['base_line_cost'];
			$rate    = isset( $row['exchange_rate_to_eur'] ) ? $row['exchange_rate_to_eur'] : 1.0;

			echo '<tr>';
			echo '<td class="wc-io-batch-preview-col--product">' . esc_html( $row['product_name'] ) . '</td>';
			echo '<td class="wc-io-num">' . esc_html( wc_format_decimal( $row['old_stock'], 4 ) ) . '</td>';
			echo '<td class="wc-io-num">' . $old_avg_html . '</td>';
			echo '<td class="wc-io-num">' . $old_inv_html . '</td>';
			echo '<td class="wc-io-num">' . esc_html( wc_format_decimal( $row['qty'], 4 ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--batch-fx">' . esc_html( self::format_amount_currency( $ent_amt, $ent_cur ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--batch-fx">' . esc_html( $ent_cur ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--batch-fx">' . esc_html( wc_format_decimal( (string) $rate, 8 ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--eur-focus">' . wp_kses_post( wc_price( (float) $conv ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--eur-focus">' . wp_kses_post( wc_price( $row['allocated_landed'] ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--eur-focus">' . wp_kses_post( wc_price( $row['true_line_cost'] ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-td--eur-focus">' . wp_kses_post( wc_price( $row['true_unit_cost'] ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-col--accent">' . esc_html( wc_format_decimal( $row['preview_new_stock'], 4 ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-col--accent">' . wp_kses_post( wc_price( $row['preview_new_average'] ) ) . '</td>';
			echo '<td class="wc-io-num wc-io-batch-preview-col--accent">' . wp_kses_post( wc_price( $row['preview_new_inv_value'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="wc-io-batch-preview-totals">';
		echo '<div class="wc-io-batch-preview-totals__row"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Product subtotal (entered)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( self::format_amount_currency( $result['product_subtotal_entered'], $pcur ) ) . '</span></div>';
		echo '<div class="wc-io-batch-preview-totals__row"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Product subtotal (EUR)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . wp_kses_post( wc_price( $result['product_subtotal'] ) ) . '</span></div>';
		echo '<div class="wc-io-batch-preview-totals__row"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Additional landed (entered, purchase currency only)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( self::format_amount_currency( $result['landed_total_entered'], $pcur ) ) . '</span></div>';
		$batch_entered = isset( $result['batch_total_entered'] ) ? (float) $result['batch_total_entered'] : (float) $result['product_subtotal_entered'] + (float) $result['landed_total_entered'];
		echo '<div class="wc-io-batch-preview-totals__row"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Batch total (entered, purchase currency)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( self::format_amount_currency( $batch_entered, $pcur ) ) . '</span></div>';
		echo '<div class="wc-io-batch-preview-totals__row"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Additional landed (EUR)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . wp_kses_post( wc_price( $result['landed_total'] ) ) . '</span></div>';
		echo '<div class="wc-io-batch-preview-totals__row wc-io-batch-preview-totals__row--grand"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Batch total (EUR)', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . wp_kses_post( wc_price( $result['batch_total'] ) ) . '</span></div>';
		echo '<div class="wc-io-batch-preview-totals__row wc-io-batch-preview-totals__row--meta"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Batch exchange rate to EUR', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( wc_format_decimal( (string) $fx['exchange_rate_to_eur'], 8 ) . ' (' . $pcur . ' → EUR)' ) . '</span></div>';
		if ( '' !== trim( (string) $fx['exchange_rate_date'] ) ) {
			echo '<div class="wc-io-batch-preview-totals__row wc-io-batch-preview-totals__row--meta"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Exchange rate date', 'wc-inventory-overview' ) . '</span>';
			echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( (string) $fx['exchange_rate_date'] ) . '</span></div>';
		}
		echo '<div class="wc-io-batch-preview-totals__row wc-io-batch-preview-totals__row--meta"><span class="wc-io-batch-preview-totals__dt">' . esc_html__( 'Landed allocation', 'wc-inventory-overview' ) . '</span>';
		echo '<span class="wc-io-batch-preview-totals__dd">' . esc_html( $result['allocation_method'] ) . '</span></div>';
		echo '</div>';

		if ( ! empty( $result['landed_rows'] ) ) {
			$labels = self::landed_cost_type_labels();
			echo '<h4 class="wc-io-batch-preview-landed-title">' . esc_html__( 'Landed costs in this batch', 'wc-inventory-overview' ) . '</h4>';
			echo '<table class="widefat striped wc-io-batch-preview-landed-table"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Type', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Entered amount', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Currency', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="wc-io-num">' . esc_html__( 'FX rate', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Converted EUR', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $result['landed_rows'] as $lr ) {
				$lt = $lr['cost_type'];
				$lb = isset( $labels[ $lt ] ) ? $labels[ $lt ] : $lt;
				echo '<tr><td>' . esc_html( $lb ) . '</td>';
				echo '<td class="wc-io-num">' . esc_html( wc_format_decimal( (string) $lr['entered_amount'], 4 ) ) . '</td>';
				echo '<td class="wc-io-num">' . esc_html( (string) $lr['entered_currency'] ) . '</td>';
				echo '<td class="wc-io-num">' . esc_html( wc_format_decimal( (string) $lr['exchange_rate_to_eur'], 8 ) ) . '</td>';
				echo '<td class="wc-io-num">' . wp_kses_post( wc_price( (float) $lr['converted_amount_eur'] ) ) . '</td>';
				echo '<td>' . esc_html( $lr['note'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '</div>';
		return (string) ob_get_clean();
	}
}
