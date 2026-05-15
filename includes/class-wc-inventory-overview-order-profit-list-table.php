<?php
/**
 * Admin list: order profit (snapshot-backed line metrics + shipping).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for Order Profit report.
 */
class WC_Inventory_Overview_Order_Profit_List_Table extends WP_List_Table {

	use WC_Inventory_Overview_Hub_List_Table_Column_Info;

	/**
	 * @var array{date_from:string,date_to:string,statuses:string[]}
	 */
	protected $filters = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wc-io-order-profit',
				'plural'   => 'wc-io-order-profits',
				'ajax'     => false,
				'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG,
			)
		);
	}

	/**
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 */
	public function set_filters( array $filters ) {
		$this->filters = $filters;
	}

	public function get_columns() {
		return array(
			'order'                  => __( 'Order', 'wc-inventory-overview' ),
			'date'                   => __( 'Date', 'wc-inventory-overview' ),
			'status'                 => __( 'Status', 'wc-inventory-overview' ),
			'product_revenue'        => __( 'Product revenue', 'wc-inventory-overview' ),
			'line_discount'          => __( 'Discount', 'wc-inventory-overview' ),
			'shipping_paid'          => __( 'Shipping paid', 'wc-inventory-overview' ),
			'product_cost'           => __( 'Product cost', 'wc-inventory-overview' ),
			'actual_shipping_cost'   => __( 'Actual shipping cost', 'wc-inventory-overview' ),
			'gross_profit'           => __( 'Gross profit', 'wc-inventory-overview' ),
			'margin_percent'         => __( 'Margin %', 'wc-inventory-overview' ),
		);
	}

	public function no_items() {
		esc_html_e( 'No orders found for these filters. Orders must have at least one line item with cost/price snapshots (recorded when the order reaches a status allowed under Settings).', 'wc-inventory-overview' );
	}

	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'wc-io-order-profit-table';
		return $classes;
	}

	public function prepare_items() {
		$filters = $this->filters;

		$per_page = $this->get_items_per_page( 'wc_io_order_profit_per_page', 20 );
		$per_page = max( 1, min( 500, (int) $per_page ) );

		$total = WC_Inventory_Overview_Order_Profit_Query::count_orders( $filters );

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = isset( $_REQUEST['paged'] ) ? absint( wp_unslash( $_REQUEST['paged'] ) ) : 1;
		$paged       = max( 1, min( $paged, $total_pages ) );
		$offset      = ( $paged - 1 ) * $per_page;

		$ids = WC_Inventory_Overview_Order_Profit_Query::get_order_ids( $filters, $per_page, $offset );

		$this->items = array();
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$this->items[] = array(
				'order'   => $order,
				'metrics' => WC_Inventory_Overview_Order_Profit_Query::get_metrics_for_order( $order ),
			);
		}

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
			'tab'                => WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT,
			'wc_io_op_export'    => 'csv',
			'wc_io_op_date_from' => $f['date_from'],
			'wc_io_op_date_to'   => $f['date_to'],
			'wc_io_op_status'    => self::status_query_arg_for_export( $f ),
		);
		$export_url = wp_nonce_url(
			add_query_arg( $export_query, admin_url( 'admin.php' ) ),
			'wc_io_order_profit_export_csv',
			'_wc_io_op_export_nonce',
			false
		);
		echo '<div class="alignleft actions wc-io-op-export-wrap">';
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download CSV (all matches)', 'wc-inventory-overview' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Single status query value for export links (mirrors filter form).
	 *
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 * @return string `all` or a WC order status key.
	 */
	protected static function status_query_arg_for_export( array $filters ) {
		$all = array_keys( wc_get_order_statuses() );
		if ( count( $filters['statuses'] ) === count( $all ) ) {
			return 'all';
		}
		return (string) $filters['statuses'][0];
	}

	protected function column_default( $item, $column_name ) {
		unset( $item, $column_name );
		return '—';
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_order( $item ) {
		$order = $item['order'];
		$url   = $order->get_edit_order_url();
		$label = '#' . $order->get_order_number();
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_date( $item ) {
		$order = $item['order'];
		$d     = $order->get_date_created();
		if ( ! $d ) {
			return '—';
		}
		return esc_html( wc_format_datetime( $d, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_status( $item ) {
		$order = $item['order'];
		return esc_html( wc_get_order_status_name( $order->get_status() ) );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_product_revenue( $item ) {
		return $this->format_money( $item['order'], $item['metrics']['product_revenue'] );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_line_discount( $item ) {
		return $this->format_money( $item['order'], $item['metrics']['line_discount'] );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_shipping_paid( $item ) {
		return $this->format_money( $item['order'], $item['metrics']['shipping_paid'] );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_product_cost( $item ) {
		return $this->format_money( $item['order'], $item['metrics']['product_cost'] );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_actual_shipping_cost( $item ) {
		return $this->format_money( $item['order'], $item['metrics']['actual_shipping_cost'] );
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_gross_profit( $item ) {
		$v = $item['metrics']['gross_profit'];
		$s = $this->format_money( $item['order'], $v );
		if ( $v < 0 && WC_Inventory_Overview_Settings::highlight_negative_profit() ) {
			return '<span class="wc-io-negative">' . $s . '</span>';
		}
		return $s;
	}

	/**
	 * @param array{order: WC_Order, metrics: array} $item Row.
	 */
	protected function column_margin_percent( $item ) {
		$m = $item['metrics']['margin_percent'];
		if ( null === $m ) {
			return '<span class="wc-io-muted">—</span>';
		}
		return esc_html( number_format_i18n( $m, 2 ) ) . '%';
	}

	/**
	 * @param WC_Order $order Order.
	 * @param float    $amount Amount in order currency.
	 * @return string
	 */
	protected function format_money( WC_Order $order, $amount ) {
		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => $order->get_currency() ) ) );
	}

	/**
	 * Stream CSV for all rows matching filters (no pagination cap).
	 *
	 * @param resource                                   $out     Output stream.
	 * @param array{date_from:string,date_to:string,statuses:string[]} $filters Filters.
	 */
	public static function export_csv_to_stream( $out, array $filters ) {
		fputcsv(
			$out,
			array(
				__( 'Order', 'wc-inventory-overview' ),
				__( 'Date', 'wc-inventory-overview' ),
				__( 'Status', 'wc-inventory-overview' ),
				__( 'Product revenue', 'wc-inventory-overview' ),
				__( 'Discount', 'wc-inventory-overview' ),
				__( 'Shipping paid', 'wc-inventory-overview' ),
				__( 'Product cost', 'wc-inventory-overview' ),
				__( 'Actual shipping cost', 'wc-inventory-overview' ),
				__( 'Gross profit', 'wc-inventory-overview' ),
				__( 'Margin %', 'wc-inventory-overview' ),
			)
		);

		foreach ( WC_Inventory_Overview_Order_Profit_Query::iterate_order_ids( $filters ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$m = WC_Inventory_Overview_Order_Profit_Query::get_metrics_for_order( $order );

			$d = $order->get_date_created();
			$date_str = $d ? $d->date_i18n( 'Y-m-d H:i:s' ) : '';

			$margin = null === $m['margin_percent'] ? '' : (string) round( $m['margin_percent'], 4 );

			fputcsv(
				$out,
				array(
					$order->get_order_number(),
					$date_str,
					wc_get_order_status_name( $order->get_status() ),
					wc_format_decimal( $m['product_revenue'], 6 ),
					wc_format_decimal( $m['line_discount'], 6 ),
					wc_format_decimal( $m['shipping_paid'], 6 ),
					wc_format_decimal( $m['product_cost'], 6 ),
					wc_format_decimal( $m['actual_shipping_cost'], 6 ),
					wc_format_decimal( $m['gross_profit'], 6 ),
					$margin,
				)
			);
		}
	}
}
