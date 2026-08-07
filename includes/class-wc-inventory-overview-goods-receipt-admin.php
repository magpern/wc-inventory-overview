<?php
/**
 * Goods Receipt admin controller — "Receive Stock" (M4).
 *
 * List/detail UI, PRG admin-post handlers, notices, and request tokens. Mutations
 * go exclusively through WC_Inventory_Overview_Goods_Receipt_Service. Mirrors
 * WC_Inventory_Overview_PO_Admin's structure for a simpler three-state lifecycle.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Goods Receipt admin UX.
 */
class WC_Inventory_Overview_Goods_Receipt_Admin {

	const NOTICE_QUERY         = 'wc_io_gr';
	const ERR_TRANSIENT_PREFIX = 'wc_io_gr_err_';

	/**
	 * Register admin-post handlers and assets.
	 */
	public static function init() {
		add_action( 'admin_post_wc_io_gr_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wc_io_gr_delete_draft', array( __CLASS__, 'handle_delete_draft' ) );
		add_action( 'admin_post_wc_io_gr_post', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_wc_io_gr_void', array( __CLASS__, 'handle_void' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue Receive Stock admin assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG !== $hook ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS !== $tab ) {
			return;
		}

		wp_enqueue_style(
			'wc-io-purchasing',
			plugins_url( 'assets/purchasing.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script(
			'wc-io-po-admin',
			plugins_url( 'assets/po-admin.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
	}

	/**
	 * Detail URL for a receipt.
	 *
	 * @param int $id Receipt id (0 = new).
	 */
	public static function detail_url( int $id = 0 ): string {
		$args = array(
			'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS,
			'action' => $id > 0 ? 'edit' : 'new',
		);
		if ( $id > 0 ) {
			$args['gr_id'] = $id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Post-confirmation URL.
	 *
	 * @param int $id Receipt id.
	 */
	public static function post_confirm_url( int $id ): string {
		return add_query_arg(
			array(
				'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
				'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS,
				'action' => 'post_confirm',
				'gr_id'  => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Void-confirmation URL.
	 *
	 * @param int $id Receipt id.
	 */
	public static function void_confirm_url( int $id ): string {
		return add_query_arg(
			array(
				'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
				'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS,
				'action' => 'void_confirm',
				'gr_id'  => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * List URL.
	 *
	 * @param array<string,string> $extra Extra query args.
	 */
	public static function list_url( array $extra = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS,
					'action' => 'list',
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render Receive Stock panel (list, detail, post-confirm, void-confirm).
	 *
	 * @param string $action Action.
	 */
	public static function render_panel( string $action ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_RECEIPT ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}

		switch ( $action ) {
			case 'new':
			case 'edit':
			case 'view':
				self::render_detail( $action );
				return;
			case 'new_from_po':
				self::render_new_from_po();
				return;
			case 'post_confirm':
				self::render_post_confirm();
				return;
			case 'void_confirm':
				self::render_void_confirm();
				return;
			default:
				self::render_list();
		}
	}

	/**
	 * "Receive" entry point from a PO detail page (M5). Builds an in-memory
	 * (not yet persisted) line proposal — one line per outstanding PO line,
	 * default qty = outstanding, cost pre-filled from the PO line's unit_cost —
	 * and renders the same editable new-receipt form render_detail() already
	 * builds, pre-populated. Saving it is the same create_draft_from_post() call
	 * M4 already built; no new persistence method (M5 plan §Receiving workflow).
	 */
	private static function render_new_from_po() {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::RECEIVE_PO ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}
		$po_id = isset( $_GET['po_id'] ) ? absint( $_GET['po_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$po    = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		if ( is_wp_error( $po ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Purchase order not found.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}

		$po_lines = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$prefill  = array();
		foreach ( $po_lines as $po_line ) {
			$outstanding = WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $po_line );
			if ( $outstanding <= 0 ) {
				continue;
			}
			$prefill[] = array(
				'po_line_id'        => (int) $po_line['id'],
				'po_number'         => (string) $po['po_number'],
				'product_id'        => (int) $po_line['product_id'],
				'variation_id'      => (int) $po_line['variation_id'],
				'sku_snapshot'      => (string) ( $po_line['sku_snapshot'] ?? '' ),
				'name_snapshot'     => (string) ( $po_line['name_snapshot'] ?? '' ),
				'qty'               => $outstanding,
				'entered_unit_cost' => $po_line['unit_cost'] ?? 0,
			);
		}

		if ( empty( $prefill ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This Purchase Order has no outstanding lines to receive.', 'wc-inventory-overview' ) . '</p></div>';
		}

		self::render_detail( 'new', $prefill, $po );
	}

	/**
	 * Render list.
	 */
	private static function render_list() {
		$list_table = new WC_Inventory_Overview_Goods_Receipts_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wc-io-po-list-header">
			<?php if ( WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( self::detail_url( 0 ) ); ?>">
					<?php esc_html_e( 'Quick Receive Without PO', 'wc-inventory-overview' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
		$list_table->views();
		$list_table->display();
	}

	/**
	 * Render create/edit/view detail.
	 *
	 * @param string                         $action        Action.
	 * @param array<int,array<string,mixed>> $prefill_lines M5: in-memory, not-yet-persisted lines (from render_new_from_po()). Empty for the normal M4 new/edit/view paths.
	 * @param array<string,mixed>|null       $po_context    M5: the PO being received against, when $prefill_lines is non-empty (for the "Receiving against PO-XXXX" banner).
	 */
	private static function render_detail( string $action, array $prefill_lines = array(), $po_context = null ) {
		$id      = isset( $_GET['gr_id'] ) ? absint( $_GET['gr_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$receipt = null;
		$lines   = array();
		$costs   = array();

		if ( $id > 0 ) {
			$receipt = WC_Inventory_Overview_Goods_Receipt_Service::get( $id );
			if ( is_wp_error( $receipt ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Goods Receipt not found.', 'wc-inventory-overview' ) . '</p></div>';
				return;
			}
			$lines = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $id );
			$costs = WC_Inventory_Overview_Receipt_Costs::list_for_receipt( $id );
		} elseif ( ! empty( $prefill_lines ) ) {
			$lines = $prefill_lines;
		}

		$is_new   = ( null === $receipt );
		$status   = $is_new ? WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT : (string) $receipt['status'];
		$editable = $is_new || WC_Inventory_Overview_Goods_Receipt_Lifecycle::is_editable( $status );
		$can_edit = $editable && WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT );
		$actions  = $is_new ? array( WC_Inventory_Overview_Goods_Receipt_Lifecycle::ACTION_EDIT ) : WC_Inventory_Overview_Goods_Receipt_Lifecycle::available_actions( $status );

		$title = $is_new
			? ( $po_context
				? sprintf(
					/* translators: %s: PO number */
					__( 'New Goods Receipt (Receiving against %s)', 'wc-inventory-overview' ),
					$po_context['po_number']
				)
				: __( 'New Goods Receipt (Quick Receive Without PO)', 'wc-inventory-overview' ) )
			: sprintf(
				/* translators: %s: receipt number */
				__( 'Goods Receipt %s', 'wc-inventory-overview' ),
				$receipt['receipt_number']
			);
		?>
		<p><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'Back to Goods Receipts', 'wc-inventory-overview' ); ?></a></p>
		<h2 class="wc-io-po-detail-title">
			<?php echo esc_html( $title ); ?>
			<?php if ( ! $is_new ) : ?>
				<span class="wc-io-gr-status wc-io-gr-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( WC_Inventory_Overview_Goods_Receipt_Lifecycle::status_label( $status ) ); ?></span>
			<?php endif; ?>
		</h2>
		<?php if ( $po_context ) : ?>
			<p class="notice notice-info inline"><?php echo esc_html( sprintf( /* translators: %s: PO number */ __( 'Pre-filled with the outstanding lines of %s. Adjust quantities for a partial receive, remove lines you\'re not receiving now, or add extra direct lines below.', 'wc-inventory-overview' ), $po_context['po_number'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( $can_edit ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wc-io-po-form" id="wc-io-gr-form">
				<input type="hidden" name="action" value="wc_io_gr_save" />
				<input type="hidden" name="gr_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="wc_io_gr_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'gr_save' ) ); ?>" />
				<?php wp_nonce_field( 'wc_io_gr_save_' . $id, 'wc_io_gr_save_nonce' ); ?>

				<?php self::render_header_fields( $receipt, true ); ?>
				<?php self::render_lines_editor( $lines, $receipt, true ); ?>
				<?php self::render_costs_editor( $costs, $receipt, true ); ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Draft', 'wc-inventory-overview' ); ?></button>
				</p>
			</form>
		<?php else : ?>
			<?php self::render_header_fields( $receipt, false ); ?>
			<?php self::render_lines_editor( $lines, $receipt, false ); ?>
			<?php self::render_costs_editor( $costs, $receipt, false ); ?>
		<?php endif; ?>

		<?php if ( ! $is_new ) : ?>
			<?php self::render_totals( $receipt ); ?>
			<?php self::render_action_forms( $receipt, $actions ); ?>
			<?php if ( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_VOIDED === $status ) : ?>
				<p><strong><?php esc_html_e( 'Void reason:', 'wc-inventory-overview' ); ?></strong> <?php echo esc_html( (string) ( $receipt['void_reason'] ?? '' ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Header fields.
	 *
	 * @param array<string,mixed>|null $receipt  Receipt row.
	 * @param bool                     $editable Editable.
	 */
	private static function render_header_fields( $receipt, bool $editable ) {
		$supplier_id = $receipt['supplier_id'] ?? 0;
		$suppliers   = WC_Inventory_Overview_Suppliers::list(
			array(
				'status'   => 'active',
				'per_page' => 200,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);
		?>
		<h3><?php esc_html_e( 'Header', 'wc-inventory-overview' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wc_io_gr_supplier_id"><?php esc_html_e( 'Supplier (optional)', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<select name="wc_io_gr_supplier_id" id="wc_io_gr_supplier_id">
							<option value="0"><?php esc_html_e( 'No supplier', 'wc-inventory-overview' ); ?></option>
							<?php foreach ( $suppliers as $supplier ) : ?>
								<option value="<?php echo esc_attr( (string) $supplier['id'] ); ?>" <?php selected( (int) $supplier_id, (int) $supplier['id'] ); ?>>
									<?php echo esc_html( $supplier['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( (string) ( $receipt['supplier_name_snapshot'] ?? '—' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_gr_reference"><?php esc_html_e( 'Reference', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="text" class="regular-text" name="wc_io_gr_reference" id="wc_io_gr_reference" value="<?php echo esc_attr( (string) ( $receipt['reference'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $receipt['reference'] ?? '—' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_gr_currency"><?php esc_html_e( 'Currency', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<select name="wc_io_gr_currency" id="wc_io_gr_currency">
							<?php foreach ( array( 'EUR', 'USD', 'SEK' ) as $cur ) : ?>
								<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( (string) ( $receipt['currency'] ?? 'EUR' ), $cur ); ?>><?php echo esc_html( $cur ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( (string) ( $receipt['currency'] ?? '' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_gr_exchange_rate_to_eur"><?php esc_html_e( 'Exchange rate to EUR (blank = looked up)', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="text" name="wc_io_gr_exchange_rate_to_eur" id="wc_io_gr_exchange_rate_to_eur" value="" placeholder="<?php echo esc_attr( (string) ( $receipt['exchange_rate_to_eur'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $receipt['exchange_rate_to_eur'] ?? '' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_gr_exchange_rate_date"><?php esc_html_e( 'Exchange rate date', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="date" name="wc_io_gr_exchange_rate_date" id="wc_io_gr_exchange_rate_date" value="<?php echo esc_attr( (string) ( $receipt['exchange_rate_date'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $receipt['exchange_rate_date'] ?? '—' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_gr_note"><?php esc_html_e( 'Notes', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<textarea class="large-text" rows="3" name="wc_io_gr_note" id="wc_io_gr_note"><?php echo esc_textarea( (string) ( $receipt['note'] ?? '' ) ); ?></textarea>
					<?php else : ?>
						<?php echo nl2br( esc_html( (string) ( $receipt['note'] ?? '' ) ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Lines table / editor. Product picker excludes variable/grouped/external
	 * parents and non-stock-managed products (server-side re-validated regardless).
	 *
	 * @param array<int,array<string,mixed>> $lines    Lines.
	 * @param array<string,mixed>|null       $receipt  Receipt.
	 * @param bool                           $editable Editable.
	 */
	private static function render_lines_editor( array $lines, $receipt, bool $editable ) {
		?>
		<h3><?php esc_html_e( 'Lines', 'wc-inventory-overview' ); ?></h3>
		<table class="widefat striped wc-io-po-lines" id="wc-io-gr-lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Qty', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Unit Cost', 'wc-inventory-overview' ); ?></th>
					<?php if ( ! $editable ) : ?>
						<th><?php esc_html_e( 'True Unit Cost (EUR)', 'wc-inventory-overview' ); ?></th>
						<th><?php esc_html_e( 'New Stock', 'wc-inventory-overview' ); ?></th>
						<th><?php esc_html_e( 'New Avg. Cost', 'wc-inventory-overview' ); ?></th>
					<?php endif; ?>
					<?php if ( $editable ) : ?>
						<th></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php
			if ( empty( $lines ) && $editable ) {
				self::render_line_row( null, 0, true );
			} else {
				foreach ( $lines as $index => $line ) {
					self::render_line_row( $line, $index, $editable );
				}
			}
			?>
			</tbody>
		</table>
		<?php if ( $editable ) : ?>
			<p><button type="button" class="button" id="wc-io-gr-add-line"><?php esc_html_e( 'Add line', 'wc-inventory-overview' ); ?></button></p>
			<script type="text/html" id="tmpl-wc-io-gr-line-row">
				<?php self::render_line_row( null, '__INDEX__', true ); ?>
			</script>
			<p class="description"><?php esc_html_e( 'Product picker excludes variable parents, grouped, and external products, and products without stock management enabled.', 'wc-inventory-overview' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Single line row.
	 *
	 * @param array<string,mixed>|null $line     Line.
	 * @param int|string               $index    Index.
	 * @param bool                     $editable Editable.
	 */
	private static function render_line_row( $line, $index, bool $editable ) {
		$product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
		$variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
		$search_id    = $variation_id > 0 ? $variation_id : $product_id;
		$name         = (string) ( $line['name_snapshot'] ?? '' );
		$sku          = (string) ( $line['sku_snapshot'] ?? '' );
		$po_line_id   = isset( $line['po_line_id'] ) ? (int) $line['po_line_id'] : 0;
		?>
		<tr class="wc-io-gr-line-row">
			<td>
				<?php if ( $editable ) : ?>
					<input type="hidden" name="wc_io_gr_line_po_line_id[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( (string) $po_line_id ); ?>" />
					<select class="wc-product-search" style="width:220px;" name="wc_io_gr_line_product[<?php echo esc_attr( (string) $index ); ?>]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'wc-inventory-overview' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
						<?php if ( $search_id > 0 ) : ?>
							<option value="<?php echo esc_attr( (string) $search_id ); ?>" selected="selected"><?php echo esc_html( $name ? $name : (string) $search_id ); ?></option>
						<?php endif; ?>
					</select>
					<?php if ( $po_line_id > 0 ) : ?>
						<p class="description"><?php echo esc_html( sprintf( /* translators: %s: PO line id */ __( 'From PO line #%s', 'wc-inventory-overview' ), (string) $po_line_id ) ); ?></p>
						<?php $outstanding = self::current_po_line_outstanding( $po_line_id ); ?>
						<?php if ( null !== $outstanding ) : ?>
							<p class="description wc-io-gr-line-outstanding" data-po-line-id="<?php echo esc_attr( (string) $po_line_id ); ?>" data-outstanding="<?php echo esc_attr( (string) $outstanding ); ?>">
								<?php echo esc_html( sprintf( /* translators: %s: outstanding quantity, 4 decimals */ __( 'Outstanding: %s', 'wc-inventory-overview' ), number_format( $outstanding, 4, '.', '' ) ) ); ?>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				<?php else : ?>
					<?php echo esc_html( $name ); ?>
					<?php if ( $po_line_id > 0 ) : ?>
						<?php $fulfils = self::po_line_link( $po_line_id ); ?>
						<?php if ( $fulfils ) : ?>
							<p class="description"><?php esc_html_e( 'Fulfils:', 'wc-inventory-overview' ); ?> <?php echo $fulfils; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- po_line_link() returns pre-escaped markup. ?></p>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="number" step="0.0001" min="0" name="wc_io_gr_line_qty[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( (string) ( $line['qty'] ?? '1' ) ); ?>" required />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['qty'] ?? '0' ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="number" step="0.000001" min="0" name="wc_io_gr_line_unit_cost[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( (string) ( $line['entered_unit_cost'] ?? '0' ) ); ?>" required />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['entered_unit_cost'] ?? '0' ) ); ?>
				<?php endif; ?>
			</td>
			<?php if ( ! $editable ) : ?>
				<td><?php echo esc_html( (string) ( $line['true_unit_cost'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $line['new_stock'] ?? '—' ) ); ?></td>
				<td><?php echo esc_html( (string) ( $line['new_average_unit_cost'] ?? '—' ) ); ?></td>
			<?php endif; ?>
			<?php if ( $editable ) : ?>
				<td><button type="button" class="button-link-delete wc-io-gr-remove-line"><?php esc_html_e( 'Remove', 'wc-inventory-overview' ); ?></button></td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * Current outstanding quantity for a PO-linked line, read fresh at render time
	 * (M5 plan §Receiving workflow — never cached/stale, since another receipt may
	 * have posted against the same line moments ago). Reuses the existing computed
	 * outstanding figure; no business logic duplicated here.
	 *
	 * @param int $po_line_id PO line id.
	 * @return float|null Outstanding quantity, or null if the PO line can no longer be resolved.
	 */
	private static function current_po_line_outstanding( int $po_line_id ): ?float {
		$po_line = WC_Inventory_Overview_Purchase_Order_Lines::get( $po_line_id );
		if ( is_wp_error( $po_line ) ) {
			return null;
		}
		return WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $po_line );
	}

	/**
	 * Build a "PO-XXXX line N" link for a PO-linked receipt line (M5). Read-only,
	 * computed at render time — po_line_id is the only stored linkage, this is
	 * never persisted redundantly (M5 plan §Audit model).
	 *
	 * @param int $po_line_id PO line id.
	 * @return string Pre-escaped HTML link, or '' if the PO line/PO can no longer be resolved.
	 */
	private static function po_line_link( int $po_line_id ): string {
		$po_line = WC_Inventory_Overview_Purchase_Order_Lines::get( $po_line_id );
		if ( is_wp_error( $po_line ) ) {
			return '';
		}
		$po = WC_Inventory_Overview_Purchase_Orders::get( (int) $po_line['po_id'] );
		if ( is_wp_error( $po ) ) {
			return '';
		}
		$url = WC_Inventory_Overview_PO_Admin::detail_url( (int) $po['id'] );
		return sprintf(
			'<a href="%s">%s (line %d)</a>',
			esc_url( $url ),
			esc_html( (string) $po['po_number'] ),
			(int) $po_line['line_index'] + 1
		);
	}

	/**
	 * Landed cost rows editor.
	 *
	 * @param array<int,array<string,mixed>> $costs    Cost rows.
	 * @param array<string,mixed>|null       $receipt  Receipt.
	 * @param bool                           $editable Editable.
	 */
	private static function render_costs_editor( array $costs, $receipt, bool $editable ) {
		$labels = WC_Inventory_Overview_Goods_Receipt_Costing::landed_cost_type_labels();
		?>
		<h3><?php esc_html_e( 'Landed Costs', 'wc-inventory-overview' ); ?></h3>
		<table class="widefat striped" id="wc-io-gr-costs">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Currency', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Note', 'wc-inventory-overview' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$rows = $editable ? array_pad( $costs, count( $costs ) > 0 ? count( $costs ) : 1, array() ) : $costs;
			foreach ( $rows as $i => $row ) :
				?>
				<tr>
					<td>
						<?php if ( $editable ) : ?>
							<select name="wc_io_gr_cost_type[<?php echo esc_attr( (string) $i ); ?>]">
								<option value=""><?php esc_html_e( '—', 'wc-inventory-overview' ); ?></option>
								<?php foreach ( $labels as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( (string) ( $row['cost_type'] ?? '' ), $slug ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<?php echo esc_html( $labels[ $row['cost_type'] ] ?? (string) $row['cost_type'] ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $editable ) : ?>
							<input type="number" step="0.0001" min="0" name="wc_io_gr_cost_amount[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['entered_amount'] ?? '' ) ); ?>" />
						<?php else : ?>
							<?php echo esc_html( (string) ( $row['entered_amount'] ?? '' ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $editable ) : ?>
							<select name="wc_io_gr_cost_currency[<?php echo esc_attr( (string) $i ); ?>]">
								<?php foreach ( array( 'EUR', 'USD', 'SEK' ) as $cur ) : ?>
									<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( (string) ( $row['entered_currency'] ?? 'EUR' ), $cur ); ?>><?php echo esc_html( $cur ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<?php echo esc_html( (string) ( $row['entered_currency'] ?? '' ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $editable ) : ?>
							<input type="text" name="wc_io_gr_cost_note[<?php echo esc_attr( (string) $i ); ?>]" value="<?php echo esc_attr( (string) ( $row['note'] ?? '' ) ); ?>" />
						<?php else : ?>
							<?php echo esc_html( (string) ( $row['note'] ?? '' ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Totals summary (posted/voided/draft alike).
	 *
	 * @param array<string,mixed> $receipt Receipt.
	 */
	private static function render_totals( array $receipt ) {
		?>
		<h3><?php esc_html_e( 'Totals', 'wc-inventory-overview' ); ?></h3>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Product subtotal (EUR)', 'wc-inventory-overview' ); ?></th><td><?php echo esc_html( (string) $receipt['product_subtotal'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Landed total (EUR)', 'wc-inventory-overview' ); ?></th><td><?php echo esc_html( (string) $receipt['landed_total'] ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Receipt total (EUR)', 'wc-inventory-overview' ); ?></th><td><?php echo esc_html( (string) $receipt['receipt_total'] ); ?></td></tr>
		</table>
		<?php
	}

	/**
	 * Lifecycle action forms (separate POSTs for PRG + tokens). Post/void require a
	 * confirmation screen, not a one-click submit.
	 *
	 * @param array<string,mixed>|null $receipt Receipt.
	 * @param array<int,string>        $actions Available actions.
	 */
	private static function render_action_forms( $receipt, array $actions ) {
		if ( null === $receipt ) {
			return;
		}
		$id = (int) $receipt['id'];
		echo '<div class="wc-io-po-actions"><h3>' . esc_html__( 'Actions', 'wc-inventory-overview' ) . '</h3>';

		if ( in_array( WC_Inventory_Overview_Goods_Receipt_Lifecycle::ACTION_POST, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT ) ) {
			echo '<a class="button button-primary" href="' . esc_url( self::post_confirm_url( $id ) ) . '">' . esc_html__( 'Post', 'wc-inventory-overview' ) . '</a> ';
		}
		if ( in_array( WC_Inventory_Overview_Goods_Receipt_Lifecycle::ACTION_VOID, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_RECEIPT ) ) {
			echo '<a class="button" href="' . esc_url( self::void_confirm_url( $id ) ) . '">' . esc_html__( 'Void', 'wc-inventory-overview' ) . '</a> ';
		}
		if ( in_array( WC_Inventory_Overview_Goods_Receipt_Lifecycle::ACTION_DELETE_DRAFT, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::DELETE_RECEIPT ) ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
				<input type="hidden" name="action" value="wc_io_gr_delete_draft" />
				<input type="hidden" name="gr_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="wc_io_gr_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'gr_delete_draft' ) ); ?>" />
				<?php wp_nonce_field( 'wc_io_gr_delete_draft_' . $id, 'wc_io_gr_delete_draft_nonce' ); ?>
				<button type="submit" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this draft?', 'wc-inventory-overview' ) ); ?>');">
					<?php esc_html_e( 'Delete Draft', 'wc-inventory-overview' ); ?>
				</button>
			</form>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Explicit post-confirmation screen. Computed preview only — no writes.
	 */
	private static function render_post_confirm() {
		$id = isset( $_GET['gr_id'] ) ? absint( $_GET['gr_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}
		$receipt = WC_Inventory_Overview_Goods_Receipt_Service::get( $id );
		if ( is_wp_error( $receipt ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $receipt->get_error_message() ) . '</p></div>';
			return;
		}
		if ( WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_DRAFT !== $receipt['status'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Only a draft Goods Receipt can be posted.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}
		$lines            = WC_Inventory_Overview_Receipt_Lines::list_for_receipt( $id );
		$over_receipt_map = WC_Inventory_Overview_Goods_Receipt_Service::preview_po_over_receipt( $id );
		if ( is_wp_error( $over_receipt_map ) ) {
			$over_receipt_map = array();
		}
		$over_receipt_lines = array();
		foreach ( $lines as $line ) {
			$assessment = $over_receipt_map[ (int) $line['id'] ] ?? null;
			if ( $assessment && ! empty( $assessment['over_receipt'] ) ) {
				$over_receipt_lines[ (int) $line['id'] ] = $assessment;
			}
		}
		?>
		<p><a href="<?php echo esc_url( self::detail_url( $id ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wc-inventory-overview' ); ?></a></p>
		<h2><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Confirm posting %s', 'wc-inventory-overview' ), $receipt['receipt_number'] ) ); ?></h2>
		<p class="notice notice-info inline"><strong><?php esc_html_e( 'Preview only — nothing has been saved and stock has not changed.', 'wc-inventory-overview' ); ?></strong></p>
		<?php if ( ! empty( $over_receipt_lines ) ) : ?>
			<div class="notice notice-warning inline wc-io-gr-over-receipt-warning">
				<p><strong><?php esc_html_e( 'Over-receipt warning', 'wc-inventory-overview' ); ?></strong></p>
				<ul>
					<?php foreach ( $lines as $line ) : ?>
						<?php $assessment = $over_receipt_lines[ (int) $line['id'] ] ?? null; ?>
						<?php if ( $assessment ) : ?>
							<li>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: product name, 2: over-received quantity, 4 decimals */
										__( '%1$s exceeds the current outstanding quantity by %2$s units. Posting will over-receive this PO line.', 'wc-inventory-overview' ),
										(string) $line['name_snapshot'],
										number_format( (float) $assessment['qty_over'], 4, '.', '' )
									)
								);
								?>
							</li>
						<?php endif; ?>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Qty', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'True unit cost (EUR)', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Current stock', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'New stock', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'New avg. cost', 'wc-inventory-overview' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $lines as $line ) :
				$purchasable_id = (int) $line['variation_id'] > 0 ? (int) $line['variation_id'] : (int) $line['product_id'];
				$product        = wc_get_product( $purchasable_id );
				$preview        = $product ? WC_Inventory_Overview_Goods_Receipt_Costing::preview_line( $product, (float) $line['qty'], (float) $line['true_unit_cost'] ) : null;
				$is_over        = isset( $over_receipt_lines[ (int) $line['id'] ] );
				?>
				<tr<?php echo $is_over ? ' class="wc-io-gr-line-over-receipt"' : ''; ?>>
					<td><?php echo esc_html( (string) $line['name_snapshot'] ); ?></td>
					<td><?php echo esc_html( (string) $line['qty'] ); ?></td>
					<td><?php echo esc_html( (string) $line['true_unit_cost'] ); ?></td>
					<td><?php echo esc_html( $preview ? (string) $preview['old_stock'] : '—' ); ?></td>
					<td><?php echo esc_html( $preview ? (string) $preview['new_stock'] : '—' ); ?></td>
					<td><?php echo esc_html( $preview ? (string) $preview['new_average_unit_cost'] : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_io_gr_post" />
			<input type="hidden" name="gr_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="wc_io_gr_confirm" value="1" />
			<input type="hidden" name="wc_io_gr_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'gr_post' ) ); ?>" />
			<?php wp_nonce_field( 'wc_io_gr_post_' . $id, 'wc_io_gr_post_nonce' ); ?>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm & Post', 'wc-inventory-overview' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Explicit void-confirmation screen with mandatory reason.
	 */
	private static function render_void_confirm() {
		$id = isset( $_GET['gr_id'] ) ? absint( $_GET['gr_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_RECEIPT ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}
		$receipt = WC_Inventory_Overview_Goods_Receipt_Service::get( $id );
		if ( is_wp_error( $receipt ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $receipt->get_error_message() ) . '</p></div>';
			return;
		}
		?>
		<p><a href="<?php echo esc_url( self::detail_url( $id ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wc-inventory-overview' ); ?></a></p>
		<h2><?php echo esc_html( sprintf( /* translators: %s: receipt number */ __( 'Void %s', 'wc-inventory-overview' ), $receipt['receipt_number'] ) ); ?></h2>
		<p class="notice notice-warning inline"><?php esc_html_e( 'Voiding reverses this receipt\'s stock and cost contribution using current stock levels. If units have since been sold, voiding may be rejected.', 'wc-inventory-overview' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_io_gr_void" />
			<input type="hidden" name="gr_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="wc_io_gr_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'gr_void' ) ); ?>" />
			<?php wp_nonce_field( 'wc_io_gr_void_' . $id, 'wc_io_gr_void_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wc_io_gr_void_reason"><?php esc_html_e( 'Void reason (required)', 'wc-inventory-overview' ); ?></label></th>
					<td><textarea class="large-text" rows="3" name="wc_io_gr_void_reason" id="wc_io_gr_void_reason" required></textarea></td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm & Void', 'wc-inventory-overview' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Handle save (create or update draft).
	 */
	public static function handle_save() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::EDIT_RECEIPT );
		$id = isset( $_POST['gr_id'] ) ? absint( $_POST['gr_id'] ) : 0;
		check_admin_referer( 'wc_io_gr_save_' . $id, 'wc_io_gr_save_nonce' );
		self::require_token( 'gr_save' );

		$src = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $id <= 0 ) {
			$result = WC_Inventory_Overview_Goods_Receipt_Service::create_draft_from_post( $src );
			if ( is_wp_error( $result ) ) {
				self::fail_and_redirect( $result, self::detail_url( 0 ) );
			}
			self::success_redirect( self::detail_url( (int) $result ), 'saved' );
		}

		$result = WC_Inventory_Overview_Goods_Receipt_Service::update_draft_from_post( $id, $src );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::detail_url( $id ), 'saved' );
	}

	/**
	 * Handle draft delete.
	 */
	public static function handle_delete_draft() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::DELETE_RECEIPT );
		$id = isset( $_POST['gr_id'] ) ? absint( $_POST['gr_id'] ) : 0;
		check_admin_referer( 'wc_io_gr_delete_draft_' . $id, 'wc_io_gr_delete_draft_nonce' );
		self::require_token( 'gr_delete_draft' );
		$result = WC_Inventory_Overview_Goods_Receipt_Service::delete_draft( $id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::list_url(), 'deleted' );
	}

	/**
	 * Handle post.
	 */
	public static function handle_post() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::POST_RECEIPT );
		$id = isset( $_POST['gr_id'] ) ? absint( $_POST['gr_id'] ) : 0;
		check_admin_referer( 'wc_io_gr_post_' . $id, 'wc_io_gr_post_nonce' );
		if ( empty( $_POST['wc_io_gr_confirm'] ) || '1' !== (string) $_POST['wc_io_gr_confirm'] ) {
			wp_die( esc_html__( 'Posting must be explicitly confirmed.', 'wc-inventory-overview' ), 400 );
		}
		$token  = isset( $_POST['wc_io_gr_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_gr_request_token'] ) ) : '';
		$result = WC_Inventory_Overview_Goods_Receipt_Service::post( $id, $token );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::detail_url( $id ), 'posted' );
	}

	/**
	 * Handle void.
	 */
	public static function handle_void() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::VOID_RECEIPT );
		$id = isset( $_POST['gr_id'] ) ? absint( $_POST['gr_id'] ) : 0;
		check_admin_referer( 'wc_io_gr_void_' . $id, 'wc_io_gr_void_nonce' );
		$reason = isset( $_POST['wc_io_gr_void_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_io_gr_void_reason'] ) ) : '';
		$token  = isset( $_POST['wc_io_gr_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_gr_request_token'] ) ) : '';
		$result = WC_Inventory_Overview_Goods_Receipt_Service::void( $id, $reason, $token );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::detail_url( $id ), 'voided' );
	}

	/**
	 * Capability guard.
	 *
	 * @param string $cap_action Cap map key.
	 */
	private static function guard( string $cap_action ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( $cap_action ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ), 403 );
		}
	}

	/**
	 * Require and consume request token.
	 *
	 * @param string $context Context.
	 */
	private static function require_token( string $context ) {
		$token = isset( $_POST['wc_io_gr_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_gr_request_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified nonce first.
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, $context ) ) {
			wp_die( esc_html__( 'This form has already been submitted or expired. Please go back and try again.', 'wc-inventory-overview' ), 400 );
		}
	}

	/**
	 * Success PRG redirect.
	 *
	 * @param string $url    URL.
	 * @param string $notice Notice key.
	 */
	private static function success_redirect( string $url, string $notice ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, $notice, $url ) );
		exit;
	}

	/**
	 * Error PRG redirect with transient message.
	 *
	 * @param WP_Error $error Error.
	 * @param string   $url   URL.
	 */
	private static function fail_and_redirect( WP_Error $error, string $url ) {
		set_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id(), $error->get_error_message(), 120 );
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, 'err', $url ) );
		exit;
	}

	/**
	 * Render notices (called from purchasing page).
	 */
	public static function render_notices() {
		if ( ! isset( $_GET[ self::NOTICE_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status   = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'   => __( 'Goods Receipt saved.', 'wc-inventory-overview' ),
			'posted'  => __( 'Goods Receipt posted. Stock and cost have been updated.', 'wc-inventory-overview' ),
			'voided'  => __( 'Goods Receipt voided.', 'wc-inventory-overview' ),
			'deleted' => __( 'Draft Goods Receipt deleted.', 'wc-inventory-overview' ),
		);
		if ( 'err' === $status ) {
			$error = get_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id() );
			if ( $error ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $error ) . '</p></div>';
				delete_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id() );
			}
			return;
		}
		if ( isset( $messages[ $status ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $status ] ) . '</p></div>';
		}
	}
}
