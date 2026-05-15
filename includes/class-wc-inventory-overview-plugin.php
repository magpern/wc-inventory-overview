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

	public const RESTOCK_VIEW_BATCH = 'batch';

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

		add_action( 'admin_init', array( $this, 'redirect_legacy_inventory_admin_pages' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'on_load_inventory_profit_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_restock_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_shipping_assets' ) );
		add_action( 'admin_post_wc_io_restock', array( $this, 'handle_restock_post' ) );
		add_action( 'admin_post_wc_io_batch_apply', array( $this, 'handle_batch_apply_post' ) );
		add_action( 'admin_post_wc_io_cost_adjustment', array( $this, 'handle_cost_adjustment_post' ) );
		add_action( 'admin_post_wc_io_save_settings', array( $this, 'handle_save_settings_post' ) );
		add_action( 'admin_post_wc_io_add_exchange_rate', array( $this, 'handle_add_exchange_rate_post' ) );
		add_action( 'admin_post_wc_io_delete_exchange_rate', array( $this, 'handle_delete_exchange_rate_post' ) );
		add_action( 'admin_post_wc_io_danger_reset_preview', array( $this, 'handle_danger_reset_preview_post' ) );
		add_action( 'admin_post_wc_io_danger_reset_apply', array( $this, 'handle_danger_reset_apply_post' ) );
		add_action( 'wp_ajax_wc_io_save_inline_stock', array( $this, 'ajax_save_inline_stock' ) );
		add_action( 'wp_ajax_wc_io_get_cost_adjustment_preview', array( $this, 'ajax_get_cost_adjustment_preview' ) );
		add_action( 'wp_ajax_wc_io_batch_preview', array( $this, 'ajax_batch_preview' ) );
		add_action( 'wp_ajax_wc_io_get_exchange_rate', array( $this, 'ajax_get_exchange_rate' ) );
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
			wp_safe_redirect( $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_BATCH ) ) );
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
			self::TAB_DASHBOARD            => array(
				'label' => __( 'Dashboard', 'wc-inventory-overview' ),
				'cap'   => 'edit_products',
			),
			self::TAB_OVERVIEW            => array(
				'label' => __( 'Inventory Overview', 'wc-inventory-overview' ),
				'cap'   => 'edit_products',
			),
			self::TAB_RESTOCK             => array(
				'label' => __( 'Restock / Cost Adjustment', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_MOVEMENTS           => array(
				'label' => __( 'Inventory Movements', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_ORDER_PROFIT        => array(
				'label' => __( 'Order Profit', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_PRODUCT_PROFITABILITY => array(
				'label' => __( 'Product Profitability', 'wc-inventory-overview' ),
				'cap'   => 'manage_woocommerce',
			),
			self::TAB_SETTINGS            => array(
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
	protected function get_requested_tab() {
		$raw = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
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
	protected function admin_url_tab( $tab, array $extra = array() ) {
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
	 * Restock tab internal sub-view (batch intake default).
	 *
	 * @return string batch|quick|adjust
	 */
	protected function get_restock_subview() {
		if ( self::TAB_RESTOCK !== $this->get_requested_tab() ) {
			return self::RESTOCK_VIEW_BATCH;
		}
		$raw = isset( $_GET['restock_view'] ) ? sanitize_key( wp_unslash( $_GET['restock_view'] ) ) : '';
		$allowed = array(
			self::RESTOCK_VIEW_BATCH,
			self::RESTOCK_VIEW_QUICK,
			self::RESTOCK_VIEW_ADJUST,
		);
		if ( in_array( $raw, $allowed, true ) ) {
			return $raw;
		}
		return self::RESTOCK_VIEW_BATCH;
	}

	/**
	 * Secondary nav under Restock / Cost Adjustment.
	 */
	protected function render_restock_subnav() {
		$cur   = $this->get_restock_subview();
		$items = array(
			self::RESTOCK_VIEW_BATCH  => __( 'Batch Intake', 'wc-inventory-overview' ),
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
	public function handle_save_settings_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_save_settings', '_wc_io_settings_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$res  = WC_Inventory_Overview_Settings::save_from_post( $post );
		if ( is_wp_error( $res ) ) {
			set_transient( 'wc_io_settings_save_err_' . get_current_user_id(), $res->get_error_message(), 120 );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => self::PAGE_SLUG,
						'tab'            => self::TAB_SETTINGS,
						'wc_io_settings' => 'err',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::PAGE_SLUG,
					'tab'             => self::TAB_SETTINGS,
					'wc_io_settings' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Add an exchange-rate history row (admin-post).
	 */
	public function handle_add_exchange_rate_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_add_exchange_rate', '_wc_io_add_exchange_rate_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$from = isset( $post['wc_io_fx_from'] ) ? strtoupper( sanitize_text_field( (string) $post['wc_io_fx_from'] ) ) : '';
		$to   = isset( $post['wc_io_fx_to'] ) ? strtoupper( sanitize_text_field( (string) $post['wc_io_fx_to'] ) ) : WC_Inventory_Overview_Exchange_Rates::TO_CURRENCY;
		$rate = isset( $post['wc_io_fx_rate'] ) ? (string) $post['wc_io_fx_rate'] : '';
		$date = isset( $post['wc_io_fx_date'] ) ? sanitize_text_field( (string) $post['wc_io_fx_date'] ) : '';
		$note = isset( $post['wc_io_fx_note'] ) ? sanitize_textarea_field( (string) $post['wc_io_fx_note'] ) : '';

		$res = WC_Inventory_Overview_Exchange_Rates::insert_rate( $from, $to, $rate, $date, $note );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE_SLUG,
						'tab'          => self::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( $res->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'tab'      => self::TAB_SETTINGS,
					'wc_io_fx' => 'added',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Delete an exchange-rate history row (admin-post).
	 */
	public function handle_delete_exchange_rate_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$id   = isset( $post['wc_io_fx_delete_id'] ) ? absint( $post['wc_io_fx_delete_id'] ) : 0;
		if ( $id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE_SLUG,
						'tab'          => self::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( __( 'Invalid exchange rate id.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		check_admin_referer( 'wc_io_delete_exchange_rate_' . $id, '_wc_io_delete_fx_nonce' );

		$res = WC_Inventory_Overview_Exchange_Rates::delete_rate( $id );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE_SLUG,
						'tab'          => self::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( $res->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'tab'      => self::TAB_SETTINGS,
					'wc_io_fx' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Danger Zone: dry-run counts for plugin analytics reset.
	 */
	public function handle_danger_reset_preview_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_danger_reset_preview', '_wc_io_danger_reset_preview_nonce' );

		$post    = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$payload = WC_Inventory_Overview_Data_Reset::parse_request_payload( $post );
		if ( is_wp_error( $payload ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'tab'             => self::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( $payload->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$counts = WC_Inventory_Overview_Data_Reset::preview_counts( $payload );
		$token  = wp_generate_password( 32, false, false );
		WC_Inventory_Overview_Data_Reset::store_preview( $token, $payload, $counts );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'tab'       => self::TAB_SETTINGS,
					'wc_io_rst' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Danger Zone: execute reset after preview + confirmations.
	 */
	public function handle_danger_reset_apply_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_danger_reset_apply', '_wc_io_danger_reset_apply_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		if ( empty( $post['wc_io_danger_confirm'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'tab'             => self::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'You must confirm that you understand the consequences.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$typed = isset( $post['wc_io_danger_type_confirm'] ) ? trim( (string) $post['wc_io_danger_type_confirm'] ) : '';
		if ( 'RESET' !== $typed ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'tab'             => self::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'Type RESET exactly in the confirmation field.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$token = isset( $post['wc_io_reset_preview_token'] ) ? sanitize_text_field( (string) $post['wc_io_reset_preview_token'] ) : '';
		$prev  = WC_Inventory_Overview_Data_Reset::get_preview( $token );
		if ( null === $prev ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'tab'             => self::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'Preview expired or invalid. Run Preview Reset again.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = WC_Inventory_Overview_Data_Reset::apply_reset( $prev['payload'], $prev['counts'] );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => self::PAGE_SLUG,
						'tab'             => self::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		WC_Inventory_Overview_Data_Reset::delete_preview( $token );
		WC_Inventory_Overview_Data_Reset::store_result_notice(
			get_current_user_id(),
			array(
				'deleted' => $result,
				'counts'  => $prev['counts'],
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => self::PAGE_SLUG,
					'tab'                  => self::TAB_SETTINGS,
					'wc_io_reset_applied'  => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Settings tab: profit, inventory, and reporting options.
	 */
	protected function render_settings_panel() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['wc_io_settings'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['wc_io_settings'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wc-inventory-overview' ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_settings'] ) && 'err' === sanitize_key( wp_unslash( $_GET['wc_io_settings'] ) ) ) {
			$err = get_transient( 'wc_io_settings_save_err_' . get_current_user_id() );
			if ( is_string( $err ) && '' !== $err ) {
				delete_transient( 'wc_io_settings_save_err_' . get_current_user_id() );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
			}
		}

		if ( isset( $_GET['wc_io_fx'] ) && is_string( $_GET['wc_io_fx'] ) ) {
			$fxs = sanitize_key( wp_unslash( $_GET['wc_io_fx'] ) );
			if ( 'added' === $fxs ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exchange rate added.', 'wc-inventory-overview' ) . '</p></div>';
			} elseif ( 'deleted' === $fxs ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exchange rate deleted.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['wc_io_fx_err'] ) && is_string( $_GET['wc_io_fx_err'] ) ) {
			$fxe = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_fx_err'] ) ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $fxe ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_reset_err'] ) && is_string( $_GET['wc_io_reset_err'] ) ) {
			$err = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_reset_err'] ) ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_reset_applied'] ) && '1' === sanitize_key( wp_unslash( $_GET['wc_io_reset_applied'] ) ) ) {
			$res = WC_Inventory_Overview_Data_Reset::consume_result_notice( get_current_user_id() );
			if ( is_array( $res ) && ! empty( $res['deleted'] ) && is_array( $res['deleted'] ) ) {
				$d   = $res['deleted'];
				$msg = __( 'Danger Zone reset completed. Rows removed:', 'wc-inventory-overview' ) . ' ';
				$msg .= sprintf(
					/* translators: 1: movements, 2: batch costs, 3: batch lines, 4: batches, 5: line meta, 6: shipping meta, 7: product meta */
					__( 'movements %1$d; batch costs %2$d; batch lines %3$d; batches %4$d; order line snapshot meta %5$d; order shipping meta %6$d; product cost meta %7$d.', 'wc-inventory-overview' ),
					(int) ( $d['movements'] ?? 0 ),
					(int) ( $d['purchase_batch_costs'] ?? 0 ),
					(int) ( $d['purchase_batch_lines'] ?? 0 ),
					(int) ( $d['purchase_batches'] ?? 0 ),
					(int) ( $d['order_line_snapshot_meta'] ?? 0 ),
					(int) ( $d['order_shipping_meta'] ?? 0 ),
					(int) ( $d['product_cost_meta_rows'] ?? 0 )
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Danger Zone reset completed.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}

		$form_action = admin_url( 'admin-post.php' );
		$snap        = WC_Inventory_Overview_Settings::get_snapshot_order_status_mode();
		$inc_tax     = WC_Inventory_Overview_Settings::include_shipping_tax();
		$ship_pkg    = WC_Inventory_Overview_Settings::get_default_actual_shipping_package();
		$allow_zero  = WC_Inventory_Overview_Settings::allow_zero_supplier_cost();
		$auto_val    = WC_Inventory_Overview_Settings::auto_update_inventory_value_on_stock_edit();
		$low_def     = WC_Inventory_Overview_Settings::get_default_low_stock_threshold();
		$range       = WC_Inventory_Overview_Settings::get_default_reporting_range();
		$neg_hi      = WC_Inventory_Overview_Settings::highlight_negative_profit();
		$def_cur     = WC_Inventory_Overview_Settings::get_default_purchase_currency();

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Settings', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Configure how snapshots, costs, shipping, reporting ranges, and low-stock thresholds behave across Inventory & Profit.', 'wc-inventory-overview' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-settings-form" style="max-width:920px;margin-top:16px">';
		echo '<input type="hidden" name="action" value="wc_io_save_settings" />';
		wp_nonce_field( 'wc_io_save_settings', '_wc_io_settings_nonce' );

		echo '<h3>' . esc_html__( 'Profit calculation', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wc-io-snapshot-status">' . esc_html__( 'Snapshot order status', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_snapshot_order_status" id="wc-io-snapshot-status">';
		printf(
			'<option value="processing"%2$s>%1$s</option>',
			esc_html__( 'Processing only', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_PROCESSING, $snap, false )
		);
		printf(
			'<option value="completed"%2$s>%1$s</option>',
			esc_html__( 'Completed only', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_COMPLETED, $snap, false )
		);
		printf(
			'<option value="both"%2$s>%1$s</option>',
			esc_html__( 'Processing and completed', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_BOTH, $snap, false )
		);
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Controls when line-item price and cost snapshots are written, and which orders are included in product profitability aggregates.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Include shipping tax in revenue', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_include_shipping_tax" value="yes"' . checked( true, $inc_tax, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_include_shipping_tax" value="no"' . checked( false, $inc_tax, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'Order Profit “shipping paid” uses shipping total plus shipping tax when enabled; otherwise shipping total only.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Default actual shipping', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset class="wc-io-settings-def-ship"><legend class="screen-reader-text">' . esc_html__( 'Default actual shipping cost', 'wc-inventory-overview' ) . '</legend>';
		echo '<p><label for="wc-io-def-ship-entered-amount">' . esc_html__( 'Amount', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="wc_io_def_ship_entered_amount" id="wc-io-def-ship-entered-amount" inputmode="decimal" value="' . esc_attr( $ship_pkg['entered_amount'] ) . '" /></p>';
		echo '<p><label for="wc-io-def-ship-currency">' . esc_html__( 'Currency', 'wc-inventory-overview' ) . '</label><br />';
		echo '<select name="wc_io_def_ship_currency" id="wc-io-def-ship-currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $c ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $c ),
				selected( $ship_pkg['currency'], $c, false )
			);
		}
		echo '</select></p>';
		$rate_ro = WC_Inventory_Overview_Settings::CURRENCY_EUR === $ship_pkg['currency'];
		$rate_class = 'small-text';
		if ( ! $rate_ro && (float) $ship_pkg['exchange_rate_to_eur'] <= 0 ) {
			$rate_class .= ' wc-io-batch-rate-manual-needed';
		}
		echo '<p><label for="wc-io-def-ship-exchange-rate">' . esc_html__( 'Exchange rate to EUR', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="' . esc_attr( $rate_class ) . '" name="wc_io_def_ship_exchange_rate_to_eur" id="wc-io-def-ship-exchange-rate" inputmode="decimal" value="' . esc_attr( wc_format_decimal( $ship_pkg['exchange_rate_to_eur'], 8 ) ) . '" data-server-rate="' . esc_attr( wc_format_decimal( $ship_pkg['exchange_rate_to_eur'], 8 ) ) . '"';
		echo $rate_ro ? ' readonly="readonly"' : '';
		echo ' /></p>';
		echo '<p><label for="wc-io-def-ship-fx-date">' . esc_html__( 'Exchange rate date', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" name="wc_io_def_ship_exchange_rate_date" id="wc-io-def-ship-fx-date" value="' . esc_attr( $ship_pkg['exchange_rate_date'] ) . '" /></p>';
		echo '<p class="description" id="wc-io-def-ship-rate-hint" aria-live="polite"></p>';
		echo '<p><strong>' . esc_html__( 'EUR value used in Order Profit', 'wc-inventory-overview' ) . '</strong>: ';
		echo '<span id="wc-io-def-ship-converted-saved">' . esc_html( wc_format_decimal( $ship_pkg['converted_eur'], 6 ) ) . '</span>';
		echo ' <span class="description">(' . esc_html__( 'estimate before save:', 'wc-inventory-overview' ) . ' <span id="wc-io-def-ship-converted-live">—</span>)</span></p>';
		echo '<p class="description">' . esc_html__( 'Used when an order has no saved Actual shipping cost. Converted internally to EUR for profitability reporting.', 'wc-inventory-overview' ) . ' ';
		echo esc_html__( 'Exchange rate uses Exchange Rate History (latest on or before the date), or enter a manual rate—same rules as Batch Intake.', 'wc-inventory-overview' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Inventory', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Allow zero supplier cost', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_allow_zero_supplier_cost" value="yes"' . checked( true, $allow_zero, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_allow_zero_supplier_cost" value="no"' . checked( false, $allow_zero, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'When set to No, purchase restock rejects a supplier unit cost of zero.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Auto-update inventory value on inline stock edit', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_auto_update_inventory_value_on_stock_edit" value="yes"' . checked( true, $auto_val, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_auto_update_inventory_value_on_stock_edit" value="no"' . checked( false, $auto_val, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'When Yes, changing stock on Inventory Overview recalculates inventory value as stock multiplied by average unit cost (when average cost exists).', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wc-io-low-threshold">' . esc_html__( 'Default low stock threshold', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="0" step="1" name="wc_io_default_low_stock_threshold" id="wc-io-low-threshold" value="' . esc_attr( (string) $low_def ) . '" />';
		echo '<p class="description">' . esc_html__( 'Used for low-stock counts and badges when a product has no explicit low stock amount set.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Reporting', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wc-io-def-range">' . esc_html__( 'Default reporting range', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_default_reporting_range" id="wc-io-def-range">';
		$ranges = array(
			WC_Inventory_Overview_Settings::RANGE_7D  => __( 'Last 7 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_30D => __( 'Last 30 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_90D => __( 'Last 90 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_YTD => __( 'Year to date', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_ALL => __( 'All time', 'wc-inventory-overview' ),
		);
		foreach ( $ranges as $val => $label ) {
			printf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $val ),
				esc_html( $label ),
				selected( $val, $range, false )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Initial date range on Order Profit and Product Profitability when you open the tab without date query parameters. Submit empty dates on those tabs to see all orders regardless of this default.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Highlight negative profit', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_highlight_negative_profit" value="yes"' . checked( true, $neg_hi, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_highlight_negative_profit" value="no"' . checked( false, $neg_hi, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'Applies emphasis styling to negative gross profit cells in Order Profit and Product Profitability.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Currency defaults (Batch Intake)', 'wc-inventory-overview' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Only the default purchase currency is stored here. Exchange rates for automatic prefills live in Exchange Rate History below. Each applied batch stores its own currency and rate; changing settings or history does not alter past batches.', 'wc-inventory-overview' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wc-io-def-purchase-currency">' . esc_html__( 'Default purchase currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_default_purchase_currency" id="wc-io-def-purchase-currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $cc ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $cc ),
				selected( $cc, $def_cur, false )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Batch Intake line costs are entered in this currency unless you change it on the batch form.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'wc-inventory-overview' ) );
		echo '</form>';

		$this->render_exchange_rate_history_section();

		$this->render_settings_danger_zone();
	}

	/**
	 * Settings: Exchange Rate History (separate forms; not nested in Save settings).
	 */
	protected function render_exchange_rate_history_section() {
		$form_action = admin_url( 'admin-post.php' );
		$rates       = WC_Inventory_Overview_Exchange_Rates::list_rates( 500 );

		echo '<div class="wc-io-exchange-rate-history" style="max-width:920px;margin-top:28px;">';
		echo '<h3>' . esc_html__( 'Exchange Rate History', 'wc-inventory-overview' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Exchange Rate History is the authoritative source for automatic Batch Intake FX prefills. Applied batches keep their stored rate; editing this list does not change past batches.', 'wc-inventory-overview' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-fx-add-form" style="margin-top:16px;padding:12px 0;border-top:1px solid #dcdcde;">';
		echo '<input type="hidden" name="action" value="wc_io_add_exchange_rate" />';
		wp_nonce_field( 'wc_io_add_exchange_rate', '_wc_io_add_exchange_rate_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wc-io-fx-from">' . esc_html__( 'From currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_fx_from" id="wc-io-fx-from">';
		echo '<option value="USD">USD</option>';
		echo '<option value="SEK">SEK</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-to">' . esc_html__( 'To currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select id="wc-io-fx-to" disabled="disabled">';
		echo '<option value="EUR" selected="selected">EUR</option>';
		echo '</select>';
		echo '<input type="hidden" name="wc_io_fx_to" value="EUR" />';
		echo '<p class="description">' . esc_html__( 'Only EUR is supported as the target currency.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-rate">' . esc_html__( 'Exchange rate', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="small-text" name="wc_io_fx_rate" id="wc-io-fx-rate" inputmode="decimal" value="" required />';
		echo '<p class="description">' . esc_html__( 'Multiply one unit of the from-currency by this value to get EUR. Must be greater than zero.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-date">' . esc_html__( 'Rate date', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="date" name="wc_io_fx_date" id="wc-io-fx-date" value="' . esc_attr( wp_date( 'Y-m-d', null, wp_timezone() ) ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-note">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="wc_io_fx_note" id="wc-io-fx-note" maxlength="500" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Add rate', 'wc-inventory-overview' ), 'secondary', 'wc_io_fx_add_submit', false );
		echo '</form>';

		echo '<h4 style="margin-top:20px;">' . esc_html__( 'Recorded rates', 'wc-inventory-overview' ) . '</h4>';
		if ( empty( $rates ) ) {
			echo '<p class="description">' . esc_html__( 'No rates yet. Add one above.', 'wc-inventory-overview' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:920px;"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Date', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Rate', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Created by', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Actions', 'wc-inventory-overview' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rates as $r ) {
				$rid   = (int) $r['id'];
				$uid   = (int) $r['user_id'];
				$uobj  = $uid > 0 ? get_userdata( $uid ) : false;
				$ulab  = $uobj ? $uobj->display_name : __( '—', 'wc-inventory-overview' );
				$note  = isset( $r['source_note'] ) && null !== $r['source_note'] ? (string) $r['source_note'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( (string) $r['rate_date'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['from_currency'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['to_currency'] ) . '</td>';
				echo '<td>' . esc_html( wc_format_decimal( (string) $r['exchange_rate'], 8 ) ) . '</td>';
				echo '<td>' . esc_html( $note ) . '</td>';
				echo '<td>' . esc_html( $ulab ) . '</td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( $form_action ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this exchange rate?', 'wc-inventory-overview' ) ) . '\');">';
				echo '<input type="hidden" name="action" value="wc_io_delete_exchange_rate" />';
				echo '<input type="hidden" name="wc_io_fx_delete_id" value="' . esc_attr( (string) $rid ) . '" />';
				wp_nonce_field( 'wc_io_delete_exchange_rate_' . $rid, '_wc_io_delete_fx_nonce' );
				submit_button( __( 'Delete', 'wc-inventory-overview' ), 'delete small', 'wc_io_fx_del_' . $rid, false );
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/**
	 * Danger Zone: preview / apply reset of plugin analytics tables and snapshot metas.
	 */
	protected function render_settings_danger_zone() {
		$form_action = admin_url( 'admin-post.php' );
		$rst_token   = isset( $_GET['wc_io_rst'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wc_io_rst'] ) ) : '';
		$preview     = '' !== $rst_token ? WC_Inventory_Overview_Data_Reset::get_preview( $rst_token ) : null;

		echo '<div class="wc-io-danger-zone" style="max-width:920px;margin-top:32px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fcf0f1;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Danger Zone', 'wc-inventory-overview' ) . '</h3>';
		echo '<div class="notice notice-warning" style="margin:12px 0;border-left-width:4px;max-width:none;"><p style="margin:0.65em 0;font-size:14px;line-height:1.5;">';
		echo esc_html__( 'Reset tools delete plugin reporting and audit data only. They do not undo WooCommerce stock changes, delete orders, delete products, or reverse inventory adjustments.', 'wc-inventory-overview' );
		echo '</p></div>';
		echo '<p class="description">' . esc_html__( 'Choose a date scope and reset options, then run Preview Reset and review counts before applying.', 'wc-inventory-overview' ) . '</p>';

		if ( null !== $preview ) {
			$this->render_danger_zone_apply_form( $rst_token, $preview );
		} else {
			if ( '' !== $rst_token ) {
				echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>' . esc_html__( 'That preview link expired or is invalid. Configure the reset again and run Preview Reset.', 'wc-inventory-overview' ) . '</p></div>';
			}
			$this->render_danger_zone_preview_form( $form_action );
		}

		echo '</div>';
	}

	/**
	 * @param string $form_action admin-post.php URL.
	 */
	protected function render_danger_zone_preview_form( $form_action ) {
		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-danger-preview-form" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="wc_io_danger_reset_preview" />';
		wp_nonce_field( 'wc_io_danger_reset_preview', '_wc_io_danger_reset_preview_nonce' );

		echo '<h4>' . esc_html__( 'Date scope', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Date scope', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_24H ) . '" /> ' . esc_html__( 'Last 24 hours', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_7D ) . '" checked="checked" /> ' . esc_html__( 'Last 7 days', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_CUSTOM ) . '" id="wc-io-danger-scope-custom" /> ' . esc_html__( 'Custom date range', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_ALL ) . '" /> ' . esc_html__( 'All time', 'wc-inventory-overview' ) . '</label>';
		echo '</fieldset>';

		echo '<p id="wc-io-danger-custom-dates" class="wc-io-danger-custom-dates" style="display:none;margin-top:8px;">';
		echo '<label for="wc-io-danger-df">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="date" id="wc-io-danger-df" name="wc_io_reset_date_from" /> ';
		echo '<label for="wc-io-danger-dt">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="date" id="wc-io-danger-dt" name="wc_io_reset_date_to" />';
		echo '</p>';

		echo '<h4 style="margin-top:16px;">' . esc_html__( 'Reset options', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Reset options', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="checkbox" name="wc_io_reset_movements" value="1" /> ' . esc_html__( 'Clear inventory movements', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_batches" value="1" /> ' . esc_html__( 'Clear purchase batch history (batches, lines, landed costs)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_order_snapshots" value="1" /> ' . esc_html__( 'Clear order profit snapshots (order line item meta)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_pp_snapshots" value="1" /> ' . esc_html__( 'Clear product profitability snapshots (same line item meta as Order Profit)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_all" value="1" id="wc-io-danger-reset-all" /> ' . esc_html__( 'Clear all plugin analytics / test data (selects every option above, plus optional items below when checked)', 'wc-inventory-overview' ) . '</label>';
		echo '</fieldset>';

		echo '<h4 style="margin-top:16px;">' . esc_html__( 'Optional removals', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Optional removals', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="checkbox" name="wc_io_reset_shipping_meta" value="1" /> ' . esc_html__( 'Also remove per-order “Actual shipping cost” meta (_wc_io_actual_shipping_cost) for orders in the date scope', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_product_cost_meta" value="1" id="wc-io-danger-reset-product-cost" /> ' . esc_html__( 'Reset product costing meta (_wc_io_average_unit_cost, _wc_io_inventory_value) on all simple products and variations (ignores date scope)', 'wc-inventory-overview' ) . '</label>';
		echo '<p class="description" style="margin:10px 0 0 1.75em;max-width:52em;">' . esc_html__( 'Resetting product costing meta removes current average unit cost and inventory value. This does not change stock quantity.', 'wc-inventory-overview' ) . '</p>';
		echo '</fieldset>';

		echo '<p style="margin-top:16px;">';
		submit_button( __( 'Preview Reset', 'wc-inventory-overview' ), 'secondary', 'wc_io_danger_preview_submit', false );
		echo '</p>';
		echo '</form>';

		echo '<script>
(function(){
	var c=document.getElementById("wc-io-danger-scope-custom");
	var w=document.getElementById("wc-io-danger-custom-dates");
	var all=document.getElementById("wc-io-danger-reset-all");
	function sync(){if(!w)return;w.style.display=c&&c.checked?"block":"none";}
	if(c){c.addEventListener("change",sync);document.querySelectorAll(\'input[name="wc_io_reset_date_scope"]\').forEach(function(r){r.addEventListener("change",sync);});sync();}
	if(all){all.addEventListener("change",function(){if(all.checked){document.querySelectorAll(".wc-io-danger-preview-form input[type=checkbox]").forEach(function(x){if(x!==all&&x.name!=="wc_io_reset_shipping_meta"&&x.name!=="wc_io_reset_product_cost_meta"){x.checked=true;}});}});}
})();
</script>';
	}

	/**
	 * @param string               $token   Preview token.
	 * @param array<string, mixed> $preview Payload + counts from transient.
	 */
	protected function render_danger_zone_apply_form( $token, array $preview ) {
		$form_action = admin_url( 'admin-post.php' );
		$payload     = $preview['payload'];
		$counts      = $preview['counts'];
		$t           = $payload['targets'];

		echo '<div class="notice notice-info inline" style="margin:12px 0;"><p><strong>' . esc_html__( 'Preview results — review counts before applying.', 'wc-inventory-overview' ) . '</strong></p></div>';
		echo '<ul class="wc-io-danger-preview-list" style="list-style:disc;margin-left:1.25em;">';
		if ( ! empty( $t['movements'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: row count */ __( 'Inventory movements to delete: %d', 'wc-inventory-overview' ), (int) $counts['movements'] ) ) . '</li>';
		}
		if ( ! empty( $t['batches'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: 1: batches, 2: lines, 3: costs */ __( 'Purchase batches: %1$d · Lines: %2$d · Landed cost rows: %3$d', 'wc-inventory-overview' ), (int) $counts['purchase_batches'], (int) $counts['purchase_batch_lines'], (int) $counts['purchase_batch_costs'] ) ) . '</li>';
		}
		if ( ! empty( $t['line_snapshots'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Order line snapshot meta rows to delete: %d', 'wc-inventory-overview' ), (int) $counts['order_line_snapshot_meta'] ) ) . '</li>';
		}
		if ( ! empty( $t['shipping_meta'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Order shipping meta rows to delete: %d', 'wc-inventory-overview' ), (int) $counts['order_shipping_meta'] ) ) . '</li>';
		}
		if ( ! empty( $t['product_cost_meta'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Product costing meta rows to delete: %d (all products/variations)', 'wc-inventory-overview' ), (int) $counts['product_cost_meta_rows'] ) ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-danger-apply-form" style="margin-top:16px;padding-top:12px;border-top:1px solid #dcdcde;">';
		echo '<input type="hidden" name="action" value="wc_io_danger_reset_apply" />';
		echo '<input type="hidden" name="wc_io_reset_preview_token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( 'wc_io_danger_reset_apply', '_wc_io_danger_reset_apply_nonce' );

		echo '<p><label><input type="checkbox" name="wc_io_danger_confirm" value="1" required /> ';
		echo '<span class="description">' . esc_html__( 'I understand this will permanently delete the listed plugin data and cannot be undone.', 'wc-inventory-overview' ) . '</span></label></p>';
		echo '<p><label for="wc-io-danger-type">' . esc_html__( 'Type RESET to confirm', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="wc_io_danger_type_confirm" id="wc-io-danger-type" value="" autocomplete="off" required /></p>';

		submit_button( __( 'Apply Reset', 'wc-inventory-overview' ), 'primary', 'wc_io_danger_apply_submit', false );
		echo '</form>';

		echo '<p style="margin-top:12px;"><a class="button" href="' . esc_url( $this->admin_url_tab( self::TAB_SETTINGS ) ) . '">' . esc_html__( 'Cancel and start over', 'wc-inventory-overview' ) . '</a></p>';
	}

	/**
	 * Dashboard date range from request (GET) with defaults from plugin settings.
	 *
	 * @return array{date_from:string,date_to:string}
	 */
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

	/**
	 * Dashboard: low-stock table and quick links to hub tabs.
	 *
	 * @param array<string, mixed> $summary_base Base filters for low-stock lines (same as overview summary).
	 */
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
					'label'   => __( 'Restock / Add Inventory', 'wc-inventory-overview' ),
					'url'     => $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_QUICK ) ) . '#wc-io-restock-section',
					'dashicon' => 'dashicons-plus-alt',
				),
				array(
					'label'    => __( 'Adjust Product Cost', 'wc-inventory-overview' ),
					'url'      => $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_ADJUST ) ) . '#wc-io-cost-adjustment-section',
					'dashicon' => 'dashicons-admin-generic',
				),
				array(
					'label'    => __( 'View Order Profit', 'wc-inventory-overview' ),
					'url'      => $this->admin_url_tab( self::TAB_ORDER_PROFIT ),
					'dashicon' => 'dashicons-money-alt',
				),
				array(
					'label'    => __( 'View Product Profitability', 'wc-inventory-overview' ),
					'url'      => $this->admin_url_tab( self::TAB_PRODUCT_PROFITABILITY ),
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

	/**
	 * Dashboard tab: KPIs (live metrics) and Chart.js charts.
	 */
	protected function render_dashboard_panel() {
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

		$na = "\u{2014}";
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
			$accent        = isset( $kpi['accent'] ) ? sanitize_key( (string) $kpi['accent'] ) : 'neutral';
			$dashicon      = isset( $kpi['dashicon'] ) ? preg_replace( '/[^a-z0-9\-]/', '', (string) $kpi['dashicon'] ) : 'dashicons-chart-line';
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
				$this->render_dashboard_panel();
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
				$this->render_settings_panel();
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
		wp_enqueue_script(
			'wc-io-batch-intake',
			plugins_url( 'assets/batch-intake.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-batch-intake',
			'wcIoBatchIntake',
			array(
				'ajaxUrl'                 => admin_url( 'admin-ajax.php' ),
				'nonce'                   => wp_create_nonce( 'wc_io_batch_preview' ),
				'exchangeRateNonce'       => wp_create_nonce( 'wc_io_get_exchange_rate' ),
				'currencyEur'             => WC_Inventory_Overview_Settings::CURRENCY_EUR,
				'currencyUsd'             => WC_Inventory_Overview_Settings::CURRENCY_USD,
				'currencySek'             => WC_Inventory_Overview_Settings::CURRENCY_SEK,
				'defaultPurchaseCurrency' => WC_Inventory_Overview_Settings::get_default_purchase_currency(),
				'siteTodayYmd'            => wp_date( 'Y-m-d', null, wp_timezone() ),
				'lineSubtotalHeadingTpl'  => __( 'Line subtotal (%s)', 'wc-inventory-overview' ),
				'strings'                 => array(
					'previewError'           => __( 'Could not load batch preview.', 'wc-inventory-overview' ),
					'loading'                => __( 'Loading preview…', 'wc-inventory-overview' ),
					'previewBusy'            => __( 'Checking…', 'wc-inventory-overview' ),
					'rateHintHistory'        => __( 'Using historical rate from %s.', 'wc-inventory-overview' ),
					'rateHintNoHistory'      => __( 'No historical exchange rate found for this date. Please enter a manual rate.', 'wc-inventory-overview' ),
					'rateHintEur'            => __( 'EUR always uses 1.', 'wc-inventory-overview' ),
					'rateHintManual'         => __( 'Manual override.', 'wc-inventory-overview' ),
					'rateHintAjaxError'      => __( 'Could not load rate from server.', 'wc-inventory-overview' ),
				),
			)
		);
		wp_add_inline_script(
			'wc-enhanced-select',
			'jQuery(function($){ $(document.body).trigger("wc-enhanced-select-init"); });',
			'after'
		);
	}

	/**
	 * Settings tab: default shipping FX helpers (Exchange Rate History + AJAX, same as Batch Intake).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_settings_shipping_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( self::TAB_SETTINGS !== $this->get_requested_tab() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);
		wp_enqueue_script(
			'wc-io-settings-shipping-fx',
			plugins_url( 'assets/settings-shipping-fx.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-settings-shipping-fx',
			'wcIoSettingsShipping',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'exchangeRateNonce' => wp_create_nonce( 'wc_io_get_exchange_rate' ),
				'currencyEur'       => WC_Inventory_Overview_Settings::CURRENCY_EUR,
				'siteTodayYmd'      => wp_date( 'Y-m-d', null, wp_timezone() ),
				'strings'           => array(
					'rateHintHistory'   => __( 'Using historical rate from %s.', 'wc-inventory-overview' ),
					'rateHintNoHistory' => __( 'No historical exchange rate found for this date. Please enter a manual rate.', 'wc-inventory-overview' ),
					'rateHintEur'       => __( 'EUR always uses 1.', 'wc-inventory-overview' ),
					'rateHintAjaxError' => __( 'Could not load rate from server.', 'wc-inventory-overview' ),
				),
			)
		);
	}

	/**
	 * AJAX: authoritative batch preview (Stage 1 — no DB writes).
	 */
	public function ajax_batch_preview() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to preview batches.', 'wc-inventory-overview' ),
				),
				403
			);
		}
		check_ajax_referer( 'wc_io_batch_preview', 'nonce' );

		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$result = WC_Inventory_Overview_Batch_Intake_Service::build_preview_from_post( $post );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
				)
			);
		}

		$html = WC_Inventory_Overview_Batch_Intake_Service::render_preview_markup( $result );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * AJAX: lookup FX to EUR for Batch Intake prefill (history only; no Settings fallback).
	 */
	public function ajax_get_exchange_rate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to load exchange rates.', 'wc-inventory-overview' ),
				),
				403
			);
		}
		check_ajax_referer( 'wc_io_get_exchange_rate', 'nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$cur  = isset( $post['currency'] ) ? strtoupper( sanitize_text_field( (string) $post['currency'] ) ) : '';
		$date = isset( $post['date'] ) ? sanitize_text_field( (string) $post['date'] ) : '';

		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $cur ) {
			wp_send_json_success(
				array(
					'rate'      => 1.0,
					'source'    => 'eur',
					'rate_date' => null,
				)
			);
		}

		$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $cur, $date );
		if ( is_wp_error( $lk ) ) {
			wp_send_json_error(
				array(
					'message' => $lk->get_error_message(),
					'code'    => $lk->get_error_code(),
				)
			);
		}

		wp_send_json_success(
			array(
				'rate'      => (float) $lk['rate'],
				'source'    => (string) $lk['source'],
				'rate_date' => $lk['rate_date'],
			)
		);
	}

	/**
	 * Process batch intake apply (admin-post).
	 */
	public function handle_batch_apply_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_batch_apply' );

		$redirect = $this->admin_url_tab( self::TAB_RESTOCK, array( 'restock_view' => self::RESTOCK_VIEW_BATCH ) ) . '#wc-io-batch-intake-section';

		$post   = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$result = WC_Inventory_Overview_Batch_Intake_Service::apply_batch_from_post( $post );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'wc_io_batch_msg' => 'error',
						'wc_io_batch_err' => rawurlencode( $result->get_error_message() ),
					),
					$redirect
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'wc_io_batch_msg' => 'success',
					'wc_io_batch_id'  => (int) $result,
				),
				$redirect
			)
		);
		exit;
	}

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

		$qty   = isset( $_POST['wc_io_qty'] ) ? wc_stock_amount( wp_unslash( $_POST['wc_io_qty'] ) ) : 0;
		$cost  = isset( $_POST['wc_io_unit_cost'] ) ? wc_format_decimal( wp_unslash( $_POST['wc_io_unit_cost'] ), 6 ) : '';
		$sup   = isset( $_POST['wc_io_supplier'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_supplier'] ) ) : '';
		$note  = isset( $_POST['wc_io_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_io_note'] ) ) : '';

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

		if ( isset( $_GET['wc_io_batch_msg'] ) ) {
			$code = sanitize_key( wp_unslash( $_GET['wc_io_batch_msg'] ) );
			if ( 'success' === $code ) {
				$bid = isset( $_GET['wc_io_batch_id'] ) ? absint( wp_unslash( $_GET['wc_io_batch_id'] ) ) : 0;
				if ( $bid > 0 ) {
					$batch_notice = sprintf(
						/* translators: %d: batch ID */
						__( 'Batch applied successfully. Batch ID %d. Stock, weighted average unit cost, and inventory value were updated for each line.', 'wc-inventory-overview' ),
						$bid
					);
				} else {
					$batch_notice = __( 'Batch applied successfully. Stock, weighted average unit cost, and inventory value were updated for each line.', 'wc-inventory-overview' );
				}
				$mov_url = $this->admin_url_tab( self::TAB_MOVEMENTS );
				echo '<div class="notice notice-success is-dismissible wc-io-batch-notice"><p>';
				echo esc_html( $batch_notice ) . ' ';
				echo '<a href="' . esc_url( $mov_url ) . '">' . esc_html__( 'Review in Inventory Movements', 'wc-inventory-overview' ) . '</a>';
				echo '</p></div>';
			} elseif ( 'error' === $code && ! empty( $_GET['wc_io_batch_err'] ) ) {
				$err = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_batch_err'] ) ) );
				echo '<div class="notice notice-error is-dismissible wc-io-batch-notice"><p><strong>' . esc_html__( 'Batch was not applied', 'wc-inventory-overview' ) . '</strong></p>';
				echo '<p>' . esc_html__( 'Nothing was saved. Fix the issue below and try again.', 'wc-inventory-overview' ) . '</p>';
				echo '<p class="wc-io-batch-notice__detail">' . esc_html( $err ) . '</p></div>';
			}
		}

		$form_action = esc_url( admin_url( 'admin-post.php' ) );

		echo '<div class="wc-io-restock-panel wc-io-wrap">';
		$this->render_restock_subnav();

		$sub = $this->get_restock_subview();
		if ( self::RESTOCK_VIEW_BATCH === $sub ) {
			WC_Inventory_Overview_Batch_Intake_UI::render_panel( $form_action );
		} elseif ( self::RESTOCK_VIEW_QUICK === $sub ) {
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

		$filters = WC_Inventory_Overview_Order_Profit_Query::get_filters_from_request();
		$all_st  = array_keys( wc_get_order_statuses() );
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
				$af = WC_Inventory_Overview_Costing::get_average_float( $product );
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
			'search'            => $search,
			'category_tt_id'    => $tt_id,
			'stock_status'      => $stock_args,
			'exclude_private'   => WC_Inventory_Overview_Repository::request_excludes_private(),
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
