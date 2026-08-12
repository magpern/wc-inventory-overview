<?php
/**
 * Reporting admin controller — extracted from main Plugin class in M19.
 *
 * Handles: Movements, Order Profit, and Product Profitability tabs
 * (screen bootstrap, rendering, CSV export). Read-only, zero mutation.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reporting tabs controller (Movements, Order Profit, Product Profitability).
 */
class WC_Inventory_Overview_Reporting_Controller {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_reporting_assets' ) );
	}

	/**
	 * Movements tab: screen options + CSV export.
	 */
	public function on_load_movements() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Movement rows per page', 'wc-inventory-overview' ),
				'default' => 20,
				'option'  => 'wc_io_movements_per_page',
			)
		);

		$this->export_movements_csv();
	}

	/**
	 * Order Profit tab: screen options + CSV export.
	 */
	public function on_load_order_profit() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Order profit rows per page', 'wc-inventory-overview' ),
				'default' => 20,
				'option'  => 'wc_io_order_profit_per_page',
			)
		);

		$this->export_order_profit_csv();
	}

	/**
	 * Product Profitability tab: screen options + CSV export.
	 */
	public function on_load_product_profitability() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Product profitability rows per page', 'wc-inventory-overview' ),
				'default' => 20,
				'option'  => 'wc_io_product_profitability_per_page',
			)
		);

		$this->export_product_profitability_csv();
	}

	/**
	 * Inventory movement log (custom table).
	 */
	public function render_movements() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Inventory Movements', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Stock quantity and average cost changes recorded by the plugin.', 'wc-inventory-overview' ) . '</p>';

		echo '<form id="wc-io-movements-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( WC_Inventory_Overview_Plugin::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Plugin::TAB_MOVEMENTS ) . '" />';
		$mv_req = WC_Inventory_Overview_Movements_List_Table::get_request_args();
		echo '<input type="hidden" name="orderby" value="' . esc_attr( $mv_req['orderby'] ) . '" />';
		echo '<input type="hidden" name="order" value="' . esc_attr( $mv_req['order'] ) . '" />';

		echo '<div class="wc-io-movements-scroll">';
		$list_table = new WC_Inventory_Overview_Movements_List_Table();
		$list_table->prepare_items();
		$list_table->display();
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Order profit report (line-item snapshots + shipping; does not restate order totals).
	 */
	public function render_order_profit() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Order Profit', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Product revenue sums WooCommerce line totals after discount from each snapshotted line (stored as _wc_io_line_total_snapshot when available; older rows use unit snapshot × quantity). Product cost uses snapshot average unit cost. The Discount column sums per-line _wc_io_discount_snapshot (empty for legacy snapshots). Shipping paid follows the "include shipping tax" setting. Actual shipping cost uses the order meta when set; otherwise the default from Settings. Gross profit = product revenue + shipping paid - product cost - actual shipping cost. Margin % is gross profit divided by (product revenue + shipping paid) when that sum is positive.', 'wc-inventory-overview' ) . '</p>';

		$filters      = WC_Inventory_Overview_Order_Profit_Query::get_filters_from_request();
		$all_st       = array_keys( wc_get_order_statuses() );
		$status_value = count( $filters['statuses'] ) === count( $all_st ) ? 'all' : (string) $filters['statuses'][0];

		echo '<form id="wc-io-order-profit-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( WC_Inventory_Overview_Plugin::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT ) . '" />';

		echo '<div class="wc-io-op-filters" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">';
		echo '<p class="search-box">';
		echo '<label for="wc-io-op-date-from">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" id="wc-io-op-date-from" name="wc_io_op_date_from" value="' . esc_attr( $filters['date_from'] ) . '" />';
		echo '</p>';
		echo '<p class="search-box">';
		echo '<label for="wc-io-op-date-to">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" id="wc-io-op-date-to" name="wc_io_op_date_to" value="' . esc_attr( $filters['date_to'] ) . '" />';
		echo '</p>';
		echo '<p class="search-box">';
		echo '<label for="wc-io-op-status">' . esc_html__( 'Order status', 'wc-inventory-overview' ) . '</label><br />';
		echo '<select id="wc-io-op-status" name="wc_io_op_status">';
		echo '<option value="all"' . selected( 'all', $status_value, false ) . '>' . esc_html__( 'All statuses', 'wc-inventory-overview' ) . '</option>';
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			printf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $slug ),
				esc_html( $label ),
				selected( $slug, $status_value, false )
			);
		}
		echo '</select></p>';
		echo '<p class="search-box">';
		submit_button( __( 'Filter', 'wc-inventory-overview' ), 'secondary', 'filter_action', false );
		echo '</p>';
		echo '</div>';

		echo '<div class="wc-io-order-profit-scroll">';
		$list_table = new WC_Inventory_Overview_Order_Profit_List_Table();
		$list_table->set_filters( $filters );
		$list_table->prepare_items();
		$list_table->display();
		echo '</div>';

		echo '</form>';
	}

	/**
	 * Product profitability from snapshotted lines on processing/completed orders.
	 */
	public function render_product_profitability() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Product Profitability', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Aggregates line items that have price/cost snapshots on orders whose status matches the snapshot setting under Settings. Revenue uses discounted line totals from snapshots when present, otherwise unit snapshot × quantity. Average sale price is discounted revenue divided by units sold. The Discount column sums snapshot line discounts. Date filter uses order date (default range under Settings applies when you open this tab without date parameters). Money columns use the store default currency.', 'wc-inventory-overview' ) . '</p>';

		$filters = WC_Inventory_Overview_Product_Profitability_Query::get_filters_from_request();

		echo '<form id="wc-io-product-profitability-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( WC_Inventory_Overview_Plugin::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY ) . '" />';

		echo '<div class="wc-io-pp-filters" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">';
		echo '<p class="search-box">';
		echo '<label for="wc-io-pp-date-from">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" id="wc-io-pp-date-from" name="wc_io_pp_date_from" value="' . esc_attr( $filters['date_from'] ) . '" />';
		echo '</p>';
		echo '<p class="search-box">';
		echo '<label for="wc-io-pp-date-to">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" id="wc-io-pp-date-to" name="wc_io_pp_date_to" value="' . esc_attr( $filters['date_to'] ) . '" />';
		echo '</p>';
		echo '<p class="search-box">';
		submit_button( __( 'Filter', 'wc-inventory-overview' ), 'secondary', 'filter_action', false );
		echo '</p>';
		echo '</div>';

		echo '<div class="wc-io-product-profitability-scroll">';
		$list_table = new WC_Inventory_Overview_Product_Profitability_List_Table();
		$list_table->set_filters( $filters );
		$list_table->prepare_items();
		$list_table->display();
		echo '</div>';

		echo '</form>';
	}

	/**
	 * CSV export for movement log (current filters and sort).
	 */
	public function export_movements_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export inventory movements.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_mv_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_mv_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || WC_Inventory_Overview_Plugin::TAB_MOVEMENTS !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		check_admin_referer( 'wc_io_movements_export_csv', '_wc_io_mv_export_nonce' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wc-inventory-movements-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		WC_Inventory_Overview_Movements_List_Table::export_csv_to_stream( $out );

		fclose( $out );
		exit;
	}

	/**
	 * CSV export for Order Profit (current filters).
	 */
	public function export_order_profit_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export order profit data.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_op_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_op_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || WC_Inventory_Overview_Plugin::TAB_ORDER_PROFIT !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		check_admin_referer( 'wc_io_order_profit_export_csv', '_wc_io_op_export_nonce' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wc-order-profit-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$filters = WC_Inventory_Overview_Order_Profit_Query::get_filters_from_request();
		WC_Inventory_Overview_Order_Profit_List_Table::export_csv_to_stream( $out, $filters );

		fclose( $out );
		exit;
	}

	/**
	 * CSV export for Product Profitability (current filters).
	 */
	public function export_product_profitability_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export product profitability data.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_pp_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_pp_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || WC_Inventory_Overview_Plugin::TAB_PRODUCT_PROFITABILITY !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		check_admin_referer( 'wc_io_product_profitability_export_csv', '_wc_io_pp_export_nonce' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wc-product-profitability-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		$filters = WC_Inventory_Overview_Product_Profitability_Query::get_filters_from_request();
		WC_Inventory_Overview_Product_Profitability_List_Table::export_csv_to_stream( $out, $filters );

		fclose( $out );
		exit;
	}

	/**
	 * Assets for the Movements tab (shared admin stylesheet + movements table script).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_reporting_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$tab = WC_Inventory_Overview_Plugin::instance()->get_requested_tab();
		if ( WC_Inventory_Overview_Plugin::TAB_MOVEMENTS !== $tab ) {
			return;
		}

		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);

		wp_enqueue_script(
			'wc-io-movements-table',
			plugins_url( 'assets/movements-table.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
	}
}

add_filter(
	'set_screen_option_wc_io_movements_per_page',
	static function ( $screen_option, $option, $value ) {
		if ( 'wc_io_movements_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $screen_option;
	},
	10,
	3
);

add_filter(
	'set_screen_option_wc_io_order_profit_per_page',
	static function ( $screen_option, $option, $value ) {
		if ( 'wc_io_order_profit_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $screen_option;
	},
	10,
	3
);

add_filter(
	'set_screen_option_wc_io_product_profitability_per_page',
	static function ( $screen_option, $option, $value ) {
		if ( 'wc_io_product_profitability_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $screen_option;
	},
	10,
	3
);
