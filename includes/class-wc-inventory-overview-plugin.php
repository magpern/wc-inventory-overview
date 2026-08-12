<?php
/**
 * Bootstrap: admin menu, requests, assets.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin controller.
 */
class WC_Inventory_Overview_Plugin {

	public const PAGE_SLUG = 'wc-inventory-profit';

	public const TAB_DASHBOARD = 'dashboard';

	public const TAB_OVERVIEW = 'overview';

	public const TAB_RESTOCK = 'restock';

	public const RESTOCK_VIEW_QUICK = 'quick';

	public const RESTOCK_VIEW_ADJUST = 'adjust';

	public const TAB_MOVEMENTS = 'movements';

	public const TAB_ORDER_PROFIT = 'order_profit';

	public const TAB_PRODUCT_PROFITABILITY = 'product_profitability';

	public const TAB_SETTINGS = 'settings';

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
		WC_Inventory_Overview_Order_Item_Snapshots::register();
		WC_Inventory_Overview_Order_Shipping_Admin::register();
		WC_Inventory_Overview_Purchasing_Page::instance()->init();
		WC_Inventory_Overview_Settings_Controller::instance()->init();
		WC_Inventory_Overview_Expected_Delivery_Service::register();
		WC_Inventory_Overview_Expected_Delivery_Renderer::register();

		add_action( 'admin_init', array( $this, 'redirect_legacy_inventory_admin_pages' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'on_load_inventory_profit_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_restock_assets' ) );
		add_action( 'admin_post_wc_io_restock', array( $this, 'handle_restock_post' ) );
		add_action( 'admin_post_wc_io_cost_adjustment', array( $this, 'handle_cost_adjustment_post' ) );
		add_action( 'wp_ajax_wc_io_save_inline_stock', array( $this, 'ajax_save_inline_stock' ) );
		add_action( 'wp_ajax_wc_io_get_cost_adjustment_preview', array( $this, 'ajax_get_cost_adjustment_preview' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Inventory & Profit', 'wc-inventory-overview' ),
			__( 'Inventory & Profit', 'wc-inventory-overview' ),
			'edit_products',
			self::PAGE_SLUG,
			array( $this, 'render_inventory_profit_shell' )
		);
	}

