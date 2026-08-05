<?php
/**
 * Purchase Orders list table (M2-D).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Purchase Orders WP_List_Table.
 */
class WC_Inventory_Overview_Purchase_Orders_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'purchase_order',
				'plural'   => 'purchase_orders',
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
			'po_number'     => __( 'PO Number', 'wc-inventory-overview' ),
			'supplier'      => __( 'Supplier', 'wc-inventory-overview' ),
			'status'        => __( 'Status', 'wc-inventory-overview' ),
			'order_date'    => __( 'Order Date', 'wc-inventory-overview' ),
			'expected_date' => __( 'Expected Date', 'wc-inventory-overview' ),
			'delayed'       => __( 'Delay', 'wc-inventory-overview' ),
			'currency'      => __( 'Currency', 'wc-inventory-overview' ),
			'total'         => __( 'Total', 'wc-inventory-overview' ),
			'updated_at'    => __( 'Updated', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'po_number'     => array( 'po_number', false ),
			'supplier'      => array( 'supplier_name_snapshot', false ),
			'status'        => array( 'status', false ),
			'expected_date' => array( 'expected_date', false ),
			'updated_at'    => array( 'updated_at', true ),
		);
	}

	/**
	 * Prepare items.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'wc_io_purchase_orders_per_page', 20 );
		$paged    = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_REQUEST['po_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['po_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$delayed  = ( 'delayed' === $status );

		if ( $delayed ) {
			$status = '';
		}

		$args = array(
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $paged - 1 ) * $per_page,
		);
		if ( $status && WC_Inventory_Overview_PO_Statuses::is_valid( $status ) ) {
			$args['status'] = $status;
		}
		if ( $delayed ) {
			$args['delayed'] = true;
		}

		$this->items = WC_Inventory_Overview_Purchase_Orders::list( $args );
		$total       = WC_Inventory_Overview_Purchase_Orders::count( $args );

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
		$base    = admin_url( 'admin.php?page=' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG . '&tab=' . WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS );
		$current = isset( $_REQUEST['po_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['po_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$views        = array();
		$all          = WC_Inventory_Overview_Purchase_Orders::count();
		$views['all'] = $this->view_link( $base, '', __( 'All', 'wc-inventory-overview' ), $all, '' === $current );

		foreach ( WC_Inventory_Overview_PO_Statuses::labels() as $key => $label ) {
			$count         = WC_Inventory_Overview_Purchase_Orders::count( array( 'status' => $key ) );
			$views[ $key ] = $this->view_link( $base, $key, $label, $count, $current === $key );
		}

		$delayed_count    = WC_Inventory_Overview_Purchase_Orders::count( array( 'delayed' => true ) );
		$views['delayed'] = $this->view_link( $base, 'delayed', __( 'Delayed', 'wc-inventory-overview' ), $delayed_count, 'delayed' === $current );

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
		$url   = $status ? add_query_arg( 'po_status', $status, $base ) : $base;
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
		echo '<input type="hidden" name="tab" value="' . esc_attr( WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS ) . '" />';
		if ( ! empty( $_REQUEST['po_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="po_status" value="' . esc_attr( sanitize_key( wp_unslash( $_REQUEST['po_status'] ) ) ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$this->search_box( __( 'Search purchase orders…', 'wc-inventory-overview' ), 'wc-io-po' );
		parent::display();
		echo '</form>';
	}

	/**
	 * PO number column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_po_number( $item ) {
		$url     = WC_Inventory_Overview_PO_Admin::detail_url( (int) $item['id'] );
		$actions = $this->get_po_row_actions( $item );
		return sprintf(
			'<strong><a href="%s">%s</a></strong> %s',
			esc_url( $url ),
			esc_html( $item['po_number'] ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Supplier column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_supplier( $item ) {
		return esc_html( (string) $item['supplier_name_snapshot'] );
	}

	/**
	 * Status column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_status( $item ) {
		$status = (string) $item['status'];
		return '<span class="wc-io-po-status wc-io-po-status--' . esc_attr( $status ) . '">' . esc_html( WC_Inventory_Overview_PO_Statuses::label( $status ) ) . '</span>';
	}

	/**
	 * Order date column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_order_date( $item ) {
		return $item['order_date'] ? esc_html( (string) $item['order_date'] ) : '—';
	}

	/**
	 * Expected date column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_expected_date( $item ) {
		$date = $item['expected_date'] ? (string) $item['expected_date'] : '—';
		$conf = isset( $item['expected_confidence'] ) ? (string) $item['expected_confidence'] : '';
		if ( $conf && '—' !== $date ) {
			return esc_html( $date ) . ' <span class="description">(' . esc_html( $conf ) . ')</span>';
		}
		return esc_html( $date );
	}

	/**
	 * Delayed indicator column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_delayed( $item ) {
		$lines   = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( (int) $item['id'] );
		$delayed = WC_Inventory_Overview_PO_Delay::is_po_delayed(
			(string) $item['status'],
			$item['expected_date'] ?? null,
			(string) ( $item['expected_confidence'] ?? 'unknown' ),
			$lines,
			WC_Inventory_Overview_PO_Delay::grace_days_from_option()
		);
		if ( ! $delayed ) {
			return '—';
		}
		return '<span class="wc-io-po-delayed">' . esc_html__( 'Delayed', 'wc-inventory-overview' ) . '</span>';
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
	 * Line total column.
	 *
	 * @param array<string,mixed> $item Row.
	 */
	public function column_total( $item ) {
		$total = WC_Inventory_Overview_Purchase_Orders::line_total( (int) $item['id'] );
		return esc_html( number_format_i18n( $total, 2 ) );
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

	/**
	 * Row action links (GET view/edit; mutations via detail forms).
	 *
	 * @param array<string,mixed> $item Row.
	 * @return array<string,string>
	 */
	private function get_po_row_actions( array $item ): array {
		$id      = (int) $item['id'];
		$status  = (string) $item['status'];
		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( WC_Inventory_Overview_PO_Admin::detail_url( $id ) ),
				esc_html__( 'View', 'wc-inventory-overview' )
			),
		);
		if ( WC_Inventory_Overview_PO_Lifecycle::is_editable( $status ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( WC_Inventory_Overview_PO_Admin::detail_url( $id ) ),
				esc_html__( 'Edit', 'wc-inventory-overview' )
			);
		}
		return $actions;
	}
}
