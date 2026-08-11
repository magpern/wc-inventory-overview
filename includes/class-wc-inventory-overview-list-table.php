<?php
/**
 * Admin inventory list: grouped variable products, compact columns, custom render.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Inventory WP_List_Table (operational layout).
 */
class WC_Inventory_Overview_List_Table extends WP_List_Table {

	/**
	 * @var array<int, WC_Product>
	 */
	protected $parent_cache = array();

	/**
	 * Grouped rows: list of array{ parent: WC_Product, children: WC_Product[] }.
	 *
	 * @var array<int, array{parent: WC_Product, children: WC_Product[]}>
	 */
	public $item_groups = array();

	/**
	 * @var array<string, int>
	 */
	public $summary_stats = array();

	/**
	 * Shared params for variation sub-queries.
	 *
	 * @var array<string, mixed>
	 */
	protected $query_base = array();

	/**
	 * @var string
	 */
	protected $row_role = 'parent';

	/**
	 * Aggregate variation metrics for the current variable parent row (request-scoped).
	 *
	 * @var array<string, mixed>|null
	 */
	protected $current_variable_aggregate = null;

	/**
	 * M3: Inventory Position results keyed by item (product or variation) ID,
	 * populated once per request by prepare_items() after the complete
	 * groups structure — including later-discovered variations — is built.
	 * Empty for users without manage_woocommerce.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $position_map = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'wc-inventory-item',
				'plural'   => 'wc-inventory-items',
				'ajax'     => false,
			)
		);
	}

	public function get_primary_column() {
		return 'product';
	}

	public function no_items() {
		esc_html_e( 'No products found.', 'wc-inventory-overview' );
	}

	protected function get_table_classes() {
		$classes   = array_values( array_diff( parent::get_table_classes(), array( 'striped' ) ) );
		$classes[] = 'wc-io-table';
		return $classes;
	}

	public static function screen_base() {
		return 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG;
	}

	public function get_columns() {
		$cols = array(
			'cb'       => '<input type="checkbox" />',
			'product'  => __( 'Product', 'wc-inventory-overview' ),
			'sku'      => __( 'SKU', 'wc-inventory-overview' ),
			'stock'    => __( 'Stock', 'wc-inventory-overview' ),
		);
		if ( current_user_can( 'manage_woocommerce' ) ) {
			// M3: Incoming/Position sit at the same sensitivity tier as average
			// cost and inventory value below them (not the broader Stock
			// column precedent) — manage_woocommerce only, no new capability.
			$cols['incoming']        = __( 'Incoming', 'wc-inventory-overview' );
			$cols['position']        = __( 'Position', 'wc-inventory-overview' );
			$cols['avg_cost']        = __( 'Average unit cost', 'wc-inventory-overview' );
			$cols['inventory_value'] = __( 'Inventory value', 'wc-inventory-overview' );
		}
		$cols['status']  = __( 'Status', 'wc-inventory-overview' );
		$cols['details'] = '<span class="screen-reader-text">' . esc_html__( 'Details', 'wc-inventory-overview' ) . '</span>';

		return $cols;
	}

	protected function get_sortable_columns() {
		return array(
			'product' => array( 'title', false ),
			'sku'     => array( 'sku', false ),
			'stock'   => array( 'stock_quantity', false ),
			'status'  => array( 'stock_status', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'wc_io_set_draft'       => __( 'Set to draft', 'wc-inventory-overview' ),
			'wc_io_hide_catalog'    => __( 'Hide from catalog', 'wc-inventory-overview' ),
			'wc_io_mark_instock'    => __( 'Mark in stock', 'wc-inventory-overview' ),
			'wc_io_mark_outofstock' => __( 'Mark out of stock', 'wc-inventory-overview' ),
		);
	}

	public function has_items() {
		return ! empty( $this->item_groups );
	}

	protected function column_cb( $item ) {
		$id = $item->get_id();
		return '<input type="checkbox" name="post[]" value="' . esc_attr( (string) $id ) . '" />';
	}

	protected function column_default( $item, $column_name ) {
		return '—';
	}

	protected function column_product( WC_Product $item ) {
		$title = $item->get_name();
		$edit  = get_edit_post_link( $item->get_id(), 'raw' );
		$name  = $edit
			? '<a class="wc-io-title-link" href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>'
			: '<span class="wc-io-title-link">' . esc_html( $title ) . '</span>';

		if ( 'variation' === $this->row_role ) {
			return '<div class="wc-io-variation-title">' . $name . '</div>';
		}

		$toggle = '';
		$count  = (int) $this->get_current_group_variation_count();
		if ( $item->is_type( 'variable' ) && $count > 0 ) {
			$expanded = is_array( $this->current_variable_aggregate ) && ! empty( $this->current_variable_aggregate['expand_default'] );
			$toggle     = sprintf(
				'<button type="button" class="button button-small wc-io-toggle-variations" aria-expanded="%3$s" data-wc-io-parent="%1$d"><span class="wc-io-toggle-icon" aria-hidden="true">▸</span> %2$s</button> ',
				(int) $item->get_id(),
				/* translators: %d: number of variations */
				esc_html( sprintf( _n( '%d variation', '%d variations', $count, 'wc-inventory-overview' ), $count ) ),
				$expanded ? 'true' : 'false'
			);
		}

		$summary = '';
		if ( $item->is_type( 'variable' ) && is_array( $this->current_variable_aggregate ) && ! empty( $this->current_variable_aggregate['summary_html'] ) ) {
			$summary = '<div class="wc-io-var-summary">' . $this->current_variable_aggregate['summary_html'] . '</div>';
		}

		return '<div class="wc-io-product-cell">' . $toggle . $name . $summary . '</div>';
	}

	/**
	 * @var int
	 */
	protected $current_group_variation_count = 0;

	protected function get_current_group_variation_count() {
		return (int) $this->current_group_variation_count;
	}

	protected function column_sku( WC_Product $item ) {
		$sku = $item->get_sku();
		return $sku !== '' ? '<code class="wc-io-sku">' . esc_html( $sku ) . '</code>' : '<span class="wc-io-muted">—</span>';
	}

	protected function column_avg_cost( WC_Product $item ) {
		if ( 'parent' === $this->row_role && $item->is_type( 'variable' ) ) {
			return '<span class="wc-io-muted">—</span>';
		}
		if ( ! $item->is_type( 'variation' ) && ! $item->is_type( 'simple' ) ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$avg = WC_Inventory_Overview_Costing::get_average_float( $item );
		return WC_Inventory_Overview_Costing::format_average_display( $avg );
	}

	protected function column_inventory_value( WC_Product $item ) {
		if ( 'parent' === $this->row_role && $item->is_type( 'variable' ) ) {
			return '<span class="wc-io-muted">—</span>';
		}
		if ( ! $item->is_type( 'variation' ) && ! $item->is_type( 'simple' ) ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$raw = $item->get_meta( WC_Inventory_Overview_Costing::META_VAL, true );
		$val = ( '' === $raw || null === $raw ) ? null : $raw;

		return WC_Inventory_Overview_Costing::format_value_display( $val );
	}

	protected function column_stock( WC_Product $item ) {
		if ( 'parent' === $this->row_role && $item->is_type( 'variable' ) && is_array( $this->current_variable_aggregate ) ) {
			$html = isset( $this->current_variable_aggregate['stock_column_html'] ) ? (string) $this->current_variable_aggregate['stock_column_html'] : '';
			return $html !== '' ? $html : '<span class="wc-io-muted">—</span>';
		}

		if ( ! $item->managing_stock() ) {
			return '<span class="wc-io-muted">' . esc_html__( 'Not managed', 'wc-inventory-overview' ) . '</span>';
		}
		$id    = $item->get_id();
		$qty   = $item->get_stock_quantity();
		$value = ( null === $qty || '' === $qty ) ? '' : (string) wc_stock_amount( $qty );

		return sprintf(
			'<div class="wc-io-stock" data-product-id="%1$d">
				<button type="button" class="button-link wc-io-stock-display" aria-label="%4$s">%2$s</button>
				<span class="wc-io-stock-edit" hidden>
					<input type="number" step="any" class="small-text wc-io-stock-input" value="%3$s" aria-label="%4$s" />
					<button type="button" class="button button-small wc-io-stock-save">' . esc_html__( 'Save', 'wc-inventory-overview' ) . '</button>
					<button type="button" class="button button-small wc-io-stock-cancel">' . esc_html__( 'Cancel', 'wc-inventory-overview' ) . '</button>
				</span>
			</div>',
			(int) $id,
			$value !== '' ? esc_html( $value ) : '<span class="wc-io-muted">' . esc_html__( 'Set…', 'wc-inventory-overview' ) . '</span>',
			esc_attr( $value ),
			esc_attr__( 'Edit stock quantity', 'wc-inventory-overview' )
		);
	}

	/**
	 * M3: Incoming column. Variable-parent rows show a presentation-only sum
	 * of child variation Incoming (never a real parent-level supply, INV-8).
	 *
	 * @param WC_Product $item Row product.
	 */
	protected function column_incoming( WC_Product $item ) {
		$agg = $this->get_variable_position_aggregate_for_row( $item );
		if ( null !== $agg ) {
			return self::render_incoming_cell( (float) $agg['incoming'], ! empty( $agg['incoming_delayed'] ) );
		}

		$pos = $this->position_map[ $item->get_id() ] ?? null;
		if ( null === $pos ) {
			return '<span class="wc-io-muted">—</span>';
		}

		return self::render_incoming_cell( (float) $pos['incoming'], ! empty( $pos['incoming_delayed'] ) );
	}

	/**
	 * M3: Position column (On Hand + Incoming). Variable-parent rows show a
	 * presentation-only sum of child variation Position.
	 *
	 * @param WC_Product $item Row product.
	 */
	protected function column_position( WC_Product $item ) {
		$agg = $this->get_variable_position_aggregate_for_row( $item );
		if ( null !== $agg ) {
			return '<span class="wc-io-position-qty">' . esc_html( self::format_position_qty( (float) $agg['position'] ) ) . '</span>';
		}

		$pos = $this->position_map[ $item->get_id() ] ?? null;
		if ( null === $pos ) {
			return '<span class="wc-io-muted">—</span>';
		}

		return '<span class="wc-io-position-qty">' . esc_html( self::format_position_qty( (float) $pos['position'] ) ) . '</span>';
	}

	/**
	 * Variable-parent presentation aggregate for the current row, or null when
	 * the row is not a variable parent (simple product, variation, or no
	 * aggregate computed for this request).
	 *
	 * @param WC_Product $item Row product.
	 * @return array<string, mixed>|null
	 */
	protected function get_variable_position_aggregate_for_row( WC_Product $item ) {
		if ( 'parent' !== $this->row_role || ! $item->is_type( 'variable' ) ) {
			return null;
		}
		if ( ! is_array( $this->current_variable_aggregate ) || ! array_key_exists( 'incoming', $this->current_variable_aggregate ) ) {
			return null;
		}
		return $this->current_variable_aggregate;
	}

	/**
	 * Incoming cell markup: quantity plus an optional delayed badge.
	 *
	 * @param float $incoming Incoming quantity.
	 * @param bool  $delayed  Whether at least one contributing line is delayed.
	 */
	protected static function render_incoming_cell( float $incoming, bool $delayed ): string {
		if ( $incoming <= 0 ) {
			return '<span class="wc-io-muted">—</span>';
		}
		$html = '<span class="wc-io-incoming-qty">' . esc_html( self::format_position_qty( $incoming ) ) . '</span>';
		if ( $delayed ) {
			$html .= ' <span class="wc-io-badge wc-io-badge-delayed">' . esc_html__( 'Delayed', 'wc-inventory-overview' ) . '</span>';
		}
		return $html;
	}

	/**
	 * Format a quantity for display using WooCommerce's stock-amount rules.
	 *
	 * @param float $value Raw quantity.
	 */
	protected static function format_position_qty( float $value ): string {
		return (string) wc_stock_amount( $value );
	}

	/**
	 * On Hand quantity supplied to the Inventory Position Service for one
	 * purchasable item (M3). Unmanaged-stock items contribute 0 On Hand —
	 * WooCommerce has no quantity to report for them.
	 *
	 * @param WC_Product $item Simple product or variation.
	 */
	protected static function item_on_hand_qty( WC_Product $item ): float {
		if ( ! $item->managing_stock() ) {
			return 0.0;
		}
		$qty = $item->get_stock_quantity();
		return ( null === $qty || '' === $qty ) ? 0.0 : (float) $qty;
	}

	protected function column_status( WC_Product $item ) {
		if ( 'parent' === $this->row_role && $item->is_type( 'variable' ) && is_array( $this->current_variable_aggregate ) ) {
			$inner  = self::render_publish_visibility_badges_inner( $item );
			$inner .= isset( $this->current_variable_aggregate['badges_inner_html'] ) ? (string) $this->current_variable_aggregate['badges_inner_html'] : '';

			return '<div class="wc-io-badges wc-io-badges-variable-parent">' . $inner . '</div>';
		}

		return self::render_status_badges( $item );
	}

	/**
	 * Draft / catalog visibility badge HTML (no wrapper).
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_publish_visibility_badges_inner( WC_Product $item ) {
		$html = array();

		if ( 'draft' === $item->get_status() || 'pending' === $item->get_status() ) {
			$html[] = '<span class="wc-io-badge wc-io-badge-draft">' . esc_html__( 'Draft', 'wc-inventory-overview' ) . '</span>';
		}

		if ( \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN === $item->get_catalog_visibility() ) {
			$html[] = '<span class="wc-io-badge wc-io-badge-hidden">' . esc_html__( 'Hidden', 'wc-inventory-overview' ) . '</span>';
		}

		return implode( '', $html );
	}

	/**
	 * Draft / catalog visibility badges only.
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_publish_visibility_badges( WC_Product $item ) {
		$inner = self::render_publish_visibility_badges_inner( $item );

		return '' !== $inner ? '<div class="wc-io-badges">' . $inner . '</div>' : '';
	}

	/**
	 * Direct stock badge HTML for a single sellable line (simple or variation), no wrapper.
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_direct_stock_badges_inner( WC_Product $item ) {
		if ( \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER === $item->get_stock_status() ) {
			return '<span class="wc-io-badge wc-io-badge-backorder">' . esc_html__( 'On backorder', 'wc-inventory-overview' ) . '</span>';
		}
		if ( ! $item->is_in_stock() ) {
			return '<span class="wc-io-badge wc-io-badge-out">' . esc_html__( 'Out of stock', 'wc-inventory-overview' ) . '</span>';
		}

		$low = false;
		if ( $item->managing_stock() ) {
			$qty = $item->get_stock_quantity();
			if ( null !== $qty && '' !== $qty ) {
				$low_amt = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $item );
				if ( null !== $low_amt && (float) $qty <= (float) $low_amt ) {
					$low = true;
				}
			}
		}
		if ( $low ) {
			return '<span class="wc-io-badge wc-io-badge-low">' . esc_html__( 'Low stock', 'wc-inventory-overview' ) . '</span>';
		}

		return '<span class="wc-io-badge wc-io-badge-in">' . esc_html__( 'In stock', 'wc-inventory-overview' ) . '</span>';
	}

	/**
	 * Direct stock badges for a single sellable line (simple or variation).
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_direct_stock_badges( WC_Product $item ) {
		return '<div class="wc-io-badges">' . self::render_direct_stock_badges_inner( $item ) . '</div>';
	}

	/**
	 * Status badges for scanning (publish + direct stock).
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_status_badges( WC_Product $item ) {
		$inner  = self::render_publish_visibility_badges_inner( $item );
		$inner .= self::render_direct_stock_badges_inner( $item );

		return '<div class="wc-io-badges">' . $inner . '</div>';
	}

	/**
	 * Build variation aggregate metrics for a variable parent row.
	 *
	 * @param WC_Product              $parent       Variable parent.
	 * @param array<int, WC_Product>  $children     Variation products on this page.
	 * @param array<int, array<string, mixed>> $position_map M3: Inventory Position results keyed by
	 *        item ID, used only to sum presentation-only Incoming/Position
	 *        totals for the parent row (INV-8: never a real parent-level
	 *        incoming record). Empty when the viewer lacks manage_woocommerce.
	 * @return array<string, mixed>
	 */
	public static function compute_variable_aggregate( WC_Product $parent, array $children, array $position_map = array() ) {
		$total = count( $children );

		$incoming_total   = 0.0;
		$position_total   = 0.0;
		$incoming_delayed = false;

		$n_managed   = 0;
		$n_unmanaged = 0;
		$n_bo        = 0;
		$n_out       = 0;
		$n_in_ok     = 0;
		$n_in_low    = 0;

		foreach ( $children as $v ) {
			if ( ! $v instanceof WC_Product || ! $v->is_type( 'variation' ) ) {
				continue;
			}

			$child_pos = $position_map[ $v->get_id() ] ?? null;
			if ( null !== $child_pos ) {
				$incoming_total += (float) $child_pos['incoming'];
				$position_total += (float) $child_pos['position'];
				if ( ! empty( $child_pos['incoming_delayed'] ) ) {
					$incoming_delayed = true;
				}
			}

			if ( ! $v->managing_stock() ) {
				++$n_unmanaged;
				continue;
			}

			++$n_managed;
			$st = $v->get_stock_status();

			if ( \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER === $st ) {
				++$n_bo;
				continue;
			}
			if ( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK === $st || ! $v->is_in_stock() ) {
				++$n_out;
				continue;
			}

			$low   = false;
			$qty   = $v->get_stock_quantity();
			$low_amt = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $v );
			if ( null !== $qty && '' !== $qty && null !== $low_amt && (float) $qty <= (float) $low_amt ) {
				$low = true;
			}

			if ( $low ) {
				++$n_in_low;
			} else {
				++$n_in_ok;
			}
		}

		$n_in_all = $n_in_ok + $n_in_low;

		$mixed_in_out = ( $n_in_all > 0 && $n_out > 0 );
		$mixed_bo     = ( $n_bo > 0 && ( $n_in_all + $n_out ) > 0 );

		// Variation rows default collapsed. Auto-expand reserved for a future screen option (apply_filters).
		$clean_for_highlight = ( $n_managed > 0 && $n_out === 0 && $n_bo === 0 && $n_in_low === 0 && $n_in_ok === $n_managed );
		$needs_row_attention = ( $n_managed > 0 ) && ! $clean_for_highlight;
		$expand_default      = (bool) apply_filters( 'wc_io_expand_variable_groups_default', false, $parent, $children, compact( 'n_managed', 'n_out', 'n_bo', 'n_in_low', 'n_in_ok', 'n_in_all' ) );

		$lines = array();

		if ( $total > 0 ) {
			$lines[] = sprintf(
				/* translators: %d: variation count */
				_n( '%d variation', '%d variations', $total, 'wc-inventory-overview' ),
				$total
			);
		}

		$stock_bits = array();
		if ( $n_in_all > 0 ) {
			$stock_bits[] = sprintf(
				/* translators: %d: in-stock variation count */
				_n( '%d in stock', '%d in stock', $n_in_all, 'wc-inventory-overview' ),
				$n_in_all
			);
		}
		if ( $n_out > 0 ) {
			$stock_bits[] = sprintf(
				/* translators: %d: out-of-stock variation count */
				_n( '%d out of stock', '%d out of stock', $n_out, 'wc-inventory-overview' ),
				$n_out
			);
		}
		if ( $n_bo > 0 ) {
			$stock_bits[] = sprintf(
				/* translators: %d: backorder variation count */
				_n( '%d on backorder', '%d on backorder', $n_bo, 'wc-inventory-overview' ),
				$n_bo
			);
		}
		if ( $n_in_low > 0 ) {
			$stock_bits[] = sprintf(
				/* translators: %d: low-stock variation count */
				_n( '%d low stock', '%d low stock', $n_in_low, 'wc-inventory-overview' ),
				$n_in_low
			);
		}

		$summary_html = '';
		if ( $lines ) {
			$summary_html = '<span class="wc-io-var-summary-line wc-io-var-summary-count">' . esc_html( $lines[0] ) . '</span>';
			if ( $stock_bits ) {
				$summary_html .= '<span class="wc-io-var-summary-line">' . esc_html( implode( ' · ', $stock_bits ) ) . '</span>';
			}
		}

		$stock_column_html = '';
		if ( $stock_bits ) {
			$stock_column_html = '<div class="wc-io-parent-stock-summary">' . esc_html( implode( ' / ', $stock_bits ) ) . '</div>';
		} elseif ( $n_managed === 0 && $total > 0 ) {
			$stock_column_html = '<span class="wc-io-muted">' . esc_html__( 'Stock not managed (variations)', 'wc-inventory-overview' ) . '</span>';
		}

		$badges = array();

		if ( $n_managed === 0 ) {
			if ( $total > 0 ) {
				$badges[] = '<span class="wc-io-badge wc-io-badge-muted">' . esc_html__( 'No managed stock', 'wc-inventory-overview' ) . '</span>';
			}
		} elseif ( $n_bo === $n_managed ) {
			$badges[] = '<span class="wc-io-badge wc-io-badge-backorder">' . esc_html__( 'On backorder', 'wc-inventory-overview' ) . '</span>';
		} elseif ( $n_out === $n_managed ) {
			$badges[] = '<span class="wc-io-badge wc-io-badge-out">' . esc_html__( 'All out of stock', 'wc-inventory-overview' ) . '</span>';
		} elseif ( $n_in_all === $n_managed && $n_in_low === 0 ) {
			$badges[] = '<span class="wc-io-badge wc-io-badge-all-in">' . esc_html__( 'All in stock', 'wc-inventory-overview' ) . '</span>';
		} elseif ( $n_in_all === $n_managed ) {
			$badges[] = '<span class="wc-io-badge wc-io-badge-low">' . esc_html__( 'Low stock', 'wc-inventory-overview' ) . '</span>';
		} else {
			$has_mixed = ( $n_in_all > 0 && $n_out > 0 ) || ( $n_bo > 0 && ( $n_in_all + $n_out ) > 0 );

			if ( $has_mixed ) {
				$badges[] = '<span class="wc-io-badge wc-io-badge-mixed">' . esc_html__( 'Mixed stock', 'wc-inventory-overview' ) . '</span>';
			}

			if ( $n_in_low > 0 ) {
				$badges[] = '<span class="wc-io-badge wc-io-badge-low wc-io-badge-low-child">' . esc_html__( 'Low stock', 'wc-inventory-overview' ) . '</span>';
			}

			if ( $n_bo > 0 && $n_bo < $n_managed ) {
				$badges[] = '<span class="wc-io-badge wc-io-badge-backorder">' . esc_html__( 'On backorder', 'wc-inventory-overview' ) . '</span>';
			}
		}

		$badges = array_values( array_unique( $badges ) );

		if ( ! $badges && $n_managed > 0 ) {
			$badges[] = '<span class="wc-io-badge wc-io-badge-mixed">' . esc_html__( 'Mixed stock', 'wc-inventory-overview' ) . '</span>';
		}

		return array(
			'n_total'           => $total,
			'n_managed'         => $n_managed,
			'n_unmanaged'       => $n_unmanaged,
			'n_bo'              => $n_bo,
			'n_out'             => $n_out,
			'n_in_ok'           => $n_in_ok,
			'n_in_low'          => $n_in_low,
			'n_in_all'          => $n_in_all,
			'mixed_in_out'      => $mixed_in_out,
			'mixed_bo'          => $mixed_bo,
			'expand_default'         => $expand_default,
			'needs_row_attention'    => $needs_row_attention,
			'summary_html'      => $summary_html,
			'stock_column_html' => $stock_column_html,
			'badges_inner_html' => implode( '', $badges ),
			'incoming'          => $incoming_total,
			'position'          => $position_total,
			'incoming_delayed'  => $incoming_delayed,
		);
	}

	/**
	 * Row classes for a variable parent driven by variation aggregates.
	 *
	 * @param WC_Product              $parent Parent.
	 * @param array<string, mixed>|null $agg    Aggregate or null.
	 * @return string[]
	 */
	protected static function get_variable_parent_row_classes( WC_Product $parent, $agg ) {
		$classes = array();
		if ( 'draft' === $parent->get_status() || 'pending' === $parent->get_status() ) {
			$classes[] = 'wc-io-is-draft';
		}
		if ( \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN === $parent->get_catalog_visibility() ) {
			$classes[] = 'wc-io-is-hidden-catalog';
		}
		if ( ! is_array( $agg ) ) {
			return $classes;
		}
		if ( ! empty( $agg['needs_row_attention'] ) ) {
			$classes[] = 'wc-io-parent-attention';
		}
		if ( ! empty( $agg['mixed_in_out'] ) ) {
			$classes[] = 'wc-io-has-mixed-stock';
		}
		if ( ! empty( $agg['mixed_bo'] ) ) {
			$classes[] = 'wc-io-has-mixed-backorder';
		}
		if ( (int) ( $agg['n_in_low'] ?? 0 ) > 0 ) {
			$classes[] = 'wc-io-has-low-child';
		}
		if ( (int) ( $agg['n_out'] ?? 0 ) > 0 ) {
			$classes[] = 'wc-io-has-child-out';
		}
		if ( (int) ( $agg['n_bo'] ?? 0 ) > 0 ) {
			$classes[] = 'wc-io-has-backorder-child';
		}

		return $classes;
	}

	protected function column_details( WC_Product $item ) {
		return sprintf(
			'<button type="button" class="button button-small wc-io-details-toggle" aria-expanded="false" aria-controls="wc-io-detail-panel-%1$d" data-wc-io-detail="%1$d">%2$s</button>',
			(int) $item->get_id(),
			esc_html__( 'Details', 'wc-inventory-overview' )
		);
	}

	/**
	 * Secondary fields for expandable region.
	 *
	 * @param WC_Product $item Product.
	 * @return string
	 */
	public static function render_details_dl( WC_Product $item ) {
		$rows = array();

		if ( $item->is_type( 'variation' ) ) {
			$rows[] = array( __( 'Variation', 'wc-inventory-overview' ), wp_strip_all_tags( wc_get_formatted_variation( $item, true, false, true ) ) );
		}

		$labels = wc_get_product_stock_status_options();
		$st      = $item->get_stock_status();
		$rows[]  = array( __( 'Stock status', 'wc-inventory-overview' ), isset( $labels[ $st ] ) ? $labels[ $st ] : $st );

		$b = $item->get_backorders();
		$rows[] = array(
			__( 'Backorders', 'wc-inventory-overview' ),
			'no' === $b ? __( 'No', 'wc-inventory-overview' ) : ( 'notify' === $b ? __( 'Allow, notify', 'wc-inventory-overview' ) : __( 'Yes', 'wc-inventory-overview' ) ),
		);

		$raw = get_post_meta( $item->get_id(), '_expected_back_in_stock_date', true );
		if ( $raw !== '' && null !== $raw ) {
			$ts = strtotime( (string) $raw );
			$rows[] = array(
				__( 'Expected restock', 'wc-inventory-overview' ),
				$ts ? wp_date( get_option( 'date_format' ), $ts ) : (string) $raw,
			);
		}

		$rp = $item->get_regular_price();
		$sp = $item->get_sale_price();
		if ( $rp !== '' ) {
			$rows[] = array( __( 'Regular price', 'wc-inventory-overview' ), wp_strip_all_tags( wc_price( $rp ) ) );
		}
		if ( $sp !== '' ) {
			$rows[] = array( __( 'Sale price', 'wc-inventory-overview' ), wp_strip_all_tags( wc_price( $sp ) ) );
		}

		$rows[] = array( __( 'Publish status', 'wc-inventory-overview' ), ucfirst( $item->get_status() ) );

		$vmap = wc_get_product_visibility_options();
		$cv   = $item->get_catalog_visibility();
		$rows[] = array(
			__( 'Catalog visibility', 'wc-inventory-overview' ),
			isset( $vmap[ $cv ] ) ? $vmap[ $cv ] : $cv,
		);

		$dm = $item->get_date_modified();
		if ( $dm ) {
			$rows[] = array( __( 'Last modified', 'wc-inventory-overview' ), $dm->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
		}

		ob_start();
		echo '<dl class="wc-io-dl">';
		foreach ( $rows as $row ) {
			echo '<dt>' . esc_html( $row[0] ) . '</dt><dd>' . esc_html( (string) $row[1] ) . '</dd>';
		}
		echo '</dl>';

		return (string) ob_get_clean();
	}

	/**
	 * M3: per-supply drill-down. Renders each independent contributing PO
	 * line as its own row (never merged or collapsed, INV-1/INV-7) with PO
	 * number/link, outstanding quantity, expected date, confidence, and
	 * delayed indication. Reuses this list table's existing mini-table
	 * markup convention; no AJAX, no REST, no new modal framework.
	 *
	 * @param WC_Product $item Simple product or variation (never a variable parent).
	 * @return string
	 */
	protected function render_position_drilldown_section( WC_Product $item ): string {
		$pos = $this->position_map[ $item->get_id() ] ?? null;
		if ( null === $pos ) {
			return '';
		}

		ob_start();
		echo '<div class="wc-io-detail-position">';
		echo '<h4 class="wc-io-detail-heading">' . esc_html__( 'Incoming supply', 'wc-inventory-overview' ) . '</h4>';

		$lines = is_array( $pos['incoming_lines'] ?? null ) ? $pos['incoming_lines'] : array();

		if ( empty( $lines ) ) {
			echo '<p class="wc-io-muted">' . esc_html__( 'No open purchase order lines.', 'wc-inventory-overview' ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		echo '<table class="widefat striped wc-io-mini-table wc-io-position-drilldown"><thead><tr>';
		echo '<th>' . esc_html__( 'PO number', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Supplier', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Outstanding', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Expected date', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence', 'wc-inventory-overview' ) . '</th>';
		echo '<th>' . esc_html__( 'Delayed', 'wc-inventory-overview' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $lines as $line ) {
			$po_url = WC_Inventory_Overview_PO_Admin::detail_url( (int) ( $line['po_id'] ?? 0 ) );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $po_url ) . '">' . esc_html( (string) ( $line['po_number'] ?? '' ) ) . '</a></td>';
			echo '<td>' . esc_html( (string) ( $line['supplier_name'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( WC_Inventory_Overview_PO_Statuses::label( (string) ( $line['po_status'] ?? '' ) ) ) . '</td>';
			echo '<td>' . esc_html( self::format_position_qty( (float) ( $line['outstanding'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( ! empty( $line['expected_date'] ) ? (string) $line['expected_date'] : '—' ) . '</td>';
			echo '<td>' . esc_html( ! empty( $line['expected_confidence'] ) ? (string) $line['expected_confidence'] : '—' ) . '</td>';
			echo '<td>';
			if ( ! empty( $line['is_delayed'] ) ) {
				echo '<span class="wc-io-badge wc-io-badge-delayed">' . esc_html__( 'Delayed', 'wc-inventory-overview' ) . '</span>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * M3: standalone expandable detail row for one variation, keyed by the
	 * variation's own ID so its "Details" toggle (rendered per-row by
	 * column_details()) targets a real panel — completing the existing
	 * details-toggle pattern for variation rows, which previously had no
	 * matching panel to open.
	 *
	 * @param WC_Product $child Variation.
	 */
	protected function render_variation_detail_row( WC_Product $child ) {
		$id = (string) $child->get_id();

		echo '<tr id="wc-io-detail-row-' . esc_attr( $id ) . '" class="wc-io-detail-row wc-io-variation-detail-row" hidden>';
		$colspan = count( $this->get_columns() );
		echo '<td colspan="' . (int) $colspan . '" class="wc-io-detail-cell">';
		echo '<div id="wc-io-detail-panel-' . esc_attr( $id ) . '" class="wc-io-detail-panel">';
		echo '<div class="wc-io-detail-cols">';
		echo '<div class="wc-io-detail-primary">';
		echo '<h4 class="wc-io-detail-heading">' . esc_html__( 'Variation details', 'wc-inventory-overview' ) . '</h4>';
		echo self::render_details_dl( $child );
		echo '</div>';
		if ( current_user_can( 'manage_woocommerce' ) ) {
			echo $this->render_position_drilldown_section( $child ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
		}
		echo '</div></div></td>';
		echo '</tr>';
	}

	/**
	 * @return string[]
	 */
	protected function get_row_state_classes( WC_Product $item ) {
		$classes = array();
		if ( 'draft' === $item->get_status() || 'pending' === $item->get_status() ) {
			$classes[] = 'wc-io-is-draft';
		}
		if ( \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN === $item->get_catalog_visibility() ) {
			$classes[] = 'wc-io-is-hidden-catalog';
		}
		if ( $item->is_in_stock() && $item->managing_stock() ) {
			$qty = $item->get_stock_quantity();
			if ( null !== $qty && '' !== $qty ) {
				$low = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $item );
				if ( null !== $low && (float) $qty <= (float) $low ) {
					$classes[] = 'wc-io-is-low-stock';
				}
			}
		}
		if ( ! $item->is_in_stock() ) {
			$classes[] = 'wc-io-is-outofstock';
		}
		return $classes;
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'number'     => 200,
			)
		);
		$current = isset( $_GET['wc_io_product_cat'] ) ? absint( $_GET['wc_io_product_cat'] ) : 0;
		echo '<div class="alignleft actions wc-io-filters">';
		echo '<label class="screen-reader-text" for="wc-io-filter-cat">' . esc_html__( 'Filter by category', 'wc-inventory-overview' ) . '</label>';
		echo '<select name="wc_io_product_cat" id="wc-io-filter-cat">';
		echo '<option value="0">' . esc_html__( 'All categories', 'wc-inventory-overview' ) . '</option>';
		if ( ! is_wp_error( $cats ) ) {
			foreach ( $cats as $term ) {
				printf(
					'<option value="%1$d" %3$s>%2$s</option>',
					(int) $term->term_id,
					esc_html( $term->name ),
					selected( $current, (int) $term->term_id, false )
				);
			}
		}
		echo '</select>';

		$ss_current = isset( $_GET['wc_io_stock_status'] ) ? sanitize_key( wp_unslash( $_GET['wc_io_stock_status'] ) ) : '';
		echo '<label class="screen-reader-text" for="wc-io-filter-stock">' . esc_html__( 'Filter by stock status', 'wc-inventory-overview' ) . '</label>';
		echo '<select name="wc_io_stock_status" id="wc-io-filter-stock">';
		echo '<option value="">' . esc_html__( 'All stock statuses', 'wc-inventory-overview' ) . '</option>';
		foreach ( wc_get_product_stock_status_options() as $key => $label ) {
			printf(
				'<option value="%1$s" %3$s>%2$s</option>',
				esc_attr( $key ),
				esc_html( $label ),
				selected( $ss_current, $key, false )
			);
		}
		echo '</select>';

		$exclude_private_checked = WC_Inventory_Overview_Repository::request_excludes_private();
		echo '<input type="hidden" name="wc_io_exclude_private" value="0" />';
		echo '<label class="wc-io-exclude-private"><input type="checkbox" name="wc_io_exclude_private" value="1" ' . checked( $exclude_private_checked, true, false ) . ' /> ';
		echo esc_html__( 'Exclude private products', 'wc-inventory-overview' ) . '</label> ';

		submit_button( __( 'Filter', 'wc-inventory-overview' ), '', 'filter_action', false );
		echo ' ';
		$export_query = array(
			'page'               => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'                => WC_Inventory_Overview_Plugin::TAB_OVERVIEW,
			'wc_io_export'       => 'csv',
			'wc_io_product_cat'  => $current,
			'wc_io_stock_status' => $ss_current,
			's'                  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		if ( ! $exclude_private_checked ) {
			$export_query['wc_io_exclude_private'] = '0';
		}
		$export_url = wp_nonce_url(
			add_query_arg(
				$export_query,
				admin_url( 'admin.php' )
			),
			'wc_io_export_csv',
			'_wc_io_export_nonce',
			false
		);
		echo '<a class="button" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Download CSV (all matches)', 'wc-inventory-overview' ) . '</a>';
		echo '</div>';
	}

	public function render_top_tablenav() {
		$this->display_tablenav( 'top' );
	}

	public function render_table_main() {
		if ( method_exists( $this->screen, 'render_screen_reader_content' ) ) {
			$this->screen->render_screen_reader_content( 'heading_list' );
		}
		if ( method_exists( $this, 'print_table_description' ) ) {
			$this->print_table_description();
		}
		?>
		<table class="wp-list-table <?php echo esc_attr( implode( ' ', $this->get_table_classes() ) ); ?>">
			<thead>
			<tr>
				<?php $this->print_column_headers(); ?>
			</tr>
			</thead>
			<tbody id="the-list" data-wp-lists="list:wc-inventory-item">
			<?php $this->display_rows(); ?>
			</tbody>
			<tfoot>
			<tr>
				<?php $this->print_column_headers( false ); ?>
			</tr>
			</tfoot>
		</table>
		<?php
		$this->display_tablenav( 'bottom' );
	}

	public function display_rows() {
		$i = 0;
		foreach ( $this->item_groups as $group ) {
			$this->render_group_block( $group, $i % 2 === 1 );
			++$i;
		}
	}

	/**
	 * @param array{parent: WC_Product, children: WC_Product[]} $group Group.
	 * @param bool                                              $zebra Odd striping band.
	 */
	protected function render_group_block( array $group, $zebra ) {
		$parent   = $group['parent'];
		$children = isset( $group['children'] ) && is_array( $group['children'] ) ? $group['children'] : array();

		$this->current_group_variation_count = count( $children );

		$agg = null;
		if ( $parent->is_type( 'variable' ) ) {
			$agg = self::compute_variable_aggregate( $parent, $children, $this->position_map );
		}

		$expand_variations = is_array( $agg ) && ! empty( $agg['expand_default'] );

		$parent_row_classes = array( 'wc-io-parent-row', $zebra ? 'wc-io-zebra' : '' );
		if ( $parent->is_type( 'variable' ) ) {
			$parent_row_classes[] = 'wc-io-parent-variable';
			$parent_row_classes   = array_merge( $parent_row_classes, self::get_variable_parent_row_classes( $parent, $agg ) );
		} else {
			$parent_row_classes = array_merge( $parent_row_classes, $this->get_row_state_classes( $parent ) );
		}

		$this->row_role                   = 'parent';
		$this->current_variable_aggregate = $parent->is_type( 'variable' ) ? $agg : null;
		$this->render_single_row( $parent, $parent_row_classes, 'wc-io-parent-' . $parent->get_id() );
		$this->current_variable_aggregate = null;

		$pid = (int) $parent->get_id();

		$this->row_role = 'variation';
		$variation_hidden = ! $expand_variations;
		foreach ( $children as $child ) {
			$this->render_single_row(
				$child,
				array_merge(
					array(
						'wc-io-variation-row',
						'wc-io-under-parent-' . $pid,
						$zebra ? 'wc-io-zebra' : '',
					),
					$this->get_row_state_classes( $child )
				),
				'wc-io-variation-' . $child->get_id(),
				false,
				null,
				null,
				$variation_hidden
			);
		}

		$this->row_role = 'parent';
		$this->render_single_row(
			$parent,
			array( 'wc-io-detail-row' ),
			'wc-io-detail-row-' . $parent->get_id(),
			true,
			$parent,
			$children
		);

		// M3: each variation gets its own drill-down-capable detail row,
		// keyed by its own ID (its "Details" button, rendered per-row by
		// column_details(), already targets that ID).
		if ( ! empty( $children ) ) {
			$this->row_role = 'variation';
			foreach ( $children as $child ) {
				$this->render_variation_detail_row( $child );
			}
			$this->row_role = 'parent';
		}
	}

	/**
	 * @param WC_Product        $item    Row product.
	 * @param string[]          $classes Row classes.
	 * @param string            $row_id  HTML id.
	 * @param bool              $detail  Detail colspan row.
	 * @param WC_Product|null   $detail_parent Parent for detail content.
	 * @param WC_Product[]|null $detail_children Variations for mini table.
	 * @param bool              $variation_hidden Collapsed variation row.
	 */
	protected function render_single_row( WC_Product $item, array $classes, $row_id, $detail = false, $detail_parent = null, $detail_children = null, $variation_hidden = false ) {
		$classes = array_filter( array_map( 'sanitize_html_class', $classes ) );
		echo '<tr id="' . esc_attr( $row_id ) . '" class="' . esc_attr( implode( ' ', $classes ) ) . '"';

		if ( $detail ) {
			echo ' hidden';
		}
		if ( $variation_hidden ) {
			echo ' hidden';
		}
		echo '>';

		if ( $detail && $detail_parent ) {
			$colspan = count( $this->get_columns() );
			echo '<td colspan="' . (int) $colspan . '" class="wc-io-detail-cell">';
			echo '<div id="wc-io-detail-panel-' . esc_attr( (string) $detail_parent->get_id() ) . '" class="wc-io-detail-panel">';
			echo '<div class="wc-io-detail-cols">';
			echo '<div class="wc-io-detail-primary">';
			echo '<h4 class="wc-io-detail-heading">' . esc_html__( 'Product details', 'wc-inventory-overview' ) . '</h4>';
			echo self::render_details_dl( $detail_parent );
			echo '</div>';
			if ( ! $detail_parent->is_type( 'variable' ) && current_user_can( 'manage_woocommerce' ) ) {
				// M3: a variable parent never carries its own incoming supply
				// (INV-8) — its detail row keeps only the variations mini
				// table below; per-variation drill-down rows are rendered
				// separately in render_group_block().
				echo $this->render_position_drilldown_section( $detail_parent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped internally.
			}
			if ( $detail_children && count( $detail_children ) > 0 ) {
				echo '<div class="wc-io-detail-variations">';
				echo '<h4 class="wc-io-detail-heading">' . esc_html__( 'Variations on this page', 'wc-inventory-overview' ) . '</h4>';
				echo '<table class="widefat striped wc-io-mini-table"><thead><tr>';
				echo '<th>' . esc_html__( 'SKU', 'wc-inventory-overview' ) . '</th>';
				echo '<th>' . esc_html__( 'Attributes', 'wc-inventory-overview' ) . '</th>';
				echo '<th>' . esc_html__( 'Stock', 'wc-inventory-overview' ) . '</th>';
				echo '<th>' . esc_html__( 'Status', 'wc-inventory-overview' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $detail_children as $ch ) {
					$sq = $ch->managing_stock() && null !== $ch->get_stock_quantity() ? (string) wc_stock_amount( $ch->get_stock_quantity() ) : '—';
					echo '<tr>';
					echo '<td><code>' . esc_html( $ch->get_sku() ?: '—' ) . '</code></td>';
					echo '<td>' . esc_html( wp_strip_all_tags( wc_get_formatted_variation( $ch, true, false, true ) ) ) . '</td>';
					echo '<td>' . esc_html( $sq ) . '</td>';
					echo '<td>' . wp_kses_post( self::render_status_badges( $ch ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table></div>';
			}
			echo '</div></div></td>';
			echo '</tr>';
			return;
		}

		list( $columns, $hidden, $sortable, $primary ) = $this->get_column_info();
		foreach ( $columns as $column_name => $column_display_name ) {
			$td_class = 'column-' . $column_name;
			if ( in_array( $column_name, $hidden, true ) ) {
				$td_class .= ' hidden';
			}
			$col_label = is_string( $column_display_name ) ? wp_strip_all_tags( $column_display_name ) : '';
			echo '<td class="' . esc_attr( $td_class ) . '" data-colname="' . esc_attr( $col_label ) . '">';
			$cb = 'column_' . $column_name;
			if ( method_exists( $this, $cb ) ) {
				echo $this->$cb( $item ); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNotSnakeCase
			} else {
				echo $this->column_default( $item, $column_name );
			}
			echo '</td>';
		}

		echo '</tr>';
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$per_page = $this->get_items_per_page( 'wc_io_per_page', 20 );
		$paged    = $this->get_pagenum();

		$orderby_request = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'modified';
		$order           = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$wc_orderby   = 'modified';
		$meta_key     = '';
		$orderby_meta = '';

		switch ( $orderby_request ) {
			case 'title':
				$wc_orderby = 'title';
				break;
			case 'sku':
				$wc_orderby   = 'meta_value';
				$meta_key     = '_sku';
				$orderby_meta = 'meta_value';
				break;
			case 'stock_quantity':
				$wc_orderby   = 'meta_value_num';
				$meta_key     = '_stock';
				$orderby_meta = 'meta_value_num';
				break;
			case 'stock_status':
				$wc_orderby   = 'meta_value';
				$meta_key     = '_stock_status';
				$orderby_meta = 'meta_value';
				break;
			case 'modified':
			default:
				$wc_orderby = 'modified';
				break;
		}

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

		$params = array(
			'per_page'        => $per_page,
			'paged'           => $paged,
			'orderby'         => $wc_orderby,
			'order'           => $order,
			'search'          => $search,
			'category_tt_id'  => $tt_id,
			'stock_status'    => $stock_args,
			'exclude_private' => WC_Inventory_Overview_Repository::request_excludes_private(),
		);

		if ( $meta_key ) {
			$params['meta_key']     = $meta_key;
			$params['orderby_meta'] = $orderby_meta;
		}

		$this->query_base = array(
			'search'          => $search,
			'category_tt_id'  => $tt_id,
			'stock_status'    => $stock_args,
			'exclude_private' => $params['exclude_private'],
		);

		$this->summary_stats = WC_Inventory_Overview_Summary::build( $this->query_base );

		$result = WC_Inventory_Overview_Repository::query_products( $params );

		$flat = $result['products'];

		$parent_ids = array();
		foreach ( $flat as $p ) {
			if ( $p->is_type( 'variation' ) ) {
				$parent_ids[] = $p->get_parent_id();
			}
		}
		$parent_ids = array_values( array_unique( array_filter( array_map( 'absint', $parent_ids ) ) ) );
		$this->parent_cache = array();
		if ( $parent_ids ) {
			$parents = wc_get_products(
				array(
					'include'  => $parent_ids,
					'limit'    => -1,
					'return'   => 'objects',
					'paginate' => false,
				)
			);
			foreach ( $parents as $par ) {
				$this->parent_cache[ $par->get_id() ] = $par;
			}
		}

		$ids = array_map(
			static function ( $p ) {
				return $p->get_id();
			},
			$flat
		);
		if ( $ids ) {
			update_meta_cache( 'post', $ids );
		}

		$this->items = $flat;

		$by_parent = array();
		foreach ( $flat as $p ) {
			if ( $p->is_type( 'variation' ) ) {
				$pid = $p->get_parent_id();
				if ( ! isset( $by_parent[ $pid ] ) ) {
					$by_parent[ $pid ] = array();
				}
				$by_parent[ $pid ][] = $p;
			}
		}

		$groups    = array();
		$seen_root = array();

		foreach ( $flat as $p ) {
			if ( $p->is_type( 'variation' ) ) {
				continue;
			}
			$id = $p->get_id();
			if ( isset( $seen_root[ $id ] ) ) {
				continue;
			}
			$seen_root[ $id ] = true;
			if ( $p->is_type( 'variable' ) ) {
				$children = WC_Inventory_Overview_Repository::query_variations_for_parent( $id, $this->query_base );
			} else {
				$children = isset( $by_parent[ $id ] ) ? $by_parent[ $id ] : array();
			}
			$groups[] = array(
				'parent'   => $p,
				'children' => $children,
			);
		}

		foreach ( $by_parent as $pid => $children ) {
			if ( isset( $seen_root[ $pid ] ) ) {
				continue;
			}
			$parent = wc_get_product( $pid );
			if ( ! $parent ) {
				continue;
			}
			$seen_root[ $pid ] = true;
			if ( $parent->is_type( 'variable' ) ) {
				$children = WC_Inventory_Overview_Repository::query_variations_for_parent( $pid, $this->query_base );
			}
			$groups[] = array(
				'parent'   => $parent,
				'children' => $children,
			);
		}

		$this->item_groups = $groups;

		// M3: Position must be fetched only after the complete groups
		// structure — including variations discovered above by the later
		// per-parent query — is built, and in exactly one bulk call (no
		// per-row queries). Empty for viewers without manage_woocommerce,
		// since the columns are not shown to them either.
		$this->position_map = array();
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$product_on_hand   = array();
			$variation_on_hand = array();

			foreach ( $this->item_groups as $group ) {
				$group_parent   = $group['parent'];
				$group_children = isset( $group['children'] ) && is_array( $group['children'] ) ? $group['children'] : array();

				if ( $group_parent->is_type( 'variable' ) ) {
					foreach ( $group_children as $variation ) {
						if ( $variation instanceof WC_Product && $variation->is_type( 'variation' ) ) {
							$variation_on_hand[ $variation->get_id() ] = self::item_on_hand_qty( $variation );
						}
					}
				} else {
					$product_on_hand[ $group_parent->get_id() ] = self::item_on_hand_qty( $group_parent );
				}
			}

			$this->position_map = WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( $product_on_hand, $variation_on_hand );
		}

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $result['total'] / $per_page ) ),
			)
		);
	}
}
