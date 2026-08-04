<?php
/**
 * Suppliers list table (WP_List_Table).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Suppliers list table.
 */
class WC_Inventory_Overview_Suppliers_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'supplier',
			'plural'   => 'suppliers',
			'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
		) );
	}

	/**
	 * Get list of columns.
	 */
	public function get_columns() {
		return array(
			'name'              => __( 'Name', 'wc-inventory-overview' ),
			'default_currency'  => __( 'Default Currency', 'wc-inventory-overview' ),
			'default_lead_time' => __( 'Lead Time (configured)', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Get sortable columns.
	 */
	public function get_sortable_columns() {
		return array(
			'name' => array( 'name', false ),
		);
	}

	/**
	 * Prepare items for display.
	 */
	public function prepare_items() {
		$per_page   = $this->get_items_per_page( 'wc_io_suppliers_per_page', 20 );
		$paged      = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1;
		$orderby    = isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'name';
		$order      = isset( $_REQUEST['order'] ) ? sanitize_key( strtoupper( $_REQUEST['order'] ) ) : 'ASC';
		$search     = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$status     = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '';

		$offset = ( $paged - 1 ) * $per_page;

		$suppliers = WC_Inventory_Overview_Suppliers::list( array(
			'status'   => $status,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => $offset,
		) );

		$this->items = $suppliers;

		$total_items = WC_Inventory_Overview_Suppliers::count( array(
			'status' => $status,
			'search' => $search,
		) );

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => ceil( $total_items / $per_page ),
		) );
	}

	/**
	 * Render name column with edit link.
	 */
	public function column_name( $item ) {
		$actions = $this->row_actions( $item );
		return sprintf(
			'<strong><a href="%s">%s</a></strong> %s',
			esc_url( add_query_arg( array(
				'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
				'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
				'action'      => 'edit',
				'supplier_id' => $item['id'],
			), admin_url( 'admin.php' ) ) ),
			esc_html( $item['name'] ),
			$actions
		);
	}

	/**
	 * Render default_currency column.
	 */
	public function column_default_currency( $item ) {
		return esc_html( $item['default_currency'] );
	}

	/**
	 * Render default_lead_time column.
	 */
	public function column_default_lead_time( $item ) {
		return $item['default_lead_time_days'] ? esc_html( $item['default_lead_time_days'] ) . ' days' : '—';
	}

	/**
	 * Get row actions for the supplier.
	 */
	private function row_actions( $item ) {
		$actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array(
					'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
					'action'      => 'edit',
					'supplier_id' => $item['id'],
				), admin_url( 'admin.php' ) ) ),
				__( 'Edit', 'wc-inventory-overview' )
			),
		);

		if ( 'active' === $item['status'] ) {
			$actions['archive'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( add_query_arg( array(
					'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
					'action'      => 'archive',
					'supplier_id' => $item['id'],
				), admin_url( 'admin.php' ) ) ),
				esc_attr( __( 'Archive this supplier?', 'wc-inventory-overview' ) ),
				__( 'Archive', 'wc-inventory-overview' )
			);
		} else {
			$actions['reactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array(
					'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
					'action'      => 'reactivate',
					'supplier_id' => $item['id'],
				), admin_url( 'admin.php' ) ) ),
				__( 'Reactivate', 'wc-inventory-overview' )
			);
		}

		return $actions;
	}

	/**
	 * Display the table with views and filters.
	 */
	public function display() {
		echo '<div class="suppliers-filters">';
		$this->search_box( __( 'Search suppliers...', 'wc-inventory-overview' ), 's' );
		echo '</div>';

		echo '<hr class="wp-header-end">';

		parent::display();
	}

	/**
	 * Get views (Active/Archived tabs).
	 */
	protected function get_views() {
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : '';

		$active_count    = WC_Inventory_Overview_Suppliers::count( array( 'status' => 'active' ) );
		$archived_count  = WC_Inventory_Overview_Suppliers::count( array( 'status' => 'archived' ) );
		$all_count       = $active_count + $archived_count;

		$views = array(
			'all' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( remove_query_arg( 'status', add_query_arg( 'paged', 1 ) ) ),
				'' === $status ? 'current' : '',
				__( 'All', 'wc-inventory-overview' ),
				$all_count
			),
			'active' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( array( 'status' => 'active', 'paged' => 1 ) ) ),
				'active' === $status ? 'current' : '',
				__( 'Active', 'wc-inventory-overview' ),
				$active_count
			),
			'archived' => sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( add_query_arg( array( 'status' => 'archived', 'paged' => 1 ) ) ),
				'archived' === $status ? 'current' : '',
				__( 'Archived', 'wc-inventory-overview' ),
				$archived_count
			),
		);

		return $views;
	}
}
