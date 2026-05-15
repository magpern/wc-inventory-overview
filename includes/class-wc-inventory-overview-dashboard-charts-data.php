<?php
/**
 * Dashboard Chart.js datasets (no fabricated values).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds payloads for {@see assets/dashboard-charts.js}.
 */
class WC_Inventory_Overview_Dashboard_Charts_Data {

	/**
	 * @param array{totals: array<string, mixed>, daily_buckets: array<string, array{r: float, g: float>>} $rollup        From {@see WC_Inventory_Overview_Order_Profit_Query::get_dashboard_order_rollup()}.
	 * @param array{date_from:string,date_to:string,statuses:string[]}                                   $profit_filters Order-profit filters.
	 * @param array{date_from:string,date_to:string}                                                     $pp_filters     Product profitability date filters.
	 * @param array<string, mixed>                                                                       $summary_base   Base params for low-stock chart.
	 * @return array<string, mixed>
	 */
	public static function build_admin_script_payload( array $rollup, array $profit_filters, array $pp_filters, array $summary_base ) {
		$series = WC_Inventory_Overview_Order_Profit_Query::format_dashboard_revenue_profit_series(
			$profit_filters,
			$rollup['daily_buckets']
		);

		$profit_product = self::get_profit_by_product_top10( $pp_filters );
		$profit_cat     = self::get_profit_by_category( $pp_filters );
		$low_lines      = WC_Inventory_Overview_Summary::get_low_stock_lines_for_chart( $summary_base, 10 );

		$low_labels = array();
		$low_vals   = array();
		foreach ( $low_lines as $row ) {
			$low_labels[] = $row['name'];
			$low_vals[]   = $row['qty'];
		}

		return array(
			'currencyCode'   => get_woocommerce_currency(),
			'currencySymbol' => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'decimals'       => (int) wc_get_price_decimals(),
			'strings'        => array(
				'revenue'      => __( 'Revenue', 'wc-inventory-overview' ),
				'grossProfit'  => __( 'Gross profit', 'wc-inventory-overview' ),
				'qty'          => __( 'Stock qty', 'wc-inventory-overview' ),
				'profit'       => __( 'Profit', 'wc-inventory-overview' ),
				'monthNote'    => __( 'Month', 'wc-inventory-overview' ),
				'noData'       => __( 'No data for this view', 'wc-inventory-overview' ),
			),
			'revenueProfit'  => $series,
			'profitProduct'  => $profit_product,
			'inventoryTime'  => array(
				'available' => false,
				'message'   => __( 'The movement log records per-product inventory value after each change. It does not represent reliable store-wide inventory value over time, so this chart is disabled until a dedicated valuation history exists.', 'wc-inventory-overview' ),
			),
			'profitCategory' => $profit_cat,
			'lowStock'       => array(
				'available' => count( $low_vals ) > 0,
				'message'   => __( 'All caught up', 'wc-inventory-overview' ),
				'hint'      => __( 'When sellable products fall below their low-stock threshold, they appear in the chart and table here.', 'wc-inventory-overview' ),
				'detail'    => __( 'No low-stock sellable lines match the current catalog filters (same rules as the Inventory Overview summary).', 'wc-inventory-overview' ),
				'labels'    => $low_labels,
				'values'    => $low_vals,
			),
		);
	}

	/**
	 * Top 10 products/variations by snapshot line profit (revenue minus snapshot cost) in range.
	 *
	 * @param array{date_from:string,date_to:string} $filters Date filters.
	 * @return array{available:bool,message:string,labels:string[],values:float[]}
	 */
	public static function get_profit_by_product_top10( array $filters ) {
		$by_id = array();

		foreach ( WC_Inventory_Overview_Product_Profitability_Query::iterate_rows( $filters, 300 ) as $row ) {
			$id = isset( $row->agg_product_id ) ? (int) $row->agg_product_id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$rev  = isset( $row->revenue ) ? (float) wc_format_decimal( (string) $row->revenue, 8 ) : 0.0;
			$cost = isset( $row->product_cost ) ? (float) wc_format_decimal( (string) $row->product_cost, 8 ) : 0.0;
			if ( ! isset( $by_id[ $id ] ) ) {
				$by_id[ $id ] = 0.0;
			}
			$by_id[ $id ] += ( $rev - $cost );
		}

		if ( empty( $by_id ) ) {
			return array(
				'available' => false,
				'message'   => __( 'No sales data yet', 'wc-inventory-overview' ),
				'hint'      => __( 'Sales and profit charts will populate automatically after orders are completed.', 'wc-inventory-overview' ),
				'detail'    => __( 'No snapshot-based product lines in this date range.', 'wc-inventory-overview' ),
				'labels'    => array(),
				'values'    => array(),
			);
		}

		arsort( $by_id, SORT_NUMERIC );
		$top = array_slice( $by_id, 0, 10, true );

		$labels = array();
		$values = array();
		foreach ( $top as $pid => $profit ) {
			$p = wc_get_product( $pid );
			$labels[] = $p
				? wp_strip_all_tags( $p->get_formatted_name() )
				: (string) sprintf(
					/* translators: %d: product ID */
					__( 'Product #%d', 'wc-inventory-overview' ),
					$pid
				);
			$values[] = (float) wc_format_decimal( (string) $profit, 4 );
		}

		return array(
			'available' => true,
			'message'   => '',
			'labels'    => $labels,
			'values'    => $values,
		);
	}

