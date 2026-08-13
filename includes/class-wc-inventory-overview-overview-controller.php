<?php
/**
 * Inventory Overview admin controller — extracted from main Plugin class in M20.
 *
 * Handles: Inventory Overview tab (screen bootstrap, CSV export, bulk
 * product-status/visibility/stock-status mutation, inline-stock AJAX
 * mutation, rendering, asset enqueue). Bulk/inline mutations are inline
 * WC_Product calls, relocated verbatim -- this controller does not
 * introduce a new domain/service layer.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inventory Overview tab controller.
 */
class WC_Inventory_Overview_Overview_Controller {

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_overview_assets' ) );
		add_action( 'wp_ajax_wc_io_save_inline_stock', array( $this, 'ajax_save_inline_stock' ) );
	}

	/**
	 * Unified WooCommerce admin hub: screen options, CSV export, bulk, restock bootstrap.
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
			wp_get_referer() ? wp_get_referer() : WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_OVERVIEW )
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

		// M21 (BR-M21-4, INV-M21-5): the refreshed badge HTML may include a
		// Reorder Signal only for manage_woocommerce viewers, mirroring the
		// same capability gate already governing position_map elsewhere in
		// this class (prepare_items()) -- never built for edit_products-only
		// viewers, so render_status_badges() below sees an empty map (its
		// pre-M21, unchanged behavior) for them.
		$position_map = array();
		if ( current_user_can( 'manage_woocommerce' ) && $product->managing_stock() ) {
			$on_hand = self::product_on_hand_qty( $product );
			$position_map = $product->is_type( 'variation' )
				? WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( array(), array( $id => $on_hand ) )
				: WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( array( $id => $on_hand ), array() );
		}

		wp_send_json_success(
			array(
				'formatted'  => (string) wc_stock_amount( (float) $product->get_stock_quantity() ),
				'badgesHtml' => WC_Inventory_Overview_List_Table::render_status_badges( $product, $position_map ),
			)
		);
	}

	/**
	 * On Hand quantity for Position lookups (M21) -- 0.0 when unset, matching
	 * WC_Inventory_Overview_List_Table::item_on_hand_qty()'s own convention.
	 *
	 * @param WC_Product $item Product.
	 * @return float
	 */
	protected static function product_on_hand_qty( WC_Product $item ) {
		$qty = $item->get_stock_quantity();
		return ( null === $qty || '' === $qty ) ? 0.0 : (float) $qty;
	}

	/**
	 * Overview-only asset enqueue -- split out of the formerly-shared
	 * Plugin::enqueue_assets() (Dashboard/Overview). Registers its own copy
	 * of the shared 'wc-inventory-overview-admin' stylesheet handle
	 * (idempotent per handle, same precedent already established by
	 * Settings_Controller/Reporting_Controller); Plugin::enqueue_assets()
	 * keeps only the Dashboard branch.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue_overview_assets( $hook ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG !== $hook ) {
			return;
		}
		if ( WC_Inventory_Overview_Plugin::TAB_OVERVIEW !== WC_Inventory_Overview_Plugin::instance()->get_requested_tab() ) {
			return;
		}

		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);

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

		// M21 (BR-M21-6): "Needs Reorder" is the first capability-conditional
		// card in this method -- visible only to manage_woocommerce viewers
		// (mirroring the same gate already used elsewhere for
		// Incoming/Position -- BR-M21-4), inserted immediately after Low
		// stock. The six pre-existing cards above remain unconditionally
		// edit_products-visible, unchanged (INV-M21-6).
		if ( current_user_can( 'manage_woocommerce' ) ) {
			array_splice(
				$cards,
				5,
				0,
				array(
					array(
						'key'   => 'needs_reorder',
						'label' => __( 'Needs Reorder', 'wc-inventory-overview' ),
						'value' => $stats['needs_reorder'] ?? 0,
						'alert' => ( ( $stats['needs_reorder'] ?? 0 ) > 0 ),
					),
				)
			);
		}

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
	public function render() {
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
		echo '<input type="hidden" name="page" value="' . esc_attr( WC_Inventory_Overview_Plugin::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Plugin::TAB_OVERVIEW ) . '" />';
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
