<?php
/**
 * Restock admin controller — extracted from main Plugin class in M20.
 *
 * Handles: Restock / Cost Adjustment tab (screen bootstrap, rendering,
 * both admin-post mutation handlers, and the read-only cost adjustment
 * preview AJAX). Mutation is delegated unchanged to
 * WC_Inventory_Overview_Restock_Service / WC_Inventory_Overview_Cost_Adjustment_Service
 * -- this controller is HTTP/admin orchestration only, never a new
 * mutation owner.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Restock / Cost Adjustment tab controller.
 */
class WC_Inventory_Overview_Restock_Controller {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_restock_assets' ) );
		add_action( 'admin_post_wc_io_restock', array( $this, 'handle_restock_post' ) );
		add_action( 'admin_post_wc_io_cost_adjustment', array( $this, 'handle_cost_adjustment_post' ) );
		add_action( 'wp_ajax_wc_io_get_cost_adjustment_preview', array( $this, 'ajax_get_cost_adjustment_preview' ) );
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
		if ( WC_Inventory_Overview_Plugin::TAB_RESTOCK !== WC_Inventory_Overview_Plugin::instance()->get_requested_tab() ) {
			return WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK;
		}
		$raw     = isset( $_GET['restock_view'] ) ? sanitize_key( wp_unslash( $_GET['restock_view'] ) ) : '';
		$allowed = array(
			WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK,
			WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST,
		);
		if ( in_array( $raw, $allowed, true ) ) {
			return $raw;
		}
		return WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK;
	}

	/**
	 * Secondary nav under Restock / Cost Adjustment. Batch Intake was removed
	 * from this nav in M6 — see the M6 implementation plan's Retirement
	 * strategy ("Removed (in M6)": the two admin entry points that reached it).
	 */
	protected function render_restock_subnav() {
		$cur   = $this->get_restock_subview();
		$items = array(
			WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK  => __( 'Quick Restock', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST => __( 'Cost Adjustment', 'wc-inventory-overview' ),
		);
		echo '<nav class="wc-io-restock-subnav" aria-label="' . esc_attr__( 'Restock views', 'wc-inventory-overview' ) . '">';
		$sep = '';
		foreach ( $items as $slug => $label ) {
			echo $sep;
			$url = esc_url( WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_RESTOCK, array( 'restock_view' => $slug ) ) );
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
	 * Restock tab bootstrap (assets load in enqueue_restock_assets).
	 */
	public function on_load_restock_screen() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
	}

	/**
	 * Assets for Inventory Restock (product search).
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_restock_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( WC_Inventory_Overview_Plugin::TAB_RESTOCK !== WC_Inventory_Overview_Plugin::instance()->get_requested_tab() ) {
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
	 * Process restock form (admin-post).
	 */
	public function handle_restock_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_restock' );

		$redirect = WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_RESTOCK, array( 'restock_view' => WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK ) );

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

		$redirect = WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_RESTOCK, array( 'restock_view' => WC_Inventory_Overview_Plugin::RESTOCK_VIEW_ADJUST ) );

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
	public function render() {
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
		if ( WC_Inventory_Overview_Plugin::RESTOCK_VIEW_QUICK === $sub ) {
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
}
