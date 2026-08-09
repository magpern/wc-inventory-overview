<?php
/**
 * Suppliers list table (WP_List_Table).
 *
 * M12 adds read-only Observed Lead Time and On-Time Rate columns, populated
 * from WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk()
 * once per prepare_items() for the current page (INV-M12-1 / INV-M12-2).
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
	 * Per-page performance stats from Supplier_Lead_Time_Service::get_stats_bulk(),
	 * keyed by supplier ID. Populated exactly once in prepare_items().
	 *
	 * @var array<int,array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int}>
	 */
	private $performance_stats = array();

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
			'name'                 => __( 'Name', 'wc-inventory-overview' ),
			'default_currency'     => __( 'Default Currency', 'wc-inventory-overview' ),
			'default_lead_time'    => __( 'Lead Time (configured)', 'wc-inventory-overview' ),
			'observed_lead_time'   => __( 'Observed Lead Time', 'wc-inventory-overview' ),
			'on_time_rate'         => __( 'On-Time Rate', 'wc-inventory-overview' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * Observed Lead Time and On-Time Rate are intentionally not sortable
	 * (docs/milestones/m12-implementation-plan.md §6 non-goal #2).
	 */
	public function get_sortable_columns() {
		return array(
			'name' => array( 'name', false ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * Loads one page of suppliers, then fetches M9/M11 performance statistics
	 * for those IDs with a single get_stats_bulk() call (INV-M12-2).
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

		$page_ids = array();
		foreach ( $suppliers as $supplier ) {
			$id = isset( $supplier['id'] ) ? (int) $supplier['id'] : 0;
			if ( $id > 0 ) {
				$page_ids[] = $id;
			}
		}

		// Exactly one bulk call per prepare_items(), including the empty-page
		// case (get_stats_bulk([]) returns without issuing SQL).
		$grace_days              = WC_Inventory_Overview_PO_Delay::grace_days_from_option();
		$this->performance_stats = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_bulk( $page_ids, $grace_days );

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
		$actions = $this->get_supplier_row_actions( $item );
		return sprintf(
			'<strong><a href="%s">%s</a></strong> %s',
			esc_url( add_query_arg( array(
				'page'        => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
				'tab'         => WC_Inventory_Overview_Purchasing_Page::TAB_SUPPLIERS,
				'action'      => 'edit',
				'supplier_id' => $item['id'],
			), admin_url( 'admin.php' ) ) ),
			esc_html( $item['name'] ),
			$this->row_actions( $actions )
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
	 * Render Observed Lead Time column (M12).
	 *
	 * Presentation-only: thresholds and values come from
	 * Supplier_Lead_Time_Service (same policy as the detail panel).
	 *
	 * @param array $item Supplier row.
	 * @return string
	 */
	public function column_observed_lead_time( $item ) {
		$stats = $this->stats_for_item( $item );
		if ( ! WC_Inventory_Overview_Supplier_Lead_Time_Service::is_observed_value_usable( $stats ) ) {
			return $this->insufficient_data_cell(
				__( 'Not enough observed lead-time data yet.', 'wc-inventory-overview' )
			);
		}

		$days = (int) round( (float) $stats['average_days'] );
		return esc_html(
			sprintf(
				/* translators: %d: average observed lead time in days, rounded. */
				_n( '%d day', '%d days', $days, 'wc-inventory-overview' ),
				$days
			)
		);
	}

	/**
	 * Render On-Time Rate column (M12).
	 *
	 * @param array $item Supplier row.
	 * @return string
	 */
	public function column_on_time_rate( $item ) {
		$stats = $this->stats_for_item( $item );
		if ( ! WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats ) ) {
			return $this->insufficient_data_cell(
				__( 'Not enough rated on-time data yet.', 'wc-inventory-overview' )
			);
		}

		$percent = (int) round( ( $stats['on_time_count'] / $stats['rated_order_count'] ) * 100 );
		return esc_html(
			sprintf(
				/* translators: %d: on-time delivery percentage. */
				__( '%d%%', 'wc-inventory-overview' ),
				$percent
			)
		);
	}

	/**
	 * Stats row for a list item (empty shape if missing).
	 *
	 * @param array $item Supplier row.
	 * @return array{has_data:bool,average_days:?float,fastest_days:?int,slowest_days:?int,sample_count:int,on_time_count:int,rated_order_count:int}
	 */
	private function stats_for_item( array $item ): array {
		$id = isset( $item['id'] ) ? (int) $item['id'] : 0;
		if ( $id > 0 && isset( $this->performance_stats[ $id ] ) ) {
			return $this->performance_stats[ $id ];
		}

		return array(
			'has_data'          => false,
			'average_days'      => null,
			'fastest_days'      => null,
			'slowest_days'      => null,
			'sample_count'      => 0,
			'on_time_count'     => 0,
			'rated_order_count' => 0,
		);
	}

	/**
	 * List-dense "—" cell with an optional tooltip.
	 *
	 * @param string $title Tooltip text.
	 * @return string
	 */
	private function insufficient_data_cell( string $title ): string {
		return '<span title="' . esc_attr( $title ) . '">—</span>';
	}

	/**
	 * Get row actions for the supplier.
	 *
	 * Named distinctly from WP_List_Table::row_actions() (protected) to avoid
	 * PHP visibility/signature fatals on PHP 8+.
	 */
	private function get_supplier_row_actions( $item ) {
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
			$archive_url = wp_nonce_url(
				admin_url(
					'admin-post.php?action=wc_io_supplier_archive&supplier_id=' . absint( $item['id'] )
				),
				'wc_io_supplier_archive_' . absint( $item['id'] ),
				'wc_io_supplier_archive_nonce'
			);
			$actions['archive'] = sprintf(
				'<a href="%s" onclick="return confirm(\'%s\')">%s</a>',
				esc_url( $archive_url ),
				esc_attr( __( 'Archive this supplier?', 'wc-inventory-overview' ) ),
				__( 'Archive', 'wc-inventory-overview' )
			);
		} else {
			$reactivate_url = wp_nonce_url(
				admin_url(
					'admin-post.php?action=wc_io_supplier_reactivate&supplier_id=' . absint( $item['id'] )
				),
				'wc_io_supplier_reactivate_' . absint( $item['id'] ),
				'wc_io_supplier_reactivate_nonce'
			);
			$actions['reactivate'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $reactivate_url ),
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

		$this->views();
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
