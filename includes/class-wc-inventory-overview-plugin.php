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
		WC_Inventory_Overview_Reporting_Controller::instance()->init();
		WC_Inventory_Overview_Restock_Controller::instance()->init();
		WC_Inventory_Overview_Expected_Delivery_Service::register();
		WC_Inventory_Overview_Expected_Delivery_Renderer::register();

		add_action( 'admin_init', array( $this, 'redirect_legacy_inventory_admin_pages' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'on_load_inventory_profit_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wc_io_save_inline_stock', array( $this, 'ajax_save_inline_stock' ) );
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
			$this->on_load_screen();
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
				$this->render_inventory_overview_panel();
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
				$this->render_inventory_overview_panel();
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

	public function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$tab = $this->get_requested_tab();
		if ( self::TAB_DASHBOARD !== $tab && self::TAB_OVERVIEW !== $tab ) {
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