	/**
	 * Product category term IDs used for reporting (variation inherits parent categories).
	 *
	 * @param int $product_id Variation or simple line ID from profitability aggregate.
	 * @return int[]
	 */
	protected static function product_category_term_ids_for_profit( $product_id ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return array();
		}
		$p = wc_get_product( $product_id );
		if ( ! $p instanceof WC_Product ) {
			return array();
		}

		$target_id = $p->is_type( 'variation' ) ? (int) $p->get_parent_id() : $product_id;
		if ( $target_id <= 0 ) {
			return array();
		}

		$terms = wc_get_product_term_ids( $target_id, 'product_cat' );
		return is_array( $terms ) ? array_values( array_filter( array_map( 'absint', $terms ) ) ) : array();
	}

	/**
	 * Snapshot profit split equally across assigned product categories (variation uses parent terms).
	 *
	 * @param array{date_from:string,date_to:string} $filters Date filters.
	 * @return array{available:bool,message:string,labels:string[],values:float[]}
	 */
	public static function get_profit_by_category( array $filters ) {
		$by_cat = array();

		foreach ( WC_Inventory_Overview_Product_Profitability_Query::iterate_rows( $filters, 300 ) as $row ) {
			$id = isset( $row->agg_product_id ) ? (int) $row->agg_product_id : 0;
			if ( $id <= 0 ) {
				continue;
			}
			$rev  = isset( $row->revenue ) ? (float) wc_format_decimal( (string) $row->revenue, 8 ) : 0.0;
			$cost = isset( $row->product_cost ) ? (float) wc_format_decimal( (string) $row->product_cost, 8 ) : 0.0;
			$profit = $rev - $cost;

			$term_ids = self::product_category_term_ids_for_profit( $id );
			$n        = count( $term_ids );
			if ( 0 === $n ) {
				$label = __( 'Uncategorized', 'wc-inventory-overview' );
				if ( ! isset( $by_cat[ $label ] ) ) {
					$by_cat[ $label ] = 0.0;
				}
				$by_cat[ $label ] += $profit;
				continue;
			}

			$share = $profit / $n;
			foreach ( $term_ids as $tid ) {
				$term = get_term( $tid, 'product_cat' );
				$label = ( $term && ! is_wp_error( $term ) )
					? (string) $term->name
					: (string) sprintf(
						/* translators: %d: term ID */
						__( 'Category #%d', 'wc-inventory-overview' ),
						$tid
					);
				if ( ! isset( $by_cat[ $label ] ) ) {
					$by_cat[ $label ] = 0.0;
				}
				$by_cat[ $label ] += $share;
			}
		}

		$sum = array_sum( $by_cat );
		if ( empty( $by_cat ) || abs( $sum ) < 0.0000001 ) {
			return array(
				'available' => false,
				'message'   => __( 'No sales data yet', 'wc-inventory-overview' ),
				'hint'      => __( 'Sales and profit charts will populate automatically after orders are completed.', 'wc-inventory-overview' ),
				'detail'    => __( 'No categorized snapshot profit in this date range.', 'wc-inventory-overview' ),
				'labels'    => array(),
				'values'    => array(),
			);
		}

		arsort( $by_cat, SORT_NUMERIC );

		$labels = array();
		$values  = array();

		if ( count( $by_cat ) <= 10 ) {
			foreach ( $by_cat as $label => $v ) {
				$labels[] = $label;
				$values[] = (float) wc_format_decimal( (string) $v, 4 );
			}
		} else {
			$i     = 0;
			$other = 0.0;
			foreach ( $by_cat as $label => $v ) {
				if ( $i < 9 ) {
					$labels[] = $label;
					$values[] = (float) wc_format_decimal( (string) $v, 4 );
				} else {
					$other += (float) $v;
				}
				++$i;
			}
			$labels[] = __( 'Other', 'wc-inventory-overview' );
			$values[] = (float) wc_format_decimal( (string) $other, 4 );
		}

		return array(
			'available' => true,
			'message'   => '',
			'labels'    => $labels,
			'values'    => $values,
		);
	}
}
