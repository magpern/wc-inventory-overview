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
		WC_Inventory_Overview_Product_Replenishment_Admin::register();
		WC_Inventory_Overview_Purchasing_Page::instance()->init();
		WC_Inventory_Overview_Settings_Controller::instance()->init();
		WC_Inventory_Overview_Reporting_Controller::instance()->init();
		WC_Inventory_Overview_Restock_Controller::instance()->init();
		WC_Inventory_Overview_Overview_Controller::instance()->init();
		WC_Inventory_Overview_Expected_Delivery_Service::register();
		WC_Inventory_Overview_Expected_Delivery_Renderer::register();

		add_action( 'admin_init', array( $this, 'redirect_legacy_inventory_admin_pages' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'on_load_inventory_profit_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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
	 * Unified WooCommerce admin hub: screen options, CSV export, bulk, restock bootstrap.
	 */
	public function on_load_inventory_profit_page() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-inventory-overview' ) );
		}

		$tab = $this->get_requested_tab();
		if ( self::TAB_OVERVIEW === $tab ) {
			WC_Inventory_Overview_Overview_Controller::instance()->on_load_screen();
			return;
		}
		if ( self::TAB_RESTOCK === $tab && current_user_can( 'manage_woocommerce' ) ) {
			WC_Inventory_Overview_Restock_Controller::instance()->on_load_restock_screen();
		}
		if ( self::TAB_MOVEMENTS === $tab && current_user_can( 'manage_woocommerce' ) ) {
			WC_Inventory_Overview_Reporting_Controller::instance()->on_load_movements();
		}
		if ( self::TAB_ORDER_PROFIT === $tab && current_user_can( 'manage_woocommerce' ) ) {
			WC_Inventory_Overview_Reporting_Controller::instance()->on_load_order_profit();
		}
		if ( self::TAB_PRODUCT_PROFITABILITY === $tab && current_user_can( 'manage_woocommerce' ) ) {
			WC_Inventory_Overview_Reporting_Controller::instance()->on_load_product_profitability();
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
				WC_Inventory_Overview_Overview_Controller::instance()->render();
				break;
			case self::TAB_RESTOCK:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to use restock and cost adjustment.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Restock_Controller::instance()->render();
				break;
			case self::TAB_MOVEMENTS:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view inventory movements.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Reporting_Controller::instance()->render_movements();
				break;
			case self::TAB_ORDER_PROFIT:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view order profit.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Reporting_Controller::instance()->render_order_profit();
				break;
			case self::TAB_PRODUCT_PROFITABILITY:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view product profitability.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Reporting_Controller::instance()->render_product_profitability();
				break;
			case self::TAB_SETTINGS:
				if ( ! current_user_can( 'manage_woocommerce' ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to change plugin settings.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				}
				WC_Inventory_Overview_Settings_Controller::instance()->render();
				break;
			default:
				WC_Inventory_Overview_Overview_Controller::instance()->render();
				break;
		}

		echo '</div></div>';
	}

	/**
	 * Settings tab: default shipping FX helpers (Exchange Rate History + AJAX, same as Batch Intake).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	/**
	 * AJAX: lookup FX to EUR for Batch Intake prefill (history only; no Settings fallback).
	 */

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$tab = $this->get_requested_tab();
		if ( self::TAB_DASHBOARD !== $tab ) {
			return;
		}

		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'dashicons' ),
			WC_INVENTORY_OVERVIEW_VERSION
		);

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

}
