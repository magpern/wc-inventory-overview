<?php
/**
 * Dashboard-only inventory valuation KPIs (sellable in-stock lines).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aggregates cost value, potential revenue, potential gross profit, and missing-cost counts.
 *
 * Uses the same repository filters as the Inventory Overview summary (see {@see WC_Inventory_Overview_Summary::build()}).
 * Does not alter costing meta or movement records.
 */
class WC_Inventory_Overview_Dashboard_Inventory_Metrics {

	/**
	 * @param array<string, mixed> $base_params Base list params (search, category_tt_id, stock_status, exclude_private).
	 * @return array{
	 *   inventory_cost_value: float,
	 *   potential_revenue: float,
	 *   potential_gross_profit: float,
	 *   missing_cost_lines: int
	 * }
	 */
	public static function compute_for_dashboard( array $base_params ) {
		$inventory_cost_value   = 0.0;
		$potential_revenue      = 0.0;
		$potential_gross_profit = 0.0;
		$missing_cost_lines     = 0;

		$page     = 1;
		$per_page = 200;
		$max_page = 40;

		$params = array_merge(
			$base_params,
			array(
				'sellable_stock_lines_only' => true,
				'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
				'per_page'                  => $per_page,
			)
		);

		while ( $page <= $max_page ) {
			$params['paged'] = $page;
			$r               = WC_Inventory_Overview_Repository::query_products( $params );
			if ( empty( $r['products'] ) ) {
				break;
			}

			foreach ( $r['products'] as $p ) {
				if ( ! $p instanceof WC_Product ) {
					continue;
				}
				if ( ! $p->is_type( 'simple' ) && ! $p->is_type( 'variation' ) ) {
					continue;
				}
				if ( ! $p->managing_stock() || ! $p->is_in_stock() ) {
					continue;
				}

				$qty_raw = $p->get_stock_quantity();
				if ( null === $qty_raw || '' === $qty_raw ) {
					continue;
				}
				$qty = (float) wc_stock_amount( $qty_raw );
				if ( $qty <= 0 ) {
					continue;
				}

				$price = (float) wc_format_decimal( (string) $p->get_price(), wc_get_price_decimals() );
				$potential_revenue += $qty * $price;

				$inv_meta_raw = $p->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
				$cost_line    = 0.0;
				if ( null !== $inv_meta_raw && '' !== trim( (string) $inv_meta_raw ) ) {
					$cost_line = (float) wc_format_decimal( (string) $inv_meta_raw, 8 );
				} else {
					$avg_for_val = WC_Inventory_Overview_Costing::get_average_float( $p );
					if ( null !== $avg_for_val && $avg_for_val > 0 ) {
						$cost_line = $qty * $avg_for_val;
					}
				}
				$inventory_cost_value += $cost_line;

				$avg = WC_Inventory_Overview_Costing::get_average_float( $p );
				if ( null !== $avg && $avg > 0 ) {
					$potential_gross_profit += ( $qty * $price ) - ( $qty * $avg );
				}

				if ( $price > 0.0000001 && ( null === $avg || $avg <= 0 ) ) {
					++$missing_cost_lines;
				}
			}

			if ( $page >= ( $r['max_num_pages'] ?? 1 ) ) {
				break;
			}
			++$page;
		}

		return array(
			'inventory_cost_value'   => (float) wc_format_decimal( (string) $inventory_cost_value, 4 ),
			'potential_revenue'      => (float) wc_format_decimal( (string) $potential_revenue, 4 ),
			'potential_gross_profit' => (float) wc_format_decimal( (string) $potential_gross_profit, 4 ),
			'missing_cost_lines'     => $missing_cost_lines,
		);
	}
}
