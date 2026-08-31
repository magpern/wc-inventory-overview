<?php
/**
 * Stock Adjustments admin controller (M27).
 *
 * HTTP/admin orchestration for the Stock Adjustments hub tab. Mutations are
 * delegated to WC_Inventory_Overview_Stock_Adjustment_Service.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustments tab controller.
 */
class WC_Inventory_Overview_Stock_Adjustment_Controller {

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
		WC_Inventory_Overview_Stock_Adjustment_Admin::init();
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wc_io_stock_adjustment_preview', array( $this, 'ajax_preview_line' ) );
	}

	/**
	 * Screen bootstrap for Stock Adjustments tab.
	 */
	public function on_load_screen() {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_STOCK_ADJUSTMENT ) ) {
			return;
		}
	}

	/**
	 * Render Stock Adjustments tab panel.
	 */
	public function render() {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_STOCK_ADJUSTMENT ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'You do not have permission to view Stock Adjustments.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}

		WC_Inventory_Overview_Stock_Adjustment_Admin::render_notices();

		$action = isset( $_GET['sa_action'] ) ? sanitize_key( wp_unslash( $_GET['sa_action'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		WC_Inventory_Overview_Stock_Adjustment_Admin::render_panel( $action );
	}

	/**
	 * Enqueue Stock Adjustments admin assets.
	 *
	 * @param string $hook_suffix Hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS !== WC_Inventory_Overview_Plugin::instance()->get_requested_tab() ) {
			return;
		}
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_STOCK_ADJUSTMENT ) ) {
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
			'wc-io-po-admin',
			plugins_url( 'assets/po-admin.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_enqueue_script(
			'wc-io-stock-adjustment',
			plugins_url( 'assets/stock-adjustment.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-io-po-admin' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-stock-adjustment',
			'wcIoStockAdjustment',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wc_io_sa_preview' ),
				'maxLines' => WC_Inventory_Overview_Stock_Adjustment_Service::MAX_LINES,
				'i18n'     => array(
					'previewLoading' => __( 'Loading preview…', 'wc-inventory-overview' ),
					'previewError'   => __( 'Could not load cost preview.', 'wc-inventory-overview' ),
					'maxLines'       => sprintf(
						/* translators: %d: max lines */
						__( 'A Stock Adjustment cannot contain more than %d lines.', 'wc-inventory-overview' ),
						WC_Inventory_Overview_Stock_Adjustment_Service::MAX_LINES
					),
				),
			)
		);
	}

	/**
	 * AJAX: preview outbound cost for one line.
	 */
	public function ajax_preview_line() {
		check_ajax_referer( 'wc_io_sa_preview', 'nonce' );
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_STOCK_ADJUSTMENT ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wc-inventory-overview' ) ), 403 );
		}

		$item_id = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$qty     = isset( $_POST['qty'] ) ? (float) wc_stock_amount( wp_unslash( $_POST['qty'] ) ) : 0.0;

		$result = WC_Inventory_Overview_Stock_Adjustment_Service::preview_line( $item_id, $qty );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}
}
