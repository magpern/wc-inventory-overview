<?php
/**
 * Dashboard admin controller — extracted from main Plugin class in M18.
 *
 * Handles: dashboard rendering, date filters, operational panels.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard tab controller.
 */
class WC_Inventory_Overview_Dashboard_Controller {

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

	protected function get_dashboard_date_filters_from_request() {
		$date_from = isset( $_REQUEST['wc_io_dash_date_from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_dash_date_from'] ) ) : '';
		$date_to   = isset( $_REQUEST['wc_io_dash_date_to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wc_io_dash_date_to'] ) ) : '';

		if ( $date_from && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$date_from = '';
		}
		if ( $date_to && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$date_to = '';
		}

		$had_date_in_request = isset( $_REQUEST['wc_io_dash_date_from'] ) || isset( $_REQUEST['wc_io_dash_date_to'] );
		if ( ! $had_date_in_request && '' === $date_from && '' === $date_to ) {
			$defaults  = WC_Inventory_Overview_Settings::get_default_report_date_bounds_local();
			$date_from = $defaults['date_from'];
			$date_to   = $defaults['date_to'];
		}

		return array(
			'date_from' => $date_from,
			'date_to'   => $date_to,
		);
	}
	protected function render_dashboard_operational_panels( array $summary_base ) {
		$rows     = WC_Inventory_Overview_Summary::get_low_stock_lines_for_chart( $summary_base, 15 );
		$can_shop = current_user_can( 'manage_woocommerce' );

		echo '<div class="wc-io-dash-ops" role="region" aria-label="' . esc_attr__( 'Operational shortcuts', 'wc-inventory-overview' ) . '">';

		echo '<div id="wc-io-dash-panel-low-stock" class="postbox wc-io-dash-ops-panel">';
		echo '<div class="postbox-header wc-io-dash-ops__header"><h2 class="hndle"><span>' . esc_html__( 'Recent Low Stock Items', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside">';
		if ( empty( $rows ) ) {
			echo '<div class="wc-io-dash-empty-state wc-io-dash-empty-state--table">';
			echo '<span class="wc-io-dash-empty-state__icon dashicons dashicons-yes-alt" aria-hidden="true"></span>';
			echo '<p class="wc-io-dash-empty-state__title">' . esc_html__( 'No low-stock lines', 'wc-inventory-overview' ) . '</p>';
			echo '<p class="wc-io-dash-empty-state__text description">' . esc_html__( 'No sellable products are at or below their low-stock threshold with the current catalog filters (same rules as Inventory Overview).', 'wc-inventory-overview' ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="wc-io-dash-ops-table-wrap">';
			echo '<table class="widefat striped wc-io-dash-ops-table"><thead><tr>';
			echo '<th scope="col" class="column-product">' . esc_html__( 'Product / variation', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="column-status">' . esc_html__( 'Status', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="column-qty">' . esc_html__( 'Current stock', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="column-threshold">' . esc_html__( 'Threshold', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col" class="column-actions">' . esc_html__( 'Edit', 'wc-inventory-overview' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$pid  = isset( $row['id'] ) ? (int) $row['id'] : 0;
				$edit = $pid > 0 ? get_edit_post_link( $pid, 'raw' ) : '';
				$qty  = isset( $row['qty'] ) ? (float) $row['qty'] : 0.0;
				if ( $qty <= 0 ) {
					$badge_class = 'wc-io-dash-badge wc-io-dash-badge--critical';
					$badge_text  = __( 'Out', 'wc-inventory-overview' );
				} else {
					$badge_class = 'wc-io-dash-badge wc-io-dash-badge--warn';
					$badge_text  = __( 'Low', 'wc-inventory-overview' );
				}
				echo '<tr class="wc-io-dash-ops-tr">';
				echo '<td class="column-product">' . esc_html( isset( $row['name'] ) ? (string) $row['name'] : '' ) . '</td>';
				echo '<td class="column-status"><span class="' . esc_attr( $badge_class ) . '">' . esc_html( $badge_text ) . '</span></td>';
				echo '<td class="column-qty">' . esc_html( (string) wc_stock_amount( $qty ) ) . '</td>';
				echo '<td class="column-threshold">' . esc_html( (string) wc_stock_amount( isset( $row['low'] ) ? $row['low'] : 0 ) ) . '</td>';
				echo '<td class="column-actions">';
				if ( $edit && current_user_can( 'edit_product', $pid ) ) {
					printf(
						'<a href="%1$s" aria-label="%2$s">%3$s</a>',
						esc_url( $edit ),
						esc_attr(
							sprintf(
								/* translators: %s: product or variation name */
								__( 'Edit product: %s', 'wc-inventory-overview' ),
								isset( $row['name'] ) ? (string) $row['name'] : ''
							)
						),
						esc_html__( 'Edit', 'wc-inventory-overview' )
					);
				} else {
					echo '<span class="wc-io-dash-ops-na" aria-hidden="true">' . esc_html( '—' ) . '</span>';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}
		echo '</div></div>';

		echo '<div id="wc-io-dash-panel-quick-actions" class="postbox wc-io-dash-ops-panel">';
		echo '<div class="postbox-header wc-io-dash-ops__header"><h2 class="hndle"><span>' . esc_html__( 'Quick Actions', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside">';
		if ( ! $can_shop ) {
			echo '<div class="wc-io-dash-empty-state wc-io-dash-empty-state--compact">';
			echo '<span class="wc-io-dash-empty-state__icon dashicons dashicons-lock" aria-hidden="true"></span>';
			echo '<p class="wc-io-dash-empty-state__text description">' . esc_html__( 'Shop manager capabilities are required for restock, cost adjustment, and profit tools.', 'wc-inventory-overview' ) . '</p>';
			echo '</div>';
		} else {
			$links = array(
				array(
					'label'    => __( 'Restock / Add Inventory', 'wc-inventory-overview' ),
					'url'      => WC_Inventory_Overview_Plugin::instance()->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_QUICK ) ) . '#wc-io-restock-section',
					'dashicon' => 'dashicons-plus-alt',
				),
				array(
					'label'    => __( 'Adjust Product Cost', 'wc-inventory-overview' ),
					'url'      => WC_Inventory_Overview_Plugin::instance()->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_ADJUST ) ) . '#wc-io-cost-adjustment-section',
					'dashicon' => 'dashicons-admin-generic',
				),
				array(
					'label'    => __( 'View Order Profit', 'wc-inventory-overview' ),
					'url'      => WC_Inventory_Overview_Plugin::instance()->admin_url_tab( self::TAB_ORDER_PROFIT ),
					'dashicon' => 'dashicons-money-alt',
				),
				array(
					'label'    => __( 'View Product Profitability', 'wc-inventory-overview' ),
					'url'      => WC_Inventory_Overview_Plugin::instance()->admin_url_tab( self::TAB_PRODUCT_PROFITABILITY ),
					'dashicon' => 'dashicons-chart-bar',
				),
			);
			echo '<div class="wc-io-dash-quick-actions">';
			foreach ( $links as $link ) {
				$d = isset( $link['dashicon'] ) ? preg_replace( '/[^a-z0-9\-]/', '', (string) $link['dashicon'] ) : 'dashicons-arrow-right-alt';
				echo '<a class="wc-io-dash-action-card" href="' . esc_url( $link['url'] ) . '">';
				echo '<span class="wc-io-dash-action-card__icon dashicons ' . esc_attr( $d ) . '" aria-hidden="true"></span>';
				echo '<span class="wc-io-dash-action-card__label">' . esc_html( $link['label'] ) . '</span>';
				echo '</a>';
			}
			echo '</div>';
		}
		echo '</div></div>';

		echo '</div>';
	}
	public function render() {
		$dates = $this->get_dashboard_date_filters_from_request();

		$profit_filters = array(
			'date_from' => $dates['date_from'],
			'date_to'   => $dates['date_to'],
			'statuses'  => WC_Inventory_Overview_Settings::get_profitability_sql_order_statuses(),
		);

		$rollup = WC_Inventory_Overview_Order_Profit_Query::get_dashboard_order_rollup( $profit_filters );
		$totals = $rollup['totals'];

		$pp_filters = array(
			'date_from' => $dates['date_from'],
			'date_to'   => $dates['date_to'],
		);

		$summary_base = array(
			'search'          => '',
			'category_tt_id'  => 0,
			'stock_status'    => array(),
			'exclude_private' => WC_Inventory_Overview_Repository::request_excludes_private(),
		);

		$inv_metrics = WC_Inventory_Overview_Dashboard_Inventory_Metrics::compute_for_dashboard( $summary_base );
		$summary     = WC_Inventory_Overview_Summary::build( $summary_base );
		$low_stock   = isset( $summary['low_stock'] ) ? (int) $summary['low_stock'] : 0;

		$na          = "\u{2014}";
		$margin_html = null === $totals['margin_percent']
			? '<span class="wc-io-dash-kpi-na">' . esc_html( $na ) . '</span>'
			: '<span class="wc-io-dash-kpi-num">' . esc_html( number_format_i18n( $totals['margin_percent'], 2 ) ) . '<span class="wc-io-dash-kpi-suffix">%</span></span>';

		$order_count = (int) $totals['order_count'];
		$no_sales    = ( 0 === $order_count );

		$kpis = array(
			array(
				'key'      => 'revenue',
				'label'    => __( 'Revenue', 'wc-inventory-overview' ),
				'html'     => wp_kses_post( wc_price( $totals['product_revenue'] ) ),
				'dashicon' => 'dashicons-chart-line',
				'accent'   => 'revenue',
				'help'     => '',
				'note'     => $no_sales
					? __( 'No sales data yet for this range.', 'wc-inventory-overview' )
					: '',
			),
			array(
				'key'      => 'gross_profit',
				'label'    => __( 'Gross Profit', 'wc-inventory-overview' ),
				'html'     => wp_kses_post( wc_price( $totals['gross_profit'] ) ),
				'dashicon' => 'dashicons-chart-area',
				'accent'   => 'profit',
				'help'     => '',
				'note'     => $no_sales
					? __( 'No sales data yet for this range.', 'wc-inventory-overview' )
					: '',
			),
			array(
				'key'      => 'gross_margin',
				'label'    => __( 'Gross Margin', 'wc-inventory-overview' ),
				'html'     => $margin_html,
				'dashicon' => 'dashicons-chart-pie',
				'accent'   => 'profit',
				'help'     => '',
				'note'     => $no_sales
					? __( 'No sales data yet for this range.', 'wc-inventory-overview' )
					: '',
			),
			array(
				'key'      => 'orders',
				'label'    => __( 'Orders', 'wc-inventory-overview' ),
				'html'     => '<span class="wc-io-dash-kpi-num">' . esc_html( number_format_i18n( $order_count ) ) . '</span>',
				'dashicon' => 'dashicons-cart',
				'accent'   => 'orders',
				'help'     => '',
				'note'     => $no_sales
					? __( 'No matching orders in this range.', 'wc-inventory-overview' )
					: '',
			),
			array(
				'key'      => 'inventory_cost_value',
				'label'    => __( 'Inventory Cost Value', 'wc-inventory-overview' ),
				'html'     => wp_kses_post( wc_price( $inv_metrics['inventory_cost_value'] ) ),
				'dashicon' => 'dashicons-archive',
				'accent'   => 'inventory',
				'help'     => __( 'Estimated cost value of current stock based on weighted average sourcing cost.', 'wc-inventory-overview' ),
				'note'     => '',
			),
			array(
				'key'      => 'potential_revenue',
				'label'    => __( 'Potential Revenue', 'wc-inventory-overview' ),
				'html'     => wp_kses_post( wc_price( $inv_metrics['potential_revenue'] ) ),
				'dashicon' => 'dashicons-tag',
				'accent'   => 'potrev',
				'help'     => __( 'Theoretical revenue if current stock sold at current prices.', 'wc-inventory-overview' ),
				'note'     => '',
			),
			array(
				'key'      => 'potential_gross_profit',
				'label'    => __( 'Potential Gross Profit', 'wc-inventory-overview' ),
				'html'     => wp_kses_post( wc_price( $inv_metrics['potential_gross_profit'] ) ),
				'dashicon' => 'dashicons-calculator',
				'help'     => __( 'Theoretical gross profit for stock lines with known sourcing cost.', 'wc-inventory-overview' ),
				'note'     => '',
			),
			array(
				'key'      => 'missing_cost_lines',
				'label'    => __( 'Missing Cost Lines', 'wc-inventory-overview' ),
				'html'     => '<span class="wc-io-dash-kpi-num">' . esc_html( number_format_i18n( (int) $inv_metrics['missing_cost_lines'] ) ) . '</span>',
				'dashicon' => 'dashicons-warning',
				'accent'   => 'missing',
				'help'     => __( 'Inventory lines excluded from profit forecast because sourcing cost is missing.', 'wc-inventory-overview' ),
				'note'     => '',
			),
			array(
				'key'      => 'low_stock',
				'label'    => __( 'Low Stock Items', 'wc-inventory-overview' ),
				'html'     => '<span class="wc-io-dash-kpi-num">' . esc_html( number_format_i18n( $low_stock ) ) . '</span>',
				'dashicon' => 'dashicons-flag',
				'accent'   => $low_stock > 0 ? 'lowstock' : 'calm',
				'help'     => '',
				'note'     => $low_stock > 0
					? __( 'Review the table below.', 'wc-inventory-overview' )
					: __( 'Nothing flagged right now.', 'wc-inventory-overview' ),
			),
		);

		echo '<div class="wc-io-dash">';
		echo '<div class="wc-io-dash-header">';
		echo '<div class="wc-io-dash-header-text">';
		echo '<h2 class="wc-io-dash-title">' . esc_html__( 'Dashboard', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="wc-io-dash-subtitle description">' . esc_html__( 'Overview of store performance and inventory.', 'wc-inventory-overview' ) . '</p>';
		echo '</div>';
		echo '<div class="wc-io-dash-header-filters">';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wc-io-dash-date-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_DASHBOARD ) . '" />';
		echo '<label for="wc-io-dash-date-from"><span class="screen-reader-text">' . esc_html__( 'Date from', 'wc-inventory-overview' ) . '</span></label>';
		echo '<input type="date" id="wc-io-dash-date-from" name="wc_io_dash_date_from" value="' . esc_attr( $dates['date_from'] ) . '" class="wc-io-dash-date-input" />';
		echo ' <span class="wc-io-dash-date-sep" aria-hidden="true">–</span> ';
		echo '<label for="wc-io-dash-date-to"><span class="screen-reader-text">' . esc_html__( 'Date to', 'wc-inventory-overview' ) . '</span></label>';
		echo '<input type="date" id="wc-io-dash-date-to" name="wc_io_dash_date_to" value="' . esc_attr( $dates['date_to'] ) . '" class="wc-io-dash-date-input" /> ';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Apply', 'wc-inventory-overview' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';

		echo '<div class="wc-io-dash-kpis" role="list">';
		foreach ( $kpis as $kpi ) {
			$accent          = isset( $kpi['accent'] ) ? sanitize_key( (string) $kpi['accent'] ) : 'neutral';
			$dashicon        = isset( $kpi['dashicon'] ) ? preg_replace( '/[^a-z0-9\-]/', '', (string) $kpi['dashicon'] ) : 'dashicons-chart-line';
			$kpi_outer_class = 'postbox wc-io-dash-kpi wc-io-dash-kpi--accent-' . $accent;
			if ( 'low_stock' === $kpi['key'] && $low_stock > 0 ) {
				$kpi_outer_class .= ' wc-io-dash-kpi--needs-attention';
			}
			if ( 'missing_cost_lines' === $kpi['key'] && (int) $inv_metrics['missing_cost_lines'] > 0 ) {
				$kpi_outer_class .= ' wc-io-dash-kpi--missing-cost-attention';
			}
			echo '<div class="' . esc_attr( $kpi_outer_class ) . '" role="listitem">';
			echo '<div class="wc-io-dash-kpi-inner">';
			echo '<div class="wc-io-dash-kpi-icon-wrap" aria-hidden="true"><span class="wc-io-dash-kpi-icon dashicons ' . esc_attr( $dashicon ) . '"></span></div>';
			echo '<div class="wc-io-dash-kpi-body">';
			echo '<div class="wc-io-dash-kpi-label-row">';
			echo '<span class="wc-io-dash-kpi-label">' . esc_html( $kpi['label'] ) . '</span>';
			if ( ! empty( $kpi['help'] ) ) {
				echo '<span class="wc-io-dash-kpi-help dashicons dashicons-editor-help" title="' . esc_attr( (string) $kpi['help'] ) . '" tabindex="0" role="img" aria-label="' . esc_attr( (string) $kpi['help'] ) . '"></span>';
			}
			echo '</div>';
			echo '<span class="wc-io-dash-kpi-value" data-wc-io-kpi="' . esc_attr( $kpi['key'] ) . '">' . $kpi['html'] . '</span>';
			if ( ! empty( $kpi['note'] ) ) {
				echo '<p class="wc-io-dash-kpi-note">' . esc_html( (string) $kpi['note'] ) . '</p>';
			}
			echo '</div></div></div>';
		}
		echo '</div>';

		$this->render_dashboard_operational_panels( $summary_base );

		echo '<div class="wc-io-dash-charts">';

		// 1. Revenue vs gross profit (Chart.js).
		echo '<div id="wc-io-dash-chart-revenue-profit" class="postbox wc-io-dash-chart">';
		echo '<div class="postbox-header wc-io-dash-chart__header"><h2 class="hndle"><span>' . esc_html__( 'Revenue vs Gross Profit', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside"><div class="wc-io-dash-chart-canvas" role="region" aria-label="' . esc_attr__( 'Revenue vs Gross Profit', 'wc-inventory-overview' ) . '">';
		echo '<div id="wc-io-dash-mount-revenue-profit" class="wc-io-dash-chart-mount"></div>';
		echo '</div></div></div>';

		// 2. Profit by product (top 10, horizontal bars).
		echo '<div id="wc-io-dash-chart-profit-product" class="postbox wc-io-dash-chart">';
		echo '<div class="postbox-header wc-io-dash-chart__header"><h2 class="hndle"><span>' . esc_html__( 'Profit by Product (top 10)', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside"><div class="wc-io-dash-chart-canvas" role="region" aria-label="' . esc_attr__( 'Profit by Product', 'wc-inventory-overview' ) . '">';
		echo '<div id="wc-io-dash-mount-profit-product" class="wc-io-dash-chart-mount"></div>';
		echo '</div></div></div>';

		// 3. Inventory value over time — placeholder until store-wide history exists.
		echo '<div id="wc-io-dash-chart-inv-time" class="postbox wc-io-dash-chart">';
		echo '<div class="postbox-header wc-io-dash-chart__header"><h2 class="hndle"><span>' . esc_html__( 'Inventory Value Over Time', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside"><div class="wc-io-dash-chart-canvas wc-io-dash-chart-canvas--static" role="region" aria-label="' . esc_attr__( 'Inventory Value Over Time', 'wc-inventory-overview' ) . '">';
		echo '<div class="wc-io-dash-chart-empty wc-io-dash-chart-empty--muted">';
		echo '<span class="wc-io-dash-chart-empty__icon dashicons dashicons-info" aria-hidden="true"></span>';
		echo '<p class="wc-io-dash-chart-empty__title">' . esc_html__( 'Not available yet', 'wc-inventory-overview' ) . '</p>';
		echo '<p class="wc-io-dash-chart-empty__hint">' . esc_html__( 'The movement log records per-product inventory value after each change. It does not represent reliable store-wide inventory value over time, so this chart is disabled until a dedicated valuation history exists.', 'wc-inventory-overview' ) . '</p>';
		echo '</div>';
		echo '</div></div></div>';

		// 4. Profit by category (doughnut when data exists).
		echo '<div id="wc-io-dash-chart-profit-category" class="postbox wc-io-dash-chart">';
		echo '<div class="postbox-header wc-io-dash-chart__header"><h2 class="hndle"><span>' . esc_html__( 'Profit by Category', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside"><div class="wc-io-dash-chart-canvas" role="region" aria-label="' . esc_attr__( 'Profit by Category', 'wc-inventory-overview' ) . '">';
		echo '<div id="wc-io-dash-mount-profit-category" class="wc-io-dash-chart-mount"></div>';
		echo '</div></div></div>';

		// 5. Low stock (real qty from overview rules).
		echo '<div id="wc-io-dash-chart-low-stock" class="postbox wc-io-dash-chart">';
		echo '<div class="postbox-header wc-io-dash-chart__header"><h2 class="hndle"><span>' . esc_html__( 'Low stock quantities', 'wc-inventory-overview' ) . '</span></h2></div>';
		echo '<div class="inside"><div class="wc-io-dash-chart-canvas" role="region" aria-label="' . esc_attr__( 'Low stock quantities', 'wc-inventory-overview' ) . '">';
		echo '<div id="wc-io-dash-mount-low-stock" class="wc-io-dash-chart-mount"></div>';
		echo '</div></div></div>';

		echo '</div>';

		if ( wp_script_is( 'wc-io-dashboard-charts', 'enqueued' ) ) {
			$payload = WC_Inventory_Overview_Dashboard_Charts_Data::build_admin_script_payload(
				$rollup,
				$profit_filters,
				$pp_filters,
				$summary_base
			);
			wp_add_inline_script(
				'wc-io-dashboard-charts',
				'var wcIoDashboardCharts = ' . wp_json_encode(
					$payload,
					JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
				) . ';',
				'before'
			);
		}

		echo '</div>';
	}
}
