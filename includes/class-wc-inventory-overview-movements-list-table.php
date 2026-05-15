<?php
/**
 * Admin list: inventory movement log (custom table).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for {$wpdb->prefix}wc_io_inventory_movements.
 */
class WC_Inventory_Overview_Movements_List_Table extends WP_List_Table {

	use WC_Inventory_Overview_Hub_List_Table_Column_Info;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wc-io-movement',
				'plural'   => 'wc-io-movements',
				'ajax'     => false,
				// Must match the Inventory & Profit admin screen so get_column_headers() runs our columns filter.
				'screen'   => 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG,
			)
		);
	}

	public function get_columns() {
		return array(
			'created_at'      => __( 'Date', 'wc-inventory-overview' ),
			'product'         => __( 'Product / variation', 'wc-inventory-overview' ),
			'movement_type'   => __( 'Movement type', 'wc-inventory-overview' ),
			'quantity_change' => __( 'Qty change', 'wc-inventory-overview' ),
			'stock'           => __( 'Stock', 'wc-inventory-overview' ),
			'avg_cost'        => __( 'Avg cost', 'wc-inventory-overview' ),
			'value_change'    => __( 'Value change', 'wc-inventory-overview' ),
			'user_id'         => __( 'User', 'wc-inventory-overview' ),
			'note'            => __( 'Note', 'wc-inventory-overview' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'created_at'      => array( 'created_at', true ),
			'movement_type'   => array( 'movement_type', false ),
			'quantity_change' => array( 'quantity_change', false ),
			'stock'           => array( 'new_stock', false ),
			'avg_cost'        => array( 'new_average_unit_cost', false ),
			'value_change'    => array( 'value_change', false ),
			'user_id'         => array( 'user_id', false ),
		);
	}

	public function no_items() {
		esc_html_e( 'No inventory movements found.', 'wc-inventory-overview' );
	}

	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'wc-io-movements-table';
		return $classes;
	}

	/**
	 * Outputs the main data row plus a hidden detail row for the full movement note.
	 *
	 * @param stdClass $item Row.
	 */
	public function single_row( $item ) {
		$mid = isset( $item->id ) ? (int) $item->id : 0;
		echo '<tr class="wc-io-mv-main"' . ( $mid ? ' data-wc-io-mv-id="' . esc_attr( (string) $mid ) . '"' : '' ) . '>';
		$this->single_row_columns( $item );
		echo '</tr>';

		$note = isset( $item->note ) ? (string) $item->note : '';
		if ( '' === $note ) {
			return;
		}

		list( $columns ) = $this->get_column_info();
		$colspan          = is_array( $columns ) ? count( $columns ) : 1;

		echo '<tr class="wc-io-mv-detail" id="wc-io-mv-detail-row-' . esc_attr( (string) $mid ) . '" hidden>';
		echo '<td colspan="' . (int) $colspan . '" class="wc-io-mv-detail-cell">';
		echo '<pre class="wc-io-mv-note-full">' . esc_html( $note ) . '</pre>';
		echo '</td></tr>';
	}

	/**
	 * Parse leading batch lines written by batch intake apply (English prefixes).
	 *
	 * @return array{batch_id: int|null, reference: string|null}
	 */
	protected static function parse_batch_note_preface( $note ) {
		$note = (string) $note;
		if ( '' === $note ) {
			return array(
				'batch_id'  => null,
				'reference' => null,
			);
		}
		$lines = preg_split( '/\r\n|\r|\n/', $note );
		$batch_id = null;
		$reference = null;
		if ( isset( $lines[0] ) && preg_match( '/^Batch ID:\s*(\d+)\s*$/', trim( $lines[0] ), $m ) ) {
			$batch_id = (int) $m[1];
		}
		if ( isset( $lines[1] ) && preg_match( '/^Reference:\s*(.*)$/', trim( $lines[1] ), $m2 ) ) {
			$reference = trim( (string) $m2[1] );
		}
		return array(
			'batch_id'  => $batch_id,
			'reference' => $reference,
		);
	}

	/**
	 * One-line summary for the Note column (full text stays in the expandable row + CSV).
	 *
	 * @param string $note Full note.
	 * @return string Plain text.
	 */
	protected static function format_note_summary( $note ) {
		$note = (string) $note;
		$pref = self::parse_batch_note_preface( $note );
		if ( null !== $pref['batch_id'] ) {
			$ref = $pref['reference'];
			if ( null === $ref || '' === $ref ) {
				$ref = '—';
			}
			return sprintf(
				/* translators: %d: batch numeric ID */
				__( 'Batch #%d · ', 'wc-inventory-overview' ),
				(int) $pref['batch_id']
			) . $ref;
		}
		foreach ( preg_split( '/\r\n|\r|\n/', $note ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				return wp_html_excerpt( $line, 72, '…' );
			}
		}
		return '';
	}

	/**
	 * @return array{type: string, orderby: string, order: string}
	 */
	public static function get_request_args() {
		$labels = WC_Inventory_Overview_Movements::movement_type_labels();
		$type   = isset( $_REQUEST['wc_io_mv_type'] ) ? sanitize_key( wp_unslash( $_REQUEST['wc_io_mv_type'] ) ) : '';
		if ( $type && ! isset( $labels[ $type ] ) ) {
			$type = '';
		}

		$orderby_request = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order           = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$orderby_map = self::orderby_sql_map();
		if ( ! isset( $orderby_map[ $orderby_request ] ) ) {
			$orderby_request = 'created_at';
		}

		return array(
			'type'    => $type,
			'orderby' => $orderby_request,
			'order'   => $order,
		);
	}

	/**
	 * Request orderby key => SQL ORDER BY expression (identifier-safe fragments only).
	 *
	 * @return array<string, string>
	 */
	protected static function orderby_sql_map() {
		return array(
			'created_at'            => 'created_at',
			'movement_type'         => 'movement_type',
			'quantity_change'       => 'quantity_change',
			'stock'                 => 'new_stock',
			'new_stock'             => 'new_stock',
			'old_stock'             => 'old_stock',
			'avg_cost'              => 'new_average_unit_cost',
			'new_average_unit_cost' => 'new_average_unit_cost',
			'old_average_unit_cost' => 'old_average_unit_cost',
			'user_id'               => 'user_id',
			'value_change'          => '(new_inventory_value - old_inventory_value)',
		);
	}

	/**
	 * @return array{where_sql: string, where_args: array<int, float|string>}
	 */
	protected static function build_where_sql( $type ) {
		if ( $type ) {
			return array(
				'where_sql'  => ' WHERE movement_type = %s ',
				'where_args' => array( $type ),
			);
		}

		return array(
			'where_sql'  => ' WHERE 1=1 ',
			'where_args' => array(),
		);
	}

	public function prepare_items() {
		global $wpdb;

		$table = WC_Inventory_Overview_Movements::table_name();
		$args  = self::get_request_args();

		$per_page = $this->get_items_per_page( 'wc_io_movements_per_page', 20 );
		$per_page = max( 1, min( 500, (int) $per_page ) );

		$where_parts = self::build_where_sql( $args['type'] );
		$orderby_sql = self::orderby_sql_map()[ $args['orderby'] ];
		$order_sql   = 'DESC' === $args['order'] ? 'DESC' : 'ASC';

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where_parts['where_sql']}";
		if ( $where_parts['where_args'] ) {
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_parts['where_args'] ) );
		} else {
			$total = (int) $wpdb->get_var( $count_sql );
		}

		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$paged       = isset( $_REQUEST['paged'] ) ? absint( wp_unslash( $_REQUEST['paged'] ) ) : 1;
		$paged       = max( 1, min( $paged, $total_pages ) );
		// WP_List_Table::set_pagination_args() calls get_pagenum() before _pagination_args exists; that
		// reads $_REQUEST['paged'] without clamping to this table's total_pages, which can trigger
		// wp_redirect()/exit or mismatch pagination vs rows. Keep superglobals aligned with the query.
		$_REQUEST['paged'] = $paged;
		$_GET['paged']     = $paged;
		$offset            = ( $paged - 1 ) * $per_page;

		$sql        = "SELECT * FROM {$table} {$where_parts['where_sql']} ORDER BY {$orderby_sql} {$order_sql} LIMIT %d OFFSET %d";
		$query_args = array_merge( $where_parts['where_args'], array( $per_page, $offset ) );

		$this->items = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );
		if ( ! is_array( $this->items ) ) {
			$this->items = array();
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
				'current'     => $paged,
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$labels  = WC_Inventory_Overview_Movements::movement_type_labels();
		$current = self::get_request_args()['type'];

		echo '<div class="alignleft actions wc-io-mv-filters">';
		echo '<label class="screen-reader-text" for="wc-io-mv-type">' . esc_html__( 'Filter by movement type', 'wc-inventory-overview' ) . '</label>';
		echo '<select name="wc_io_mv_type" id="wc-io-mv-type">';
		echo '<option value="">' . esc_html__( 'All movement types', 'wc-inventory-overview' ) . '</option>';
		foreach ( $labels as $slug => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $current, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'wc-inventory-overview' ), '', 'filter_action', false );

		$export_query = array(
			'page'             => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'              => WC_Inventory_Overview_Plugin::TAB_MOVEMENTS,
			'wc_io_mv_export'  => 'csv',
			'wc_io_mv_type'    => $current,
			'orderby'          => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '',
			'order'            => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : '',
		);
		$export_url = wp_nonce_url(
			add_query_arg( $export_query, admin_url( 'admin.php' ) ),
			'wc_io_movements_export_csv',
			'_wc_io_mv_export_nonce',
			false
		);
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download CSV (all matches)', 'wc-inventory-overview' ) . '</a>';
		echo '</div>';
	}

	protected function column_default( $item, $column_name ) {
		return '—';
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_created_at( $item ) {
		$t = isset( $item->created_at ) ? $item->created_at : '';
		if ( ! $t ) {
			return '—';
		}
		return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $t, true ) );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_product( $item ) {
		$pid = (int) $item->product_id;
		$vid = (int) $item->variation_id;
		$id  = $vid > 0 ? $vid : $pid;
		if ( ! $id ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return esc_html(
				sprintf(
					/* translators: %d: product or variation ID */
					__( 'Deleted product (ID %d)', 'wc-inventory-overview' ),
					$id
				)
			);
		}
		$name = $product->get_name();
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			if ( $parent_id ) {
				$parent = get_the_title( $parent_id );
				$attrs  = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
				$line   = $parent !== '' ? $parent . ' — ' . $name : $name;
				if ( $attrs ) {
					$line .= ' (' . $attrs . ')';
				}
				return esc_html( $line );
			}
		}
		return esc_html( $name );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_movement_type( $item ) {
		$labels = WC_Inventory_Overview_Movements::movement_type_labels();
		$slug   = isset( $item->movement_type ) ? (string) $item->movement_type : '';
		$label  = $labels[ $slug ] ?? $slug;
		return esc_html( $label );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_quantity_change( $item ) {
		return esc_html( wc_format_decimal( (float) $item->quantity_change, 4 ) );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_stock( $item ) {
		$old = esc_html( wc_format_decimal( (float) $item->old_stock, 4 ) );
		$new = esc_html( wc_format_decimal( (float) $item->new_stock, 4 ) );
		return '<span class="wc-io-mv-range wc-io-mv-range--nowrap"><span class="wc-io-mv-range-old">' . $old . '</span> <span class="wc-io-mv-range-sep" aria-hidden="true">→</span> <span class="wc-io-mv-range-new">' . $new . '</span></span>';
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_avg_cost( $item ) {
		$old_v = $item->old_average_unit_cost;
		$new_v = $item->new_average_unit_cost;
		$old_s = ( null === $old_v || '' === $old_v )
			? '<span class="wc-io-muted">—</span>'
			: esc_html( wc_format_decimal( (float) $old_v, 4 ) );
		$new_s = ( null === $new_v || '' === $new_v )
			? '<span class="wc-io-muted">—</span>'
			: esc_html( wc_format_decimal( (float) $new_v, 4 ) );
		return '<span class="wc-io-mv-range wc-io-mv-range--nowrap"><span class="wc-io-mv-range-old">' . $old_s . '</span> <span class="wc-io-mv-range-sep" aria-hidden="true">→</span> <span class="wc-io-mv-range-new">' . $new_s . '</span></span>';
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_value_change( $item ) {
		$delta = (float) $item->new_inventory_value - (float) $item->old_inventory_value;
		return wp_kses_post( wc_price( $delta ) );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_user_id( $item ) {
		$uid = (int) $item->user_id;
		if ( $uid <= 0 ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$user = get_userdata( $uid );
		if ( ! $user ) {
			return esc_html(
				sprintf(
					/* translators: %d: user ID */
					__( 'User #%d', 'wc-inventory-overview' ),
					$uid
				)
			);
		}
		return esc_html( $user->display_name );
	}

	/**
	 * @param stdClass $item Row.
	 */
	protected function column_note( $item ) {
		$note = isset( $item->note ) ? (string) $item->note : '';
		if ( '' === $note ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$mid     = isset( $item->id ) ? (int) $item->id : 0;
		$summary = self::format_note_summary( $note );
		if ( '' === $summary ) {
			$summary = '…';
		}
		$row_id = (string) $mid;
		$html   = '<div class="wc-io-mv-note-cell">';
		$html  .= '<span class="wc-io-mv-note-summary">' . esc_html( $summary ) . '</span> ';
		if ( $mid > 0 ) {
			$html .= sprintf(
				'<button type="button" class="button-link wc-io-mv-toggle-detail" aria-expanded="false" aria-controls="%1$s" data-wc-io-mv-row="%2$d">%3$s</button>',
				esc_attr( 'wc-io-mv-detail-row-' . $row_id ),
				$mid,
				esc_html__( 'Details', 'wc-inventory-overview' )
			);
		}
		$html .= '</div>';

		return wp_kses(
			$html,
			array(
				'div'    => array( 'class' => true ),
				'span'   => array( 'class' => true ),
				'button' => array(
					'type'              => true,
					'class'             => true,
					'aria-expanded'     => true,
					'aria-controls'     => true,
					'data-wc-io-mv-row' => true,
				),
			)
		);
	}

	/**
	 * Stream CSV for all rows matching current filters (no pagination cap).
	 *
	 * @param resource $out Output stream.
	 */
	public static function export_csv_to_stream( $out ) {
		global $wpdb;

		$table = WC_Inventory_Overview_Movements::table_name();
		$args  = self::get_request_args();

		$where_parts = self::build_where_sql( $args['type'] );
		$orderby_sql = self::orderby_sql_map()[ $args['orderby'] ];
		$order_sql   = 'DESC' === $args['order'] ? 'DESC' : 'ASC';

		$labels = WC_Inventory_Overview_Movements::movement_type_labels();

		fputcsv(
			$out,
			array(
				__( 'Date', 'wc-inventory-overview' ),
				__( 'Product / variation', 'wc-inventory-overview' ),
				__( 'Movement type', 'wc-inventory-overview' ),
				__( 'Qty change', 'wc-inventory-overview' ),
				__( 'Old stock', 'wc-inventory-overview' ),
				__( 'New stock', 'wc-inventory-overview' ),
				__( 'Old avg cost', 'wc-inventory-overview' ),
				__( 'New avg cost', 'wc-inventory-overview' ),
				__( 'Value change', 'wc-inventory-overview' ),
				__( 'User', 'wc-inventory-overview' ),
				__( 'Note', 'wc-inventory-overview' ),
			)
		);

		$batch = 500;
		$offset = 0;
		while ( true ) {
			$sql        = "SELECT * FROM {$table} {$where_parts['where_sql']} ORDER BY {$orderby_sql} {$order_sql} LIMIT %d OFFSET %d";
			$query_args = array_merge( $where_parts['where_args'], array( $batch, $offset ) );
			$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $query_args ) );
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $item ) {
				$line = self::csv_row_for_item( $item, $labels );
				fputcsv( $out, $line );
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
			$offset += $batch;
		}
	}

	/**
	 * @param stdClass               $item Row.
	 * @param array<string, string> $labels Movement type labels.
	 * @return array<int, string>
	 */
	protected static function csv_row_for_item( $item, array $labels ) {
		$slug = isset( $item->movement_type ) ? (string) $item->movement_type : '';
		$type = isset( $labels[ $slug ] ) ? $labels[ $slug ] : $slug;

		$pid = (int) $item->product_id;
		$vid = (int) $item->variation_id;
		$id  = $vid > 0 ? $vid : $pid;
		$prod_label = '';
		if ( $id ) {
			$product = wc_get_product( $id );
			if ( $product ) {
				$prod_label = $product->get_name();
				if ( $product->is_type( 'variation' ) ) {
					$parent_id = (int) $product->get_parent_id();
					if ( $parent_id ) {
						$parent = get_the_title( $parent_id );
						$attrs  = wp_strip_all_tags( wc_get_formatted_variation( $product, true, false, true ) );
						$prod_label = $parent !== '' ? $parent . ' — ' . $prod_label : $prod_label;
						if ( $attrs ) {
							$prod_label .= ' (' . $attrs . ')';
						}
					}
				}
			} else {
				$prod_label = sprintf( 'ID %d (deleted)', $id );
			}
		}

		$old_avg = ( null === $item->old_average_unit_cost || '' === $item->old_average_unit_cost )
			? ''
			: wc_format_decimal( (float) $item->old_average_unit_cost, 4 );
		$new_avg = ( null === $item->new_average_unit_cost || '' === $item->new_average_unit_cost )
			? ''
			: wc_format_decimal( (float) $item->new_average_unit_cost, 4 );

		$delta = (float) $item->new_inventory_value - (float) $item->old_inventory_value;

		$uid   = (int) $item->user_id;
		$user_s = '';
		if ( $uid > 0 ) {
			$user = get_userdata( $uid );
			$user_s = $user ? $user->display_name : (string) $uid;
		}

		$date = isset( $item->created_at ) && $item->created_at
			? mysql2date( 'Y-m-d H:i:s', $item->created_at, true )
			: '';

		return array(
			$date,
			$prod_label,
			$type,
			wc_format_decimal( (float) $item->quantity_change, 4 ),
			wc_format_decimal( (float) $item->old_stock, 4 ),
			wc_format_decimal( (float) $item->new_stock, 4 ),
			$old_avg,
			$new_avg,
			wc_format_decimal( $delta, 4 ),
			$user_s,
			(string) ( $item->note ?? '' ),
		);
	}
}
