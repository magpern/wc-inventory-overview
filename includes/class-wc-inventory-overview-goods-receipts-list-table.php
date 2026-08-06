<?php
/**
 * Goods Receipts list table (M4).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Goods Receipts WP_List_Table.
 */
class WC_Inventory_Overview_Goods_Receipts_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'goods_receipt',
				'plural'   => 'goods_receipts',
				'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'receipt_number' => __( 'Receipt Number', 'wc-inventory-overview' ),
			'supplier'       => __( 'Supplier', 'wc-inventory-overview' ),
			'status'         => __( 'Status', 'wc-inventory-overview' ),
			'currency'       => __( 'Currency', 'wc-inventory-overview' ),
			'total'          => __( 'Total (EUR)', 'wc-inventory-overview' ),
			'created_at'     => __( 'Created', 'wc-inventory-overview' ),
			'updated_at'     => __( 'Updated', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'receipt_number' => array( 'receipt_number', false ),
			'supplier'       => array( 'supplier_name_snapshot', false ),
			'status'         => array( 'status', false ),
			'created_at'     => array( 'created_at', false ),
			'updated_at'     => array( 'updated_at', true ),
		);
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'wc_io_goods_receipts_per_page', 20 );
		$paged    = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_REQUEST['gr_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['gr_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $paged - 1 ) * $per_page,
		);
		if ( $status && WC_Inventory_Overview_Goods_Receipt_Lifecycle::is_valid( $status ) ) {
			$args['status'] = $status;
		}

		$this->items = WC_Inventory_Overview_Goods_Receipts::list( $args );
		$total       = WC_Inventory_Overview_Goods_Receipts::count( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Status filter views.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$base    = admin_url( 'admin.php?page=' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG . '&tab=' . WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS );
		$current = isset( $_REQUEST['gr_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['gr_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$views        = array();
		$all          = WC_Inventory_Overview_Goods_Receipts::count();
		$views['all'] = $this->view_link( $base, '', __( 'All', 'wc-inventory-overview' ), $all, '' === $current );

		$labels = array(
			WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT  => __( 'Draft', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED => __( 'Posted', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED => __( 'Voided', 'wc-inventory-overview' ),
		);
		foreach ( $labels as $key => $label ) {
			$count         = WC_Inventory_Overview_Goods_Receipts::count( array( 'status' => $key ) );
			$views[ $key ] = $this->view_link( $base, $key, $label, $count, $current === $key );
		}

		return $views;
	}

	/**
	 * Build a view link.
	 *
	 * @param string $base    Base URL.
	 * @param string $status  Status key or empty.
	 * @param string $label   Label.
	 * @param int    $count   Count.
	 * @param bool   $current Whether current.
	 */
	private function view_link( string $base, string $status, string $label, int $count, bool $current ): string {
		$url   = $status ? add_query_arg( 'gr_status', $status, $base ) : $base;
		$class = $current ? ' class="current"' : '';
		return sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			$class,
			esc_html( $label ),
			$count
		);
	}

	/**
	 * Display with search box.
	 */
	public function display() {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS ) . '" />';
		if ( ! empty( $_REQUEST['gr_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="gr_status" value="' . esc_attr( sanitize_key( wp_unslash( $_REQUEST['gr_status'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$this->search_box( __( 'Search Goods Receipts…', 'wc-inventory-overview' ), 'wc-io-gr' );
		parent::display();
		echo '</form>';
	}

	/**
	 * Receipt number column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_receipt_number( $item ) {
		$url = WC_Inventory_Overview_Goods_Receipt_Admin::detail_url( (int) $item['id'] );
		return sprintf(
			'<strong><a href="%s">%s</a></strong>',
			esc_url( $url ),
			esc_html( $item['receipt_number'] )
		);
	}

	/**
	 * Supplier column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_supplier( $item ) {
		$name = (string) ( $item['supplier_name_snapshot'] ?? '' );
		return $name ? esc_html( $name ) : '—';
	}

	/**
	 * Status column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_status( $item ) {
		$status = (string) $item['status'];
		return '<span class="wc-io-gr-status wc-io-gr-status--' . esc_attr( $status ) . '">' . esc_html( WC_Inventory_Overview_Goods_Receipt_Lifecycle::status_label( $status ) ) . '</span>';
	}

	/**
	 * Currency column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_currency( $item ) {
		return esc_html( (string) $item['currency'] );
	}

	/**
	 * Total column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_total( $item ) {
		return esc_html( number_format_i18n( (float) $item['receipt_total'], 2 ) );
	}

	/**
	 * Created-at column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_created_at( $item ) {
		return $item['created_at'] ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $item['created_at'] ) ) : '—';
	}

	/**
	 * Updated-at column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_updated_at( $item ) {
		return $item['updated_at'] ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $item['updated_at'] ) ) : '—';
	}

	/**
	 * Default column.
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column.
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
	}
}
