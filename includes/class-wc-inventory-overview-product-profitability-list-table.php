<?php
/**
 * Admin list: product profitability (aggregated order line snapshots).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for Product Profitability report.
 */
class WC_Inventory_Overview_Product_Profitability_List_Table extends WP_List_Table {

	use WC_Inventory_Overview_Hub_List_Table_Column_Info;

	/**
	 * @var array{date_from:string,date_to:string}
	 */
	protected $filters = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wc-io-product-profit',
				'plural'   => 'wc-io-product-profits',
				'ajax'     => false,
				'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG,
			)
		);
	}

	/**
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 */
	public function set_filters( array $filters ) {
		$this->filters = $filters;
	}

	public function get_columns() {
		return array(
			'product'        => __( 'Product / variation', 'wc-inventory-overview' ),
			'units_sold'     => __( 'Units sold', 'wc-inventory-overview' ),
			'revenue'        => __( 'Revenue', 'wc-inventory-overview' ),
			'line_discount'  => __( 'Discount', 'wc-inventory-overview' ),
			'product_cost'   => __( 'Product cost', 'wc-inventory-overview' ),
			'gross_profit'   => __( 'Gross profit', 'wc-inventory-overview' ),
			'margin_percent' => __( 'Margin %', 'wc-inventory-overview' ),
			'avg_sale_price' => __( 'Average sale price', 'wc-inventory-overview' ),
			'avg_cost'       => __( 'Average cost', 'wc-inventory-overview' ),
		);
	}

	public function no_items() {
		esc_html_e( 'No snapshot line items found for orders matching the snapshot status setting in this date range.', 'wc-inventory-overview' );
	}

	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'wc-io-product-profitability-table';
		return $classes;
	}

	/**
	 * @param object $row DB row.
	 * @return array{units:float,revenue:float,discount:float,cost:float,gross:float,margin:?float,avg_sale:?float,avg_cost:?float}
	 */
	public static function derive_metrics( $row ) {
		$units    = isset( $row->units_sold ) ? (float) $row->units_sold : 0.0;
		$revenue  = isset( $row->revenue ) ? (float) $row->revenue : 0.0;
		$discount = isset( $row->line_discount ) ? (float) $row->line_discount : 0.0;
		$cost     = isset( $row->product_cost ) ? (float) $row->product_cost : 0.0;
		$gross    = $revenue - $cost;

		$margin = null;
		if ( $revenue > 0.0000001 ) {
			$margin = ( $gross / $revenue ) * 100.0;
		}

		$avg_sale = null;
		$avg_c    = null;
		if ( $units > 0.0000001 ) {
			$avg_sale = $revenue / $units;
			$avg_c    = $cost / $units;
		}

		return array(
			'units'    => $units,
			'revenue'  => $revenue,
			'discount' => $discount,
			'cost'     => $cost,
			'gross'    => $gross,
			'margin'   => $margin,
			'avg_sale' => $avg_sale,
			'avg_cost' => $avg_c,
		);
	}

	public function prepare_items() {
		$filters = $this->filters;

		$per_page = $this->get_items_per_page( 'wc_io_product_profitability_per_page', 20 );
		$per_page = max( 1, min( 500, (int) $per_page ) );

		$total = WC_Inventory_Overview_Product_Profitability_Query::count_products( $filters );

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = isset( $_REQUEST['paged'] ) ? absint( wp_unslash( $_REQUEST['paged'] ) ) : 1;
		$paged       = max( 1, min( $paged, $total_pages ) );
		$offset      = ( $paged - 1 ) * $per_page;

		$this->items = WC_Inventory_Overview_Product_Profitability_Query::get_rows( $filters, $per_page, $offset );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
				'current'     => $paged,
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$f = $this->filters;

		$export_query = array(
			'page'               => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'                => WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY,
			'wc_io_pp_export'    => 'csv',
			'wc_io_pp_date_from' => $f['date_from'],
			'wc_io_pp_date_to'   => $f['date_to'],
		);
		$export_url = wp_nonce_url(
			add_query_arg( $export_query, admin_url( 'admin.php' ) ),
			'wc_io_product_profitability_export_csv',
			'_wc_io_pp_export_nonce',
			false
		);
		echo '<div class="alignleft actions wc-io-pp-export-wrap">';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download CSV (all matches)', 'wc-inventory-overview' ) . '</a>';
		echo '</div>';
	}

	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );
		return '—';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_product( $item ) {
		$pid = isset( $item->agg_product_id ) ? (int) $item->agg_product_id : 0;
		if ( $pid <= 0 ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$product = wc_get_product( $pid );
		if ( ! $product ) {
			return esc_html(
				sprintf(
					/* translators: %d: product or variation ID */
					__( 'Deleted product (ID %d)', 'wc-inventory-overview' ),
					$pid
				)
			);
		}

		$name = $product->get_name();
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id ) {
				$parent = get_the_title( $parent_id );
				$attrs  = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
				$name   = $parent !== '' ? $parent . ' — ' . $name : $name;
				if ( $attrs ) {
					$name .= ' (' . $attrs . ')';
				}
			}
		}

		return esc_html( $name );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_units_sold( $item ) {
		$m = self::derive_metrics( $item );
		return esc_html( wc_format_localized_decimal( (string) $m['units'] ) );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_revenue( $item ) {
		return $this->format_store_money( self::derive_metrics( $item )['revenue'] );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_line_discount( $item ) {
		return $this->format_store_money( self::derive_metrics( $item )['discount'] );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_product_cost( $item ) {
		return $this->format_store_money( self::derive_metrics( $item )['cost'] );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_gross_profit( $item ) {
		$m = self::derive_metrics( $item );
		$s = $this->format_store_money( $m['gross'] );
		if ( $m['gross'] < 0 && WC_Inventory_Overview_Settings::highlight_negative_profit() ) {
			return '<span class="wc-io-negative">' . $s . '</span>';
		}
		return $s;
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_margin_percent( $item ) {
		$m = self::derive_metrics( $item );
		if ( null === $m['margin'] ) {
			return '<span class="wc-io-muted">—</span>';
		}
		return esc_html( number_format_i18n( $m['margin'], 2 ) ) . '%';
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_avg_sale_price( $item ) {
		$m = self::derive_metrics( $item );
		if ( null === $m['avg_sale'] ) {
			return '<span class="wc-io-muted">—</span>';
		}
		return $this->format_store_money( $m['avg_sale'] );
	}

	/**
	 * @param object $item Row.
	 */
	protected function column_avg_cost( $item ) {
		$m = self::derive_metrics( $item );
		if ( null === $m['avg_cost'] ) {
			return '<span class="wc-io-muted">—</span>';
		}
		return $this->format_store_money( $m['avg_cost'] );
	}

	/**
	 * Totals are summed in the store default currency (single-currency shops).
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	protected function format_store_money( $amount ) {
		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => get_woocommerce_currency() ) ) );
	}

	/**
	 * @param resource                                   $out     Stream.
	 * @param array{date_from:string,date_to:string} $filters Filters.
	 */
	public static function export_csv_to_stream( $out, array $filters ) {
		fputcsv(
			$out,
			array(
				__( 'Product / variation', 'wc-inventory-overview' ),
				__( 'Units sold', 'wc-inventory-overview' ),
				__( 'Revenue', 'wc-inventory-overview' ),
				__( 'Discount', 'wc-inventory-overview' ),
				__( 'Product cost', 'wc-inventory-overview' ),
				__( 'Gross profit', 'wc-inventory-overview' ),
				__( 'Margin %', 'wc-inventory-overview' ),
				__( 'Average sale price', 'wc-inventory-overview' ),
				__( 'Average cost', 'wc-inventory-overview' ),
			)
		);

		foreach ( WC_Inventory_Overview_Product_Profitability_Query::iterate_rows( $filters ) as $row ) {
			$pid = isset( $row->agg_product_id ) ? (int) $row->agg_product_id : 0;
			$m   = self::derive_metrics( $row );

			$label = '';
			if ( $pid > 0 ) {
				$product = wc_get_product( $pid );
				if ( $product ) {
					$label = $product->get_name();
					if ( $product->is_type( 'variation' ) ) {
						$parent_id = (int) $product->get_parent_id();
						if ( $parent_id ) {
							$parent = get_the_title( $parent_id );
							$attrs  = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
							$label  = $parent !== '' ? $parent . ' — ' . $label : $label;
							if ( $attrs ) {
								$label .= ' (' . $attrs . ')';
							}
						}
					}
				} else {
					$label = sprintf( 'ID %d (deleted)', $pid );
				}
			}

			$margin = null === $m['margin'] ? '' : (string) round( $m['margin'], 4 );
			$avg_s  = null === $m['avg_sale'] ? '' : wc_format_decimal( $m['avg_sale'], 6 );
			$avg_c  = null === $m['avg_cost'] ? '' : wc_format_decimal( $m['avg_cost'], 6 );

			fputcsv(
				$out,
				array(
					$label,
					wc_format_decimal( $m['units'], 6 ),
					wc_format_decimal( $m['revenue'], 6 ),
					wc_format_decimal( $m['discount'], 6 ),
					wc_format_decimal( $m['cost'], 6 ),
					wc_format_decimal( $m['gross'], 6 ),
					$margin,
					$avg_s,
					$avg_c,
				)
			);
		}
	}
}
