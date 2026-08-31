<?php
/**
 * Stock Adjustments list table (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Stock Adjustments WP_List_Table.
 */
class WC_Inventory_Overview_Stock_Adjustments_List_Table extends WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'stock_adjustment',
				'plural'   => 'stock_adjustments',
				'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'adjustment_number' => __( 'Document', 'wc-inventory-overview' ),
			'kind'              => __( 'Kind', 'wc-inventory-overview' ),
			'status'            => __( 'Status', 'wc-inventory-overview' ),
			'note'              => __( 'Note', 'wc-inventory-overview' ),
			'posted_at'         => __( 'Posted', 'wc-inventory-overview' ),
			'updated_at'        => __( 'Updated', 'wc-inventory-overview' ),
		);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function get_sortable_columns() {
		return array(
			'adjustment_number' => array( 'adjustment_number', false ),
			'status'            => array( 'status', false ),
			'posted_at'         => array( 'posted_at', false ),
			'updated_at'        => array( 'updated_at', true ),
		);
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'wc_io_stock_adjustments_per_page', 20 );
		$paged    = isset( $_REQUEST['paged'] ) ? max( 1, absint( $_REQUEST['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'updated_at'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'DESC'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status   = isset( $_REQUEST['sa_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['sa_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$args = array(
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'offset'   => ( $paged - 1 ) * $per_page,
		);
		if ( $status && WC_Inventory_Overview_Stock_Adjustment_Lifecycle::is_valid( $status ) ) {
			$args['status'] = $status;
		}

		$this->items = WC_Inventory_Overview_Stock_Adjustments::list( $args );
		$total       = WC_Inventory_Overview_Stock_Adjustments::count( $args );

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
	 * @return array<string,string>
	 */
	protected function get_views() {
		$base    = WC_Inventory_Overview_Stock_Adjustment_Admin::list_url();
		$current = isset( $_REQUEST['sa_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['sa_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$views        = array();
		$all          = WC_Inventory_Overview_Stock_Adjustments::count();
		$views['all'] = $this->view_link( $base, '', __( 'All', 'wc-inventory-overview' ), $all, '' === $current );

		$labels = array(
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT  => __( 'Draft', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED => __( 'Posted', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_VOIDED => __( 'Voided', 'wc-inventory-overview' ),
		);
		foreach ( $labels as $key => $label ) {
			$count         = WC_Inventory_Overview_Stock_Adjustments::count( array( 'status' => $key ) );
			$views[ $key ] = $this->view_link( $base, $key, $label, $count, $current === $key );
		}

		return $views;
	}

	/**
	 * @param string $base    Base URL.
	 * @param string $status  Status filter.
	 * @param string $label   Label.
	 * @param int    $count   Count.
	 * @param bool   $current Current.
	 */
	protected function view_link( string $base, string $status, string $label, int $count, bool $current ): string {
		$url = $status ? add_query_arg( 'sa_status', $status, $base ) : remove_query_arg( 'sa_status', $base );
		$cls = $current ? 'current' : '';
		return sprintf(
			'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
			esc_url( $url ),
			esc_attr( $cls ),
			esc_html( $label ),
			$count
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 * @param string              $column_name Column.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'adjustment_number':
				$id     = (int) $item['id'];
				$status = (string) $item['status'];
				$url    = WC_Inventory_Overview_Stock_Adjustment_Lifecycle::is_editable( $status )
					? WC_Inventory_Overview_Stock_Adjustment_Admin::detail_url( $id )
					: WC_Inventory_Overview_Stock_Adjustment_Admin::view_url( $id );
				return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['adjustment_number'] ) . '</a></strong>';
			case 'kind':
				return esc_html( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::kind_label( (string) $item['adjustment_kind'] ) );
			case 'status':
				$status = (string) $item['status'];
				return '<span class="wc-io-sa-status wc-io-sa-status--' . esc_attr( $status ) . '">' . esc_html( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::status_label( $status ) ) . '</span>';
			case 'note':
				$note = trim( (string) ( $item['note'] ?? '' ) );
				return $note !== '' ? esc_html( wp_html_excerpt( $note, 80, '…' ) ) : '<span class="wc-io-muted">—</span>';
			case 'posted_at':
				$posted = (string) ( $item['posted_at'] ?? '' );
				return $posted ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $posted ) ) ) : '<span class="wc-io-muted">—</span>';
			case 'updated_at':
				$updated = (string) ( $item['updated_at'] ?? '' );
				return $updated ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $updated ) ) ) : '<span class="wc-io-muted">—</span>';
		}
		return '';
	}
}