	/**
	 * Preserve bookmarks to old submenu slugs.
	 */
	public function redirect_legacy_inventory_admin_pages() {
		if ( ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
			return;
		}
		$page = sanitize_key( wp_unslash( $_GET['page'] ) );
		if ( 'wc-inventory-overview' === $page ) {
			if ( ! current_user_can( 'edit_products' ) ) {
				return;
			}
			$this->redirect_to_inventory_profit_tab( self::TAB_OVERVIEW );
		}
		if ( 'wc-inventory-restock' === $page ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
			wp_safe_redirect( $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_QUICK ) ) );
			exit;
		}
	}

	/**
	 * @param string $tab Tab slug (self::TAB_*).
	 */
	protected function redirect_to_inventory_profit_tab( $tab ) {
		$query = array();
		foreach ( wp_unslash( $_GET ) as $k => $v ) {
			$key = sanitize_key( (string) $k );
			if ( '' === $key || 'page' === $key ) {
				continue;
			}
			if ( is_array( $v ) ) {
				continue;
			}
			$query[ $key ] = sanitize_text_field( (string) $v );
		}
		$query['page'] = self::PAGE_SLUG;
		$query['tab']  = sanitize_key( $tab );
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Tab definitions (order = nav order).
	 *
	 * @return array<string, array{label: string, cap: string}>
	 */
	protected function get_tabs_definition() {
		return array(
			self::TAB_DASHBOARD             => array(
				'label' => __( 'Dashboard', 'wc-inventory-overview' ),
				'cap'   => 'edit_products',
			),
			self::TAB_OVERVIEW              => array(
				'label' => __( 'Inventory Overview', 'wc-inventory-overview' ),
				'cap'   => 'edit_products',
			),
			self::TAB_RESTOCK               => array(
				'label' => __( 'Restock / Cost Adjustment', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_MOVEMENTS             => array(
				'label' => __( 'Inventory Movements', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_ORDER_PROFIT          => array(
				'label' => __( 'Order Profit', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_PRODUCT_PROFITABILITY => array(
				'label' => __( 'Product Profitability', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_SETTINGS              => array(
				'label' => __( 'Settings', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
		);
	}

	/**
	 * Current tab slug, validated for the active user.
	 *
	 * @return string
	 */
	public function get_requested_tab() {
		$raw  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$tabs = $this->get_tabs_definition();
		if ( ! isset( $tabs[ $raw ] ) ) {
			return self::TAB_OVERVIEW;
		}
		$cap = $tabs[ $raw ]['cap'];
		if ( $cap && ! current_user_can( $cap ) ) {
			return self::TAB_OVERVIEW;
		}
		return $raw;
	}

	/**
	 * Admin URL for a hub tab.
	 *
	 * @param string               $tab   Tab slug.
	 * @param array<string, mixed> $extra Query args to merge.
	 * @return string
	 */
	public function admin_url_tab( $tab, array $extra = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $tab,
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Restock tab internal sub-view (Quick Restock default — Batch Intake was
	 * retired in M6; requesting restock_view=batch now falls back here rather
	 * than erroring, so a stale bookmark from before v1.23.0 still resolves
	 * to a working screen).
	 *
	 * @return string quick|adjust
	 */
	protected function get_restock_subview() {
		if ( self::TAB_RESTOCK !== $this->get_requested_tab() ) {
			return self::RESTOCK_VIEW_QUICK;
		}
		$raw     = isset( $_GET['restock_view'] ) ? sanitize_key( wp_unslash( $_GET['restock_view'] ) ) : '';
		$allowed = array(
			self::RESTOCK_VIEW_QUICK,
			self::RESTOCK_VIEW_ADJUST,
		);
		if ( in_array( $raw, $allowed, true ) ) {
			return $raw;
		}
		return self::RESTOCK_VIEW_QUICK;
	}

	/**
	 * Secondary nav under Restock / Cost Adjustment. Batch Intake was removed
	 * from this nav in M6 — see the M6 implementation plan's Retirement
	 * strategy ("Removed (in M6)": the two admin entry points that reached it).
	 */
	protected function render_restock_subnav() {
		$cur   = $this->get_restock_subview();
		$items = array(
			self::RESTOCK_VIEW_QUICK  => __( 'Quick Restock', 'wc-inventory-overview' ),
			self::RESTOCK_VIEW_ADJUST => __( 'Cost Adjustment', 'wc-inventory-overview' ),
		);
		echo '<nav class="wc-io-restock-subnav" aria-label="' . esc_attr__( 'Restock views', 'wc-inventory-overview' ) . '">';
		$sep = '';
		foreach ( $items as $slug => $label ) {
			echo $sep;
			$url = esc_url( $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => $slug ) ) );
			if ( $slug === $cur ) {
				echo '<span class="wc-io-restock-subnav__current">' . esc_html( $label ) . '</span>';
			} else {
				echo '<a href="' . $url . '">' . esc_html( $label ) . '</a>';
			}
			$sep = ' <span class="wc-io-restock-subnav__sep">|</span> ';
		}
		echo '</nav>';
	}

	/**
	 * Unified WooCommerce admin hub: screen options, CSV export, bulk, restock bootstrap.
	 */
	public function on_load_inventory_profit_page() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		$tab = $this->get_requested_tab();
		if ( self::TAB_OVERVIEW === $tab ) {
			$this->on_load_screen();
			return;
		}
		if ( self::TAB_RESTOCK === $tab && current_user_can( 'manage_woocommerce' ) ) {
			$this->on_load_restock_screen();
		}
		if ( self::TAB_MOVEMENTS === $tab && current_user_can( 'manage_woocommerce' ) ) {
			$this->on_load_movements_screen();
		}
		if ( self::TAB_ORDER_PROFIT === $tab && current_user_can( 'manage_woocommerce' ) ) {
			$this->on_load_order_profit_screen();
		}
		if ( self::TAB_PRODUCT_PROFITABILITY === $tab && current_user_can( 'manage_woocommerce' ) ) {
			$this->on_load_product_profitability_screen();
		}
	}

	/**
	 * Movements tab: screen options + CSV export.
	 */
	public function on_load_movements_screen() {
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

		$this->maybe_export_movements_csv();
	}

	/**
	 * Order Profit tab: screen options + CSV export.
	 */
	public function on_load_order_profit_screen() {
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

		$this->maybe_export_order_profit_csv();
	}

	/**
	 * Product Profitability tab: screen options + CSV export.
	 */
	public function on_load_product_profitability_screen() {
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

		$this->maybe_export_product_profitability_csv();
	}

	/**
	 * Restock tab bootstrap (assets load in enqueue_restock_assets).
	 */
	public function on_load_restock_screen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
	}

	/**
	 * Tab navigation for the Inventory & Profit hub.
	 *
	 * @param string $current Tab slug.
	 */
	protected function render_inventory_profit_tabs( $current ) {
		$tabs = $this->get_tabs_definition();
		echo '<nav class="nav-tab-wrapper wp-clearfix wc-io-profit-nav" aria-label="' . esc_attr__( 'Inventory & Profit sections', 'wc-inventory-overview' ) . '">';
		foreach ( $tabs as $slug => $def ) {
			if ( ! current_user_can( $def['cap'] ) ) {
				continue;
			}
			$url   = esc_url( $this->admin_url_tab( $slug ) );
			$label = esc_html( $def['label'] );
			$cls   = 'nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' );
			echo '<a href="' . $url . '" class="' . esc_attr( $cls ) . '">' . $label . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Placeholder panel for tabs without implementation yet.
	 *
	 * @param string $title Section title.
	 */
	protected function render_tab_placeholder( $title ) {
		echo '<h2 class="wc-io-tab-panel-title">' . esc_html( $title ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This section is not implemented yet.', 'wc-inventory-overview' ) . '</p>';
	}

	/**
	 * Persist Inventory & Profit settings (admin-post).
	 */
	/**
	 * Add an exchange-rate history row (admin-post).
	 */
	/**
	 * Delete an exchange-rate history row (admin-post).
	 */
	/**
	 * Danger Zone: dry-run counts for plugin analytics reset.
	 */
	/**
	 * Danger Zone: execute reset after preview + confirmations.
	 */
	/**
	 * Settings tab: profit, inventory, and reporting options.
	 */
	/**
	 * Settings: Exchange Rate History (separate forms; not nested in Save settings).
	 */
	/**
	 * Danger Zone: preview / apply reset of plugin analytics tables and snapshot metas.
	 */
	/**
	 * @param string $form_action admin-post.php URL.
	 */
	/**
	 * @param string               $token   Preview token.
	 * @param array<string, mixed> $preview Payload + counts from transient.
	 */
	/**
	 * Dashboard date range from request (GET) with defaults from plugin settings.
	 *
	 * @return array{date_from:string,date_to:string}
	 */

	/**
	 * Dashboard: low-stock table and quick links to hub tabs.
	 *
	 * @param array<string, mixed> $summary_base Base filters for low-stock lines (same as overview summary).
	 */

	/**
	 * Dashboard tab: KPIs (live metrics) and Chart.js charts.
	 */

	/**
	 * Single admin screen: Inventory & Profit hub.
	 */
	public function render_inventory_profit_shell() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		$tab = $this->get_requested_tab();

		echo '<div class="wrap wc-io-wrap wc-io-profit-wrap">';
		echo '<h1>' . esc_html__( 'Inventory & Profit', 'wc-inventory-overview' ) . '</h1>';
		$this->render_inventory_profit_tabs( $tab );

		echo '<div class="wc-io-tab-panel wc-io-tab-panel--' . esc_attr( $tab ) . '">';

		switch ( $tab ) {
			case self::TAB_DASHBOARD:
				WC_Inventory_Overview_Dashboard_Controller::instance()->render();
				break;
			case self::TAB_OVERVIEW:
				$this->render_inventory_overview_panel();
				break;
			case self::TAB_RESTOCK:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to use restock and cost adjustment.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				$this->render_restock_panel();
				break;
			case self::TAB_MOVEMENTS:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view inventory movements.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				$this->render_movements_panel();
				break;
			case self::TAB_ORDER_PROFIT:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view order profit.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				$this->render_order_profit_panel();
				break;
			case self::TAB_PRODUCT_PROFITABILITY:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view product profitability.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				$this->render_product_profitability_panel();
				break;
			case self::TAB_SETTINGS:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to change plugin settings.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Settings_Controller::instance()->render();
				break;
			default:
				$this->render_inventory_overview_panel();
				break;
		}

		echo '</div></div>';
	}

	/**
	 * Assets for Inventory Restock (product search).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_restock_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( self::TAB_RESTOCK !== $this->get_requested_tab() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'woocommerce_admin_styles' ),
			WC_INVENTORY_OVERVIEW_VERSION
		);
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script(
			'wc-io-restock-cost-adj',
			plugins_url( 'assets/restock-cost-adj.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-restock-cost-adj',
			'wcIoCostAdj',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wc_io_cost_adj_preview' ),
				'strings' => array(
					'selectProduct' => __( 'Select a product or variation to see current cost data.', 'wc-inventory-overview' ),
					'loading'       => __( 'Loading…', 'wc-inventory-overview' ),
					'error'         => __( 'Could not load cost data.', 'wc-inventory-overview' ),
				),
			)
		);
		// M6: wc-io-batch-intake (assets/batch-intake.js) is no longer enqueued —
		// Batch Intake's admin markup was retired in M6, so the script would only
		// ever bind to a DOM section that no longer renders. The asset file itself
		// is left in place (disabled-not-deleted), matching the M6 implementation
		// plan's Retirement strategy.
		wp_add_inline_script(
			'wc-enhanced-select',
			'jQuery(function($){ $(document.body).trigger("wc-enhanced-select-init"); });',
			'after'
		);
		wp_enqueue_script(
			'wc-io-supplier-picker',
			plugins_url( 'assets/supplier-picker.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-supplier-picker',
			'wcIoSupplierPicker',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'wc_io_search_suppliers' ),
				'quickNonce' => wp_create_nonce( 'wc_io_quick_create_supplier' ),
				'strings'    => array(
					'newSupplier' => __( '+ Create new supplier', 'wc-inventory-overview' ),
					'loading'     => __( 'Loading…', 'wc-inventory-overview' ),
					'error'       => __( 'Error creating supplier', 'wc-inventory-overview' ),
				),
			)
		);
	}

	/**
	 * Settings tab: default shipping FX helpers (Exchange Rate History + AJAX, same as Batch Intake).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	/**
	 * AJAX: lookup FX to EUR for Batch Intake prefill (history only; no Settings fallback).
	 */

	/**
	 * Process restock form (admin-post).
	 */
	public function handle_restock_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_restock' );

		$redirect = $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_QUICK ) );

		$line_id = isset( $_POST['wc_io_line_id'] ) ? absint( wp_unslash( $_POST['wc_io_line_id'] ) ) : 0;
		if ( ! $line_id ) {
			$line_id = isset( $_POST['wc_io_line_id'][0] ) ? absint( wp_unslash( $_POST['wc_io_line_id'][0] ) ) : 0;
		}

		$qty  = isset( $_POST['wc_io_qty'] ) ? wc_stock_amount( wp_unslash( $_POST['wc_io_qty'] ) ) : 0;
		$cost = isset( $_POST['wc_io_unit_cost'] ) ? wc_format_decimal( wp_unslash( $_POST['wc_io_unit_cost'] ), 6 ) : '';
		$sup  = isset( $_POST['wc_io_supplier'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_supplier'] ) ) : '';
		$note = isset( $_POST['wc_io_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_io_note'] ) ) : '';

		if ( ! $line_id ) {
			wp_safe_redirect( add_query_arg( 'wc_io_restock_msg', 'missing_product', $redirect ) );
			exit;
		}

		$result = WC_Inventory_Overview_Restock_Service::process_purchase_restock(
			$line_id,
			(float) $qty,
			(float) $cost,
			$sup,
			$note
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'wc_io_restock_msg' => 'error',
						'wc_io_restock_err' => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( 'wc_io_restock_msg', 'success', $redirect ) );
		exit;
	}

	/**
	 * Process cost adjustment form (admin-post).
	 */
	public function handle_cost_adjustment_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_cost_adjustment' );

		$redirect = $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_ADJUST ) );

		$line_id = isset( $_POST['wc_io_adj_line_id'] ) ? absint( wp_unslash( $_POST['wc_io_adj_line_id'] ) ) : 0;
		if ( ! $line_id && isset( $_POST['wc_io_adj_line_id'][0] ) ) {
			$line_id = absint( wp_unslash( $_POST['wc_io_adj_line_id'][0] ) );
		}

		$new_avg = isset( $_POST['wc_io_new_avg_cost'] ) ? wc_format_decimal( wp_unslash( $_POST['wc_io_new_avg_cost'] ), 6 ) : '';
		$note    = isset( $_POST['wc_io_adj_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_io_adj_note'] ) ) : '';

		if ( ! $line_id ) {
			wp_safe_redirect( add_query_arg( 'wc_io_adj_msg', 'missing_product', $redirect ) );
			exit;
		}

		$result = WC_Inventory_Overview_Cost_Adjustment_Service::process(
			$line_id,
			(float) $new_avg,
			$note
		);

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'wc_io_adj_msg' => 'error',
						'wc_io_adj_err' => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( 'wc_io_adj_msg', 'success', $redirect ) );
		exit;
	}

	/**
	 * Restock + cost adjustment panel (hub tab; capability enforced by shell).
	 */
	protected function render_restock_panel() {
		echo '<div class="notice notice-info"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: plugin version */
				__( 'WC Inventory Overview %s', 'wc-inventory-overview' ),
				WC_INVENTORY_OVERVIEW_VERSION
			)
		) . '</strong></p></div>';

		if ( isset( $_GET['wc_io_restock_msg'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['wc_io_restock_msg'] ) );
			if ( 'success' === $code ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Restock recorded. Stock and weighted average cost were updated.', 'wc-inventory-overview' ) . '</p></div>';
			} elseif ( 'error' === $code && ! empty( $_GET['wc_io_restock_err'] ) ) {
				$err = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_restock_err'] ) ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
			} elseif ( 'missing_product' === $code ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select a product or variation.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['wc_io_adj_msg'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['wc_io_adj_msg'] ) );
			if ( 'success' === $code ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Cost adjustment saved. Average unit cost and inventory value were updated. Stock quantity was not changed.', 'wc-inventory-overview' ) . '</p></div>';
			} elseif ( 'error' === $code && ! empty( $_GET['wc_io_adj_err'] ) ) {
				$err = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_adj_err'] ) ) );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
			} elseif ( 'missing_product' === $code ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Select a product or variation for the cost adjustment.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}

		$form_action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wc-io-restock-panel wc-io-wrap">';
		$this->render_restock_subnav();

		$sub = $this->get_restock_subview();
		if ( self::RESTOCK_VIEW_QUICK === $sub ) {
			echo '<h2 id="wc-io-restock-section" class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Quick Restock', 'wc-inventory-overview' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Record a purchase restock. This increases stock and recalculates weighted average unit cost for the selected variation or simple product.', 'wc-inventory-overview' ) . '</p>';

			echo '<form method="post" action="' . $form_action . '" class="wc-io-restock-form" style="max-width:640px;margin-top:16px">';
			echo '<input type="hidden" name="action" value="wc_io_restock" />';
			wp_nonce_field( 'wc_io_restock' );

			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row"><label for="wc-io-line-id">' . esc_html__( 'Product / variation', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<select class="wc-product-search" style="width:100%;max-width:36em" id="wc-io-line-id" name="wc_io_line_id" data-placeholder="' . esc_attr__( 'Search for a product or variation…', 'wc-inventory-overview' ) . '" data-action="woocommerce_json_search_products_and_variations"></select>';
			echo '<p class="description">' . esc_html__( 'Choose a variation or a simple product. Parent variable products cannot be restocked here.', 'wc-inventory-overview' ) . '</p>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-qty">' . esc_html__( 'Quantity added', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<input type="number" step="any" min="0.0001" class="regular-text" id="wc-io-qty" name="wc_io_qty" required />';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-unit-cost">' . esc_html__( 'Supplier unit cost', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<input type="text" inputmode="decimal" class="regular-text" id="wc-io-unit-cost" name="wc_io_unit_cost" required placeholder="0.00" />';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-supplier">' . esc_html__( 'Supplier name', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<input type="text" class="regular-text" id="wc-io-supplier" name="wc_io_supplier" maxlength="190" />';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-note">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<textarea class="large-text" rows="3" id="wc-io-note" name="wc_io_note"></textarea>';
			echo '</td></tr>';
			echo '</table>';

			submit_button( __( 'Record restock', 'wc-inventory-overview' ) );
			echo '</form>';
		} else {
			echo '<h2 id="wc-io-cost-adjustment-section" class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Cost Adjustment', 'wc-inventory-overview' ) . '</h2>';
			echo '<p class="description">' . esc_html__( 'Correct the average unit cost without changing stock quantity. Inventory value is recalculated as current stock × the cost you set below.', 'wc-inventory-overview' ) . '</p>';

			echo '<form method="post" action="' . $form_action . '" class="wc-io-cost-adjustment-form" style="max-width:640px;margin-top:16px">';
			echo '<input type="hidden" name="action" value="wc_io_cost_adjustment" />';
			wp_nonce_field( 'wc_io_cost_adjustment' );

			echo '<table class="form-table" role="presentation">';
			echo '<tr><th scope="row"><label for="wc-io-adj-line-id">' . esc_html__( 'Product / variation', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<select class="wc-product-search" style="width:100%;max-width:36em" id="wc-io-adj-line-id" name="wc_io_adj_line_id" data-placeholder="' . esc_attr__( 'Search for a product or variation…', 'wc-inventory-overview' ) . '" data-action="woocommerce_json_search_products_and_variations"></select>';
			echo '<p class="description">' . esc_html__( 'Choose a variation or a simple product. Parent variable products are not supported.', 'wc-inventory-overview' ) . '</p>';
			echo '</td></tr>';

			echo '<tr class="wc-io-cost-adj-current-wrap"><th scope="row">' . esc_html__( 'Current values', 'wc-inventory-overview' ) . '</th><td>';
			echo '<p class="description wc-io-cost-adj-lead" id="wc-io-cost-adj-lead">' . esc_html__( 'Select a product or variation to see current cost data.', 'wc-inventory-overview' ) . '</p>';
			echo '<table class="widefat striped wc-io-cost-adj-current-table" style="max-width:36em;margin-top:8px" role="presentation"><tbody>';
			echo '<tr><th scope="row" style="width:40%">' . esc_html__( 'Current stock', 'wc-inventory-overview' ) . '</th><td id="wc-io-cost-adj-stock">' . esc_html( '—' ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Current average unit cost', 'wc-inventory-overview' ) . '</th><td id="wc-io-cost-adj-avg">' . esc_html( '—' ) . '</td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Current inventory value', 'wc-inventory-overview' ) . '</th><td id="wc-io-cost-adj-value">' . esc_html( '—' ) . '</td></tr>';
			echo '</tbody></table></td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-new-avg">' . esc_html__( 'Set average unit cost', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<input type="text" inputmode="decimal" class="regular-text" id="wc-io-new-avg" name="wc_io_new_avg_cost" value="" required min="0" autocomplete="off" />';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="wc-io-adj-note">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</label></th><td>';
			echo '<textarea class="large-text" rows="3" id="wc-io-adj-note" name="wc_io_adj_note"></textarea>';
			echo '</td></tr>';
			echo '</table>';

			submit_button( __( 'Save cost adjustment', 'wc-inventory-overview' ), 'secondary' );
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Inventory movement log (custom table).
	 */
	protected function render_movements_panel() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Inventory Movements', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Stock quantity and average cost changes recorded by the plugin.', 'wc-inventory-overview' ) . '</p>';

		echo '<form id="wc-io-movements-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_MOVEMENTS ) . '" />';
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
	protected function render_order_profit_panel() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Order Profit', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Product revenue sums WooCommerce line totals after discount from each snapshotted line (stored as _wc_io_line_total_snapshot when available; older rows use unit snapshot × quantity). Product cost uses snapshot average unit cost. The Discount column sums per-line _wc_io_discount_snapshot (empty for legacy snapshots). Shipping paid follows the "include shipping tax" setting. Actual shipping cost uses the order meta when set; otherwise the default from Settings. Gross profit = product revenue + shipping paid - product cost - actual shipping cost. Margin % is gross profit divided by (product revenue + shipping paid) when that sum is positive.', 'wc-inventory-overview' ) . '</p>';

		$filters      = WC_Inventory_Overview_Order_Profit_Query::get_filters_from_request();
		$all_st       = array_keys( wc_get_order_statuses() );
		$status_value = count( $filters['statuses'] ) === count( $all_st ) ? 'all' : (string) $filters['statuses'][0];

		echo '<form id="wc-io-order-profit-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ORDER_PROFIT ) . '" />';

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
	protected function render_product_profitability_panel() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Product Profitability', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Aggregates line items that have price/cost snapshots on orders whose status matches the snapshot setting under Settings. Revenue uses discounted line totals from snapshots when present, otherwise unit snapshot × quantity. Average sale price is discounted revenue divided by units sold. The Discount column sums snapshot line discounts. Date filter uses order date (default range under Settings applies when you open this tab without date parameters). Money columns use the store default currency.', 'wc-inventory-overview' ) . '</p>';

		$filters = WC_Inventory_Overview_Product_Profitability_Query::get_filters_from_request();

		echo '<form id="wc-io-product-profitability-filter" method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_PRODUCT_PROFITABILITY ) . '" />';

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

	public function on_load_screen() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Inventory lines per page', 'wc-inventory-overview' ),
				'default' => 20,
				'option'  => 'wc_io_per_page',
			)
		);

		$this->maybe_export_csv();
		$this->maybe_handle_bulk();
	}

	/**
	 * CSV export for movement log (current filters and sort).
	 */
	protected function maybe_export_movements_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export inventory movements.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_mv_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_mv_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || self::TAB_MOVEMENTS !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
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
	protected function maybe_export_order_profit_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export order profit data.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_op_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_op_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || self::TAB_ORDER_PROFIT !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
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
	protected function maybe_export_product_profitability_csv() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export product profitability data.', 'wc-inventory-overview' ) );
		}
		if ( empty( $_GET['wc_io_pp_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_pp_export'] ) ) ) {
			return;
		}

		if ( ! isset( $_GET['tab'] ) || self::TAB_PRODUCT_PROFITABILITY !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
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

	protected function maybe_export_csv() {
		if ( empty( $_GET['wc_io_export'] ) || 'csv' !== sanitize_key( wp_unslash( $_GET['wc_io_export'] ) ) ) {
			return;
		}

		check_admin_referer( 'wc_io_export_csv', '_wc_io_export_nonce' );

		$params = $this->get_query_params_from_request();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wc-inventory-overview-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			exit;
		}

		fprintf( $out, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

		fputcsv(
			$out,
			array_merge(
				array(
					__( 'Product name', 'wc-inventory-overview' ),
					__( 'Parent product', 'wc-inventory-overview' ),
					__( 'Variation attributes', 'wc-inventory-overview' ),
					__( 'SKU', 'wc-inventory-overview' ),
					__( 'Stock quantity', 'wc-inventory-overview' ),
				),
				current_user_can( 'manage_woocommerce' )
					? array(
						__( 'Average unit cost', 'wc-inventory-overview' ),
						__( 'Inventory value', 'wc-inventory-overview' ),
					)
					: array(),
				array(
					__( 'Stock status', 'wc-inventory-overview' ),
					__( 'Backorders allowed', 'wc-inventory-overview' ),
					__( 'Expected restock date', 'wc-inventory-overview' ),
					__( 'Regular price', 'wc-inventory-overview' ),
					__( 'Sale price', 'wc-inventory-overview' ),
					__( 'Published / Draft', 'wc-inventory-overview' ),
					__( 'Catalog visibility', 'wc-inventory-overview' ),
					__( 'Last modified', 'wc-inventory-overview' ),
				)
			)
		);

		foreach ( WC_Inventory_Overview_Repository::iterate_products_for_export( $params, 300 ) as $product ) {
			$parent_title = '';
			$attrs        = '';
			if ( $product->is_type( 'variation' ) ) {
				$pid = $product->get_parent_id();
				if ( $pid ) {
					$parent_title = get_the_title( $pid );
				}
				$attrs = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
			}

			$restock       = get_post_meta( $product->get_id(), '_expected_back_in_stock_date', true );
			$dm            = $product->get_date_modified();
			$status_labels = wc_get_product_stock_status_options();
			$st            = $product->get_stock_status();
			$st_label      = isset( $status_labels[ $st ] ) ? $status_labels[ $st ] : $st;

			$avg_export = '';
			$val_export = '';
			if ( current_user_can( 'manage_woocommerce' ) && ( $product->is_type( 'variation' ) || $product->is_type( 'simple' ) ) ) {
				$af         = WC_Inventory_Overview_Costing::get_average_float( $product );
				$avg_export = null !== $af ? wc_format_decimal( $af, 4 ) : '';
				$vm         = $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
				$val_export = ( '' !== $vm && null !== $vm ) ? wc_format_decimal( $vm, 4 ) : '';
			}

			fputcsv(
				$out,
				array_merge(
					array(
						$product->get_name(),
						$parent_title,
						$attrs,
						$product->get_sku(),
						$product->managing_stock() ? (string) wc_stock_amount( (float) $product->get_stock_quantity() ) : '',
					),
					current_user_can( 'manage_woocommerce' )
						? array( $avg_export, $val_export )
						: array(),
					array(
						$st_label,
						$product->get_backorders(),
						(string) $restock,
						(string) $product->get_regular_price(),
						(string) $product->get_sale_price(),
						$product->get_status(),
						$product->get_catalog_visibility(),
						$dm ? $dm->date( 'c' ) : '',
					)
				)
			);
		}

		fclose( $out );
		exit;
	}

	/**
	 * Build repository params from GET/REQUEST (list + export).
	 *
	 * @return array<string, mixed>
	 */
	protected function get_query_params_from_request() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$cat_id = isset( $_REQUEST['wc_io_product_cat'] ) ? absint( $_REQUEST['wc_io_product_cat'] ) : 0;
		$tt_id  = 0;
		if ( $cat_id > 0 ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$tt_id = (int) $term->term_taxonomy_id;
			}
		}

		$stock_filter = isset( $_REQUEST['wc_io_stock_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['wc_io_stock_status'] ) ) : '';
		$stock_args   = array();
		if ( $stock_filter && in_array( $stock_filter, array_keys( wc_get_product_stock_status_options() ), true ) ) {
			$stock_args = array( $stock_filter );
		}

		return array(
			'search'          => $search,
			'category_tt_id'  => $tt_id,
			'stock_status'    => $stock_args,
			'exclude_private' => WC_Inventory_Overview_Repository::request_excludes_private(),
		);
	}

	protected function maybe_handle_bulk() {
		$action = $this->detect_bulk_action();
		if ( null === $action ) {
			return;
		}
		if ( empty( $_REQUEST['_wpnonce'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-wc-inventory-items' );

		$ids = isset( $_REQUEST['post'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['post'] ) ) : array();
		$ids = array_filter( $ids );
		if ( ! $ids ) {
			return;
		}

		$updated = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || ! current_user_can( 'edit_product', $id ) ) {
				continue;
			}

			switch ( $action ) {
				case 'wc_io_set_draft':
					$product->set_status( 'draft' );
					$product->save();
					++$updated;
					break;
				case 'wc_io_hide_catalog':
					$product->set_catalog_visibility( \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN );
					$product->save();
					++$updated;
					break;
				case 'wc_io_mark_instock':
					$product->set_stock_status( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK );
					$product->save();
					++$updated;
					break;
				case 'wc_io_mark_outofstock':
					$product->set_stock_status( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK );
					$product->save();
					++$updated;
					break;
				default:
					break;
			}
		}

		$sendback = remove_query_arg(
			array( 'wc_io_export', '_wc_io_export_nonce', 'action', 'action2', '_wpnonce' ),
			wp_get_referer() ? wp_get_referer() : $this->admin_url_tab( self::TAB_OVERVIEW )
		);
		$sendback = add_query_arg( 'wc_io_bulk_done', (string) $updated, $sendback );
		wp_safe_redirect( $sendback );
		exit;
	}

	/**
	 * @return string|null
	 */
	protected function detect_bulk_action() {
		$a = '';
		if ( ! empty( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$a = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( ! empty( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$a = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		if ( '' === $a ) {
			return null;
		}
		$valid = array( 'wc_io_set_draft', 'wc_io_hide_catalog', 'wc_io_mark_instock', 'wc_io_mark_outofstock' );
		return in_array( $a, $valid, true ) ? $a : null;
	}

	public function ajax_save_inline_stock() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-inventory-overview' ) ), 403 );
		}
		check_ajax_referer( 'wc_io_inventory', 'nonce' );

		$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $id || ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'wc-inventory-overview' ) ), 400 );
		}

		$product = wc_get_product( $id );
		if ( ! $product || ! $product->managing_stock() ) {
			wp_send_json_error( array( 'message' => __( 'Stock is not managed for this line.', 'wc-inventory-overview' ) ), 400 );
		}

		if ( ! isset( $_POST['stock_qty'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing quantity.', 'wc-inventory-overview' ) ), 400 );
		}

		$qty = wc_stock_amount( wp_unslash( $_POST['stock_qty'] ) );
		$product->set_stock_quantity( $qty );
		if ( WC_Inventory_Overview_Settings::auto_update_inventory_value_on_stock_edit() ) {
			WC_Inventory_Overview_Costing::maybe_sync_inventory_value_after_stock_change( $product );
		}
		$product->save();

		wp_send_json_success(
			array(
				'formatted'  => (string) wc_stock_amount( (float) $product->get_stock_quantity() ),
				'badgesHtml' => WC_Inventory_Overview_List_Table::render_status_badges( $product ),
			)
		);
	}

	/**
	 * AJAX: current stock, average cost, and inventory value for cost adjustment preview.
	 */
	public function ajax_get_cost_adjustment_preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-inventory-overview' ) ), 403 );
		}
		check_ajax_referer( 'wc_io_cost_adj_preview', 'nonce' );

		$id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'wc-inventory-overview' ) ), 400 );
		}

		$product = wc_get_product( $id );
		if ( ! $product instanceof WC_Product ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'wc-inventory-overview' ) ), 400 );
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a variation or a simple product, not a parent variable product.', 'wc-inventory-overview' ) ), 400 );
		}

		if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unsupported product type for costing.', 'wc-inventory-overview' ) ), 400 );
		}

		if ( $product->managing_stock() ) {
			$qty_raw = $product->get_stock_quantity();
			if ( null === $qty_raw || '' === $qty_raw ) {
				$stock_display = '—';
			} else {
				$stock_display = (string) wc_stock_amount( (float) $qty_raw );
			}
		} else {
			$stock_display = '—';
		}

		$avg_f = WC_Inventory_Overview_Costing::get_average_float( $product );
		if ( null === $avg_f ) {
			$avg_display = '—';
			$avg_input   = '';
		} else {
			$avg_display = wc_format_decimal( $avg_f, 4 );
			$avg_input   = wc_format_decimal( $avg_f, 6 );
		}

		$val_raw = $product->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
		if ( '' === $val_raw || null === $val_raw ) {
			$value_html = '<span class="wc-io-muted">' . esc_html( '—' ) . '</span>';
		} else {
			$value_html = wp_kses_post( wc_price( wc_format_decimal( $val_raw, 4 ) ) );
		}

		wp_send_json_success(
			array(
				'stock_display' => $stock_display,
				'avg_display'   => $avg_display,
				'avg_input'     => $avg_input,
				'value_html'    => $value_html,
			)
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$tab = $this->get_requested_tab();
		if ( self::TAB_DASHBOARD !== $tab && self::TAB_OVERVIEW !== $tab && self::TAB_MOVEMENTS !== $tab ) {
			return;
		}

		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			self::TAB_DASHBOARD === $tab ? array( 'dashicons' ) : array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);

		if ( self::TAB_DASHBOARD === $tab ) {
			wp_enqueue_script(
				'wc-io-chartjs',
				plugins_url( 'assets/vendor/chart.umd.min.js', WC_INVENTORY_OVERVIEW_FILE ),
				array(),
				WC_INVENTORY_OVERVIEW_VERSION,
				true
			);
			wp_enqueue_script(
				'wc-io-dashboard-charts',
				plugins_url( 'assets/dashboard-charts.js', WC_INVENTORY_OVERVIEW_FILE ),
				array( 'wc-io-chartjs' ),
				WC_INVENTORY_OVERVIEW_VERSION,
				true
			);
		}

		if ( self::TAB_OVERVIEW === $tab ) {
			wp_enqueue_script(
				'wc-inventory-overview-admin',
				plugins_url( 'assets/admin.js', WC_INVENTORY_OVERVIEW_FILE ),
				array(),
				WC_INVENTORY_OVERVIEW_VERSION,
				true
			);
			wp_localize_script(
				'wc-inventory-overview-admin',
				'wcIoInventory',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wc_io_inventory' ),
					'strings' => array(
						'error' => __( 'Could not save stock. Try again.', 'wc-inventory-overview' ),
					),
				)
			);
		}

		if ( self::TAB_MOVEMENTS === $tab ) {
			wp_enqueue_script(
				'wc-io-movements-table',
				plugins_url( 'assets/movements-table.js', WC_INVENTORY_OVERVIEW_FILE ),
				array( 'jquery' ),
				WC_INVENTORY_OVERVIEW_VERSION,
				true
			);
		}
	}

	/**
	 * @param array<string, int> $stats Summary counts.
	 */
	public static function render_summary_cards( array $stats ) {
		if ( empty( $stats ) ) {
			return;
		}
		$cards = array(
			array(
				'key'   => 'total',
				'label' => __( 'Matching lines', 'wc-inventory-overview' ),
				'value' => $stats['total'] ?? 0,
				'alert' => false,
			),
			array(
				'key'   => 'in_stock',
				'label' => __( 'In stock', 'wc-inventory-overview' ),
				'value' => $stats['in_stock'] ?? 0,
				'alert' => false,
			),
			array(
				'key'   => 'out_of_stock',
				'label' => __( 'Out of stock', 'wc-inventory-overview' ),
				'value' => $stats['out_of_stock'] ?? 0,
				'alert' => ( ( $stats['out_of_stock'] ?? 0 ) > 0 ),
			),
			array(
				'key'   => 'on_backorder',
				'label' => __( 'On backorder', 'wc-inventory-overview' ),
				'value' => $stats['on_backorder'] ?? 0,
				'alert' => ( ( $stats['on_backorder'] ?? 0 ) > 0 ),
			),
			array(
				'key'   => 'low_stock',
				'label' => __( 'Low stock (lines)', 'wc-inventory-overview' ),
				'value' => $stats['low_stock'] ?? 0,
				'alert' => ( ( $stats['low_stock'] ?? 0 ) > 0 ),
			),
			array(
				'key'   => 'draft',
				'label' => __( 'Draft', 'wc-inventory-overview' ),
				'value' => $stats['draft'] ?? 0,
				'alert' => ( ( $stats['draft'] ?? 0 ) > 0 ),
			),
			array(
				'key'   => 'hidden',
				'label' => __( 'Hidden catalog', 'wc-inventory-overview' ),
				'value' => $stats['hidden'] ?? 0,
				'alert' => false,
			),
		);

		echo '<div class="wc-io-summary" role="region" aria-label="' . esc_attr__( 'Inventory summary', 'wc-inventory-overview' ) . '">';
		foreach ( $cards as $card ) {
			$cls = 'wc-io-summary-card';
			if ( ! empty( $card['alert'] ) ) {
				$cls .= ' is-alert';
			}
			echo '<div class="' . esc_attr( $cls ) . '" data-wc-io-metric="' . esc_attr( $card['key'] ) . '">';
			echo '<span class="wc-io-summary-label">' . esc_html( $card['label'] ) . '</span>';
			echo '<span class="wc-io-summary-value">' . esc_html( (string) (int) $card['value'] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Inventory Overview list + filters (hub tab).
	 */
	protected function render_inventory_overview_panel() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		if ( ! empty( $_GET['wc_io_bulk_done'] ) ) {
			$n = absint( $_GET['wc_io_bulk_done'] );
			echo '<div class="notice notice-success is-dismissible"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of products updated */
					_n( '%d product updated.', '%d products updated.', $n, 'wc-inventory-overview' ),
					$n
				)
			);
			echo '</p></div>';
		}

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Inventory Overview', 'wc-inventory-overview' ) . '</h2>';
		echo '<hr class="wp-header-end" />';

		$list_table = new WC_Inventory_Overview_List_Table();
		$list_table->prepare_items();

		self::render_summary_cards( $list_table->summary_stats );

		echo '<form id="posts-filter" method="get">';
		echo '<div class="wc-io-sticky-toolbar">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_OVERVIEW ) . '" />';
		$list_table->search_box( __( 'Search products', 'wc-inventory-overview' ), 'wc-io-product' );
		$list_table->render_top_tablenav();
		echo '</div>';
		$list_table->render_table_main();
		echo '</form>';
	}
}

add_filter(
	'set_screen_option_wc_io_per_page',
	static function ( $screen_option, $option, $value ) {
		if ( 'wc_io_per_page' === $option ) {
			return max( 1, min( 500, (int) $value ) );
		}
		return $screen_option;
	},
	10,
	3
);

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
