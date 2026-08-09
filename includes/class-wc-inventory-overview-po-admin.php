<?php
/**
 * Purchase Order admin controller (M2-D).
 *
 * List/detail UI, PRG admin-post handlers, timeline, notices, and request tokens.
 * Mutations go exclusively through WC_Inventory_Overview_PO_Service.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * PO admin UX.
 */
class WC_Inventory_Overview_PO_Admin {

	const NOTICE_QUERY         = 'wc_io_po';
	const ERR_TRANSIENT_PREFIX = 'wc_io_po_err_';
	const FIELD_ERR_PREFIX     = 'wc_io_po_field_err_';

	/**
	 * Register admin-post handlers and assets.
	 */
	public static function init() {
		$actions = array(
			'wc_io_po_save',
			'wc_io_po_place',
			'wc_io_po_cancel',
			'wc_io_po_close_short',
			'wc_io_po_delete_draft',
			'wc_io_po_duplicate',
		);
		foreach ( $actions as $action ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, 'handle_' . substr( $action, strlen( 'wc_io_po_' ) ) ) );
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue PO admin assets on the purchasing page.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'wc-io-purchasing',
			plugins_url( 'assets/purchasing.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS !== $tab ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script(
			'wc-io-po-admin',
			plugins_url( 'assets/po-admin.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery', 'wc-enhanced-select' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);

		// 'new' is the only action rendering a blank, not-yet-submitted PO.
		// An existing DRAFT PO is also editable (same field IDs, same
		// supplier <select>), so this explicit flag -- not merely "does the
		// field exist" -- is what the client-side suggestion behavior gates
		// on, per docs/milestones/m10-implementation-plan.md non-goal #1:
		// editing an existing PO must never run suggestion logic, even if
		// the operator changes its supplier.
		$action    = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_new_po = ( 'new' === $action );

		wp_localize_script(
			'wc-io-po-admin',
			'wcIoPoAdmin',
			array(
				'i18n'                => array(
					'removeLine' => __( 'Remove line', 'wc-inventory-overview' ),
					'product'    => __( 'Search for a product…', 'wc-inventory-overview' ),
				),
				'isNewPurchaseOrder'  => $is_new_po,
				'leadTimeSuggestions' => $is_new_po ? self::lead_time_suggestions_for_localize() : array(),
			)
		);
	}

	/**
	 * Suggestion data for the new-PO create screen (M10, docs/milestones/m10-implementation-plan.md
	 * WP-C) -- keyed by supplier ID, one bulk pass over the same active
	 * supplier list render_header_fields() also loads for the dropdown.
	 * Caller (enqueue_assets()) already confirmed we're on the create
	 * screen before calling this.
	 *
	 * @return array<int,array{days:?int,confidence:?string,source:string}>
	 */
	private static function lead_time_suggestions_for_localize(): array {
		$suppliers = WC_Inventory_Overview_Suppliers::list(
			array(
				'status'   => 'active',
				'per_page' => 200,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);

		$suggestions = WC_Inventory_Overview_Expected_Date_Suggestion_Service::get_suggestions_bulk( $suppliers );

		$localized = array();
		foreach ( $suggestions as $supplier_id => $suggestion ) {
			if ( null === $suggestion['days'] ) {
				continue; // Only entries with an actual suggestion are sent to the client.
			}
			$localized[ $supplier_id ] = array(
				'days'       => $suggestion['days'],
				'confidence' => $suggestion['confidence'],
			);
		}

		return $localized;
	}

	/**
	 * Detail URL for a PO.
	 *
	 * @param int $po_id PO id (0 = new).
	 */
	public static function detail_url( int $po_id = 0 ): string {
		$args = array(
			'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
			'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS,
			'action' => $po_id > 0 ? 'edit' : 'new',
		);
		if ( $po_id > 0 ) {
			$args['po_id'] = $po_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
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
					'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_ORDERS,
					'action' => 'list',
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render PO panel (list or detail).
	 *
	 * @param string $action Action.
	 */
	public static function render_panel( string $action ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VIEW_PO ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}

		if ( 'new' === $action || 'edit' === $action || 'view' === $action ) {
			self::render_detail( $action );
			return;
		}
		self::render_list();
	}

	/**
	 * Render list.
	 */
	private static function render_list() {
		$list_table = new WC_Inventory_Overview_Purchase_Orders_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wc-io-po-list-header">
			<a class="page-title-action" href="<?php echo esc_url( self::detail_url( 0 ) ); ?>">
				<?php esc_html_e( 'Add Purchase Order', 'wc-inventory-overview' ); ?>
			</a>
		</div>
		<?php
		$list_table->views();
		$list_table->display();
	}

	/**
	 * Render create/edit/view detail.
	 *
	 * @param string $action Action.
	 */
	private static function render_detail( string $action ) {
		$po_id = isset( $_GET['po_id'] ) ? absint( $_GET['po_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$po    = null;
		$lines = array();

		if ( $po_id > 0 ) {
			$po = WC_Inventory_Overview_PO_Service::get( $po_id );
			if ( is_wp_error( $po ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Purchase order not found.', 'wc-inventory-overview' ) . '</p></div>';
				return;
			}
			$lines = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $po_id );
		}

		$is_new     = ( null === $po );
		$status     = $is_new ? WC_Inventory_Overview_PO_Statuses::DRAFT : (string) $po['status'];
		$editable   = $is_new || WC_Inventory_Overview_PO_Lifecycle::is_editable( $status );
		$can_edit   = $editable && WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO );
		$field_errs = self::consume_field_errors();
		$actions    = $is_new ? array( WC_Inventory_Overview_PO_Lifecycle::ACTION_EDIT ) : WC_Inventory_Overview_PO_Lifecycle::available_actions( $status );

		$title = $is_new
			? __( 'New Purchase Order', 'wc-inventory-overview' )
			: sprintf(
				/* translators: %s: PO number */
				__( 'Purchase Order %s', 'wc-inventory-overview' ),
				$po['po_number']
			);
		?>
		<p><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'Back to Purchase Orders', 'wc-inventory-overview' ); ?></a></p>
		<h2 class="wc-io-po-detail-title">
			<?php echo esc_html( $title ); ?>
			<?php if ( ! $is_new ) : ?>
				<span class="wc-io-po-status wc-io-po-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( WC_Inventory_Overview_PO_Statuses::label( $status ) ); ?></span>
				<?php
				$delayed = WC_Inventory_Overview_PO_Delay::is_po_delayed(
					$status,
					$po['expected_date'] ?? null,
					(string) ( $po['expected_confidence'] ?? 'unknown' ),
					$lines,
					WC_Inventory_Overview_PO_Delay::grace_days_from_option()
				);
				if ( $delayed ) :
					?>
					<span class="wc-io-po-delayed"><?php esc_html_e( 'Delayed', 'wc-inventory-overview' ); ?></span>
				<?php endif; ?>
			<?php endif; ?>
		</h2>

		<?php if ( $can_edit ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wc-io-po-form" id="wc-io-po-form">
				<input type="hidden" name="action" value="wc_io_po_save" />
				<input type="hidden" name="po_id" value="<?php echo esc_attr( (string) $po_id ); ?>" />
				<input type="hidden" name="wc_io_po_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'save' ) ); ?>" />
				<?php wp_nonce_field( 'wc_io_po_save_' . $po_id, 'wc_io_po_save_nonce' ); ?>

				<?php self::render_header_fields( $po, $field_errs, true ); ?>
				<?php self::render_lines_editor( $lines, $po, $field_errs, true ); ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html( WC_Inventory_Overview_PO_Lifecycle::action_label( WC_Inventory_Overview_PO_Lifecycle::ACTION_EDIT ) ); ?></button>
				</p>
			</form>
		<?php else : ?>
			<?php self::render_header_fields( $po, array(), false ); ?>
			<?php self::render_lines_editor( $lines, $po, array(), false ); ?>
		<?php endif; ?>

		<?php self::render_action_forms( $po, $actions ); ?>
		<?php if ( ! $is_new ) : ?>
			<?php self::render_receiving_history( $po_id, $lines ); ?>
			<?php self::render_timeline( $po_id ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Receiving history (M5): every Goods Receipt line fulfilling one of this PO's
	 * lines, across all receipts (posted or voided). Bulk query, not one per line
	 * (M5 plan §Performance).
	 *
	 * @param int                            $po_id PO id.
	 * @param array<int,array<string,mixed>> $lines This PO's lines (for id list; avoids a second PO-lines query).
	 */
	private static function render_receiving_history( int $po_id, array $lines ) {
		$po_line_ids = wp_list_pluck( $lines, 'id' );
		$rows        = empty( $po_line_ids ) ? array() : WC_Inventory_Overview_Receipt_Lines::list_for_po_line_ids( array_map( 'absint', $po_line_ids ) );
		?>
		<div class="wc-io-po-receiving-history">
			<h3><?php esc_html_e( 'Receiving History', 'wc-inventory-overview' ); ?></h3>
			<?php if ( empty( $rows ) ) : ?>
				<p class="description"><?php esc_html_e( 'No receipts have been posted against this Purchase Order yet.', 'wc-inventory-overview' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Receipt', 'wc-inventory-overview' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wc-inventory-overview' ); ?></th>
							<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
							<th><?php esc_html_e( 'Qty', 'wc-inventory-overview' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( WC_Inventory_Overview_Goods_Receipt_Admin::detail_url( (int) $row['receipt_id'] ) ); ?>"><?php echo esc_html( (string) $row['receipt_number'] ); ?></a></td>
							<td><?php echo esc_html( WC_Inventory_Overview_Goods_Receipt_Lifecycle::status_label( (string) $row['receipt_status'] ) ); ?></td>
							<td><?php echo esc_html( (string) $row['name_snapshot'] ); ?></td>
							<td><?php echo esc_html( (string) $row['qty'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Header fields.
	 *
	 * @param array<string,mixed>|null $po         PO row.
	 * @param array<string,string>     $field_errs Errors.
	 * @param bool                     $editable   Editable.
	 */
	private static function render_header_fields( $po, array $field_errs, bool $editable ) {
		$supplier_id = $po['supplier_id'] ?? 0;
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
			<tr class="<?php echo isset( $field_errs['supplier_id'] ) ? 'form-invalid' : ''; ?>">
				<th scope="row"><label for="wc_io_po_supplier_id"><?php esc_html_e( 'Supplier', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<select name="po[supplier_id]" id="wc_io_po_supplier_id" required>
							<option value=""><?php esc_html_e( 'Select supplier…', 'wc-inventory-overview' ); ?></option>
							<?php foreach ( $suppliers as $supplier ) : ?>
								<option value="<?php echo esc_attr( (string) $supplier['id'] ); ?>" <?php selected( (int) $supplier_id, (int) $supplier['id'] ); ?>>
									<?php echo esc_html( $supplier['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['supplier_name_snapshot'] ?? '' ) ); ?>
					<?php endif; ?>
					<?php self::field_error( $field_errs, 'supplier_id' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_po_supplier_reference"><?php esc_html_e( 'Supplier reference', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="text" class="regular-text" name="po[supplier_reference]" id="wc_io_po_supplier_reference" value="<?php echo esc_attr( (string) ( $po['supplier_reference'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['supplier_reference'] ?? '—' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="<?php echo isset( $field_errs['currency'] ) ? 'form-invalid' : ''; ?>">
				<th scope="row"><label for="wc_io_po_currency"><?php esc_html_e( 'Currency', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<select name="po[currency]" id="wc_io_po_currency">
							<?php foreach ( array( 'EUR', 'USD', 'SEK' ) as $cur ) : ?>
								<option value="<?php echo esc_attr( $cur ); ?>" <?php selected( (string) ( $po['currency'] ?? 'EUR' ), $cur ); ?>><?php echo esc_html( $cur ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['currency'] ?? '' ) ); ?>
					<?php endif; ?>
					<?php self::field_error( $field_errs, 'currency' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_po_order_date"><?php esc_html_e( 'Order date', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="date" name="po[order_date]" id="wc_io_po_order_date" value="<?php echo esc_attr( (string) ( $po['order_date'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['order_date'] ?? '—' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr class="<?php echo isset( $field_errs['expected_date'] ) ? 'form-invalid' : ''; ?>">
				<th scope="row"><label for="wc_io_po_expected_date"><?php esc_html_e( 'Expected date', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<input type="date" name="po[expected_date]" id="wc_io_po_expected_date" value="<?php echo esc_attr( (string) ( $po['expected_date'] ?? '' ) ); ?>" />
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['expected_date'] ?? '—' ) ); ?>
					<?php endif; ?>
					<?php self::field_error( $field_errs, 'expected_date' ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_po_expected_confidence"><?php esc_html_e( 'Confidence', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<select name="po[expected_confidence]" id="wc_io_po_expected_confidence">
							<?php foreach ( array( 'exact', 'estimated', 'unknown' ) as $conf ) : ?>
								<option value="<?php echo esc_attr( $conf ); ?>" <?php selected( (string) ( $po['expected_confidence'] ?? 'unknown' ), $conf ); ?>><?php echo esc_html( $conf ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php echo esc_html( (string) ( $po['expected_confidence'] ?? '' ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wc_io_po_note"><?php esc_html_e( 'Notes', 'wc-inventory-overview' ); ?></label></th>
				<td>
					<?php if ( $editable ) : ?>
						<textarea class="large-text" rows="3" name="po[note]" id="wc_io_po_note"><?php echo esc_textarea( (string) ( $po['note'] ?? '' ) ); ?></textarea>
					<?php else : ?>
						<?php echo nl2br( esc_html( (string) ( $po['note'] ?? '' ) ) ); ?>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Lines table / editor.
	 *
	 * @param array<int,array<string,mixed>> $lines      Lines.
	 * @param array<string,mixed>|null       $po         PO.
	 * @param array<string,string>           $field_errs Errors.
	 * @param bool                           $editable   Editable.
	 */
	private static function render_lines_editor( array $lines, $po, array $field_errs, bool $editable ) {
		$currency = (string) ( $po['currency'] ?? 'EUR' );
		?>
		<h3><?php esc_html_e( 'Lines', 'wc-inventory-overview' ); ?></h3>
		<?php self::field_error( $field_errs, 'lines' ); ?>
		<table class="widefat striped wc-io-po-lines" id="wc-io-po-lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Supplier SKU', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Ordered', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Received', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Cancelled', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Outstanding', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Unit Cost', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Expected', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Confidence', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'wc-inventory-overview' ); ?></th>
					<?php
					if ( $editable ) :
						?>
						<th></th><?php endif; ?>
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
			<p><button type="button" class="button" id="wc-io-po-add-line"><?php esc_html_e( 'Add line', 'wc-inventory-overview' ); ?></button></p>
			<script type="text/html" id="tmpl-wc-io-po-line-row">
				<?php self::render_line_row( null, '__INDEX__', true ); ?>
			</script>
		<?php endif; ?>
		<p class="description"><?php echo esc_html( sprintf( /* translators: %s currency */ __( 'Currency for all lines: %s', 'wc-inventory-overview' ), $currency ) ); ?></p>
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
		$line_id      = $line['id'] ?? '';
		$product_id   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
		$variation_id = isset( $line['variation_id'] ) ? (int) $line['variation_id'] : 0;
		$search_id    = $variation_id > 0 ? $variation_id : $product_id;
		$outstanding  = $line ? WC_Inventory_Overview_Purchase_Order_Lines::outstanding( $line ) : 0;
		$name         = (string) ( $line['name_snapshot'] ?? '' );
		$sku          = (string) ( $line['sku_snapshot'] ?? '' );
		?>
		<tr class="wc-io-po-line-row">
			<td>
				<?php if ( $editable ) : ?>
					<input type="hidden" name="lines[<?php echo esc_attr( (string) $index ); ?>][id]" value="<?php echo esc_attr( (string) $line_id ); ?>" />
					<input type="hidden" name="lines[<?php echo esc_attr( (string) $index ); ?>][variation_id]" value="<?php echo esc_attr( (string) $variation_id ); ?>" class="wc-io-po-variation-id" />
					<select class="wc-product-search" style="width:220px;" name="lines[<?php echo esc_attr( (string) $index ); ?>][product_id]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'wc-inventory-overview' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
						<?php if ( $search_id > 0 ) : ?>
							<option value="<?php echo esc_attr( (string) $search_id ); ?>" selected="selected"><?php echo esc_html( $name ? $name : (string) $search_id ); ?></option>
						<?php endif; ?>
					</select>
				<?php else : ?>
					<?php echo esc_html( $name ); ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $sku ? $sku : '—' ); ?></td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="text" name="lines[<?php echo esc_attr( (string) $index ); ?>][supplier_sku]" value="<?php echo esc_attr( (string) ( $line['supplier_sku'] ?? '' ) ); ?>" />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['supplier_sku'] ?? '—' ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="number" step="0.0001" min="0" name="lines[<?php echo esc_attr( (string) $index ); ?>][qty_ordered]" value="<?php echo esc_attr( (string) ( $line['qty_ordered'] ?? '1' ) ); ?>" required />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['qty_ordered'] ?? '0' ) ); ?>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( (string) ( $line['qty_received'] ?? '0' ) ); ?></td>
			<td><?php echo esc_html( (string) ( $line['qty_cancelled'] ?? '0' ) ); ?></td>
			<td><?php echo esc_html( (string) $outstanding ); ?></td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="number" step="0.0001" min="0" name="lines[<?php echo esc_attr( (string) $index ); ?>][unit_cost]" value="<?php echo esc_attr( (string) ( $line['unit_cost'] ?? '0' ) ); ?>" required />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['unit_cost'] ?? '0' ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="date" name="lines[<?php echo esc_attr( (string) $index ); ?>][expected_date]" value="<?php echo esc_attr( (string) ( $line['expected_date'] ?? '' ) ); ?>" />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['expected_date'] ?? '—' ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<select name="lines[<?php echo esc_attr( (string) $index ); ?>][expected_confidence]">
						<option value=""><?php esc_html_e( 'Inherit', 'wc-inventory-overview' ); ?></option>
						<?php foreach ( array( 'exact', 'estimated', 'unknown' ) as $conf ) : ?>
							<option value="<?php echo esc_attr( $conf ); ?>" <?php selected( (string) ( $line['expected_confidence'] ?? '' ), $conf ); ?>><?php echo esc_html( $conf ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['expected_confidence'] ?? '—' ) ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="text" name="lines[<?php echo esc_attr( (string) $index ); ?>][note]" value="<?php echo esc_attr( (string) ( $line['note'] ?? '' ) ); ?>" />
				<?php else : ?>
					<?php echo esc_html( (string) ( $line['note'] ?? '' ) ); ?>
				<?php endif; ?>
			</td>
			<?php if ( $editable ) : ?>
				<td><button type="button" class="button-link-delete wc-io-po-remove-line"><?php esc_html_e( 'Remove', 'wc-inventory-overview' ); ?></button></td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * Lifecycle action forms (separate POSTs for PRG + tokens).
	 *
	 * @param array<string,mixed>|null $po      PO.
	 * @param array<int,string>        $actions Available actions.
	 */
	private static function render_action_forms( $po, array $actions ) {
		if ( null === $po ) {
			return;
		}
		$po_id = (int) $po['id'];
		$map   = array(
			WC_Inventory_Overview_PO_Lifecycle::ACTION_PLACE        => array( 'wc_io_po_place', WC_Inventory_Overview_Purchasing_Caps::PLACE_PO, 'button-primary' ),
			WC_Inventory_Overview_PO_Lifecycle::ACTION_CANCEL       => array( 'wc_io_po_cancel', WC_Inventory_Overview_Purchasing_Caps::CANCEL_PO, 'button' ),
			WC_Inventory_Overview_PO_Lifecycle::ACTION_CLOSE_SHORT  => array( 'wc_io_po_close_short', WC_Inventory_Overview_Purchasing_Caps::CLOSE_PO, 'button' ),
			WC_Inventory_Overview_PO_Lifecycle::ACTION_DELETE_DRAFT => array( 'wc_io_po_delete_draft', WC_Inventory_Overview_Purchasing_Caps::DELETE_PO, 'button-link-delete' ),
			WC_Inventory_Overview_PO_Lifecycle::ACTION_DUPLICATE    => array( 'wc_io_po_duplicate', WC_Inventory_Overview_Purchasing_Caps::DUPLICATE_PO, 'button' ),
		);
		echo '<div class="wc-io-po-actions"><h3>' . esc_html__( 'Actions', 'wc-inventory-overview' ) . '</h3>';

		// M5: "Receive" entry point — not a WC_Inventory_Overview_PO_Lifecycle action
		// (receiving auto-transitions status via PO_Receiving_Sync, never via this
		// operator-gated map); gated on its own capability and on PO receivability.
		$receivable_statuses = array(
			WC_Inventory_Overview_PO_Statuses::PLACED,
			WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED,
			WC_Inventory_Overview_PO_Statuses::RECEIVED,
		);
		if ( in_array( (string) $po['status'], $receivable_statuses, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::RECEIVE_PO ) ) {
			$receive_url = add_query_arg(
				array(
					'page'   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'    => WC_Inventory_Overview_Purchasing_Page::TAB_RECEIPTS,
					'action' => 'new_from_po',
					'po_id'  => $po_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="button button-primary" href="' . esc_url( $receive_url ) . '">' . esc_html__( 'Receive', 'wc-inventory-overview' ) . '</a> ';
		}

		foreach ( $map as $lifecycle_action => $meta ) {
			if ( ! in_array( $lifecycle_action, $actions, true ) ) {
				continue;
			}
			if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( $meta[1] ) ) {
				continue;
			}
			$admin_action = $meta[0];
			$context      = substr( $admin_action, strlen( 'wc_io_po_' ) );
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wc-io-po-action-form" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="<?php echo esc_attr( $admin_action ); ?>" />
				<input type="hidden" name="po_id" value="<?php echo esc_attr( (string) $po_id ); ?>" />
				<input type="hidden" name="wc_io_po_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( $context ) ); ?>" />
				<?php wp_nonce_field( $admin_action . '_' . $po_id, $admin_action . '_nonce' ); ?>
				<button type="submit" class="button <?php echo esc_attr( $meta[2] ); ?>" <?php echo in_array( $lifecycle_action, array( 'cancel', 'close_short', 'delete_draft' ), true ) ? 'onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'wc-inventory-overview' ) ) . '\');"' : ''; ?>>
					<?php echo esc_html( WC_Inventory_Overview_PO_Lifecycle::action_label( $lifecycle_action ) ); ?>
				</button>
			</form>
			<?php
		}
		echo '</div>';
	}

	/**
	 * Append-only timeline (summary only; no reason codes or JSON).
	 *
	 * @param int $po_id PO id.
	 */
	private static function render_timeline( int $po_id ) {
		$events = WC_Inventory_Overview_PO_Events::list_by_po( $po_id );
		// list_by_po is newest first; show chronological (oldest first) for reading history.
		$events = array_reverse( $events );
		?>
		<div class="wc-io-po-timeline">
			<h3><?php esc_html_e( 'Timeline', 'wc-inventory-overview' ); ?></h3>
			<?php if ( empty( $events ) ) : ?>
				<p class="description"><?php esc_html_e( 'No events yet.', 'wc-inventory-overview' ); ?></p>
			<?php else : ?>
				<ol class="wc-io-po-timeline-list">
					<?php foreach ( $events as $event ) : ?>
						<?php
						$user = get_userdata( (int) ( $event['user_id'] ?? 0 ) );
						$who  = $user ? $user->display_name : __( 'System', 'wc-inventory-overview' );
						$when = ! empty( $event['created_at'] )
							? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (string) $event['created_at'] )
							: '';
						?>
						<li>
							<strong><?php echo esc_html( (string) ( $event['summary'] ?? $event['event_type'] ) ); ?></strong>
							<span class="description"> — <?php echo esc_html( $who ); ?><?php echo $when ? ', ' . esc_html( $when ) : ''; ?></span>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Field error markup.
	 *
	 * @param array<string,string> $errs Errors.
	 * @param string               $key  Key.
	 */
	private static function field_error( array $errs, string $key ) {
		if ( empty( $errs[ $key ] ) ) {
			return;
		}
		echo '<p class="wc-io-po-field-error">' . esc_html( $errs[ $key ] ) . '</p>';
	}

	/**
	 * Handle save (create or update).
	 */
	public static function handle_save() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_save_' . $po_id, 'wc_io_po_save_nonce' );
		self::require_token( 'save' );

		$header = isset( $_POST['po'] ) && is_array( $_POST['po'] ) ? wp_unslash( $_POST['po'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$lines  = self::parse_lines_from_post();

		if ( $po_id <= 0 ) {
			$result = WC_Inventory_Overview_PO_Service::create_draft( $header, $lines );
			if ( is_wp_error( $result ) ) {
				self::fail_and_redirect( $result, self::detail_url( 0 ), $result->get_error_code() );
			}
			self::success_redirect( self::detail_url( (int) $result ), 'saved' );
		}

		$po = WC_Inventory_Overview_PO_Service::get( $po_id );
		if ( is_wp_error( $po ) ) {
			self::fail_and_redirect( $po, self::list_url() );
		}

		if ( WC_Inventory_Overview_PO_Statuses::DRAFT === $po['status'] ) {
			$updated = WC_Inventory_Overview_PO_Service::update_draft( $po_id, $header );
		} elseif ( WC_Inventory_Overview_PO_Statuses::PLACED === $po['status'] ) {
			$updated = WC_Inventory_Overview_PO_Service::update_placed( $po_id, $header );
		} else {
			$updated = new WP_Error( 'wc_io_po_terminal', __( 'This purchase order cannot be edited.', 'wc-inventory-overview' ) );
		}
		if ( is_wp_error( $updated ) ) {
			self::fail_and_redirect( $updated, self::detail_url( $po_id ), $updated->get_error_code() );
		}

		$sync = self::sync_lines( $po_id, $lines );
		if ( is_wp_error( $sync ) ) {
			self::fail_and_redirect( $sync, self::detail_url( $po_id ), $sync->get_error_code() );
		}

		self::success_redirect( self::detail_url( $po_id ), 'saved' );
	}

	/**
	 * Handle place.
	 */
	public static function handle_place() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::PLACE_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_place_' . $po_id, 'wc_io_po_place_nonce' );
		self::require_token( 'place' );
		$result = WC_Inventory_Overview_PO_Service::place( $po_id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $po_id ) );
		}
		self::success_redirect( self::detail_url( $po_id ), 'placed' );
	}

	/**
	 * Handle cancel.
	 */
	public static function handle_cancel() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::CANCEL_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_cancel_' . $po_id, 'wc_io_po_cancel_nonce' );
		self::require_token( 'cancel' );
		$result = WC_Inventory_Overview_PO_Service::cancel( $po_id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $po_id ) );
		}
		self::success_redirect( self::detail_url( $po_id ), 'cancelled' );
	}

	/**
	 * Handle close short.
	 */
	public static function handle_close_short() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::CLOSE_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_close_short_' . $po_id, 'wc_io_po_close_short_nonce' );
		self::require_token( 'close_short' );
		$result = WC_Inventory_Overview_PO_Service::close_short( $po_id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $po_id ) );
		}
		self::success_redirect( self::detail_url( $po_id ), 'closed_short' );
	}

	/**
	 * Handle delete draft.
	 */
	public static function handle_delete_draft() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::DELETE_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_delete_draft_' . $po_id, 'wc_io_po_delete_draft_nonce' );
		self::require_token( 'delete_draft' );
		$result = WC_Inventory_Overview_PO_Service::delete_draft( $po_id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $po_id ) );
		}
		self::success_redirect( self::list_url(), 'deleted' );
	}

	/**
	 * Handle duplicate.
	 */
	public static function handle_duplicate() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::DUPLICATE_PO );
		$po_id = isset( $_POST['po_id'] ) ? absint( $_POST['po_id'] ) : 0;
		check_admin_referer( 'wc_io_po_duplicate_' . $po_id, 'wc_io_po_duplicate_nonce' );
		self::require_token( 'duplicate' );
		$source = WC_Inventory_Overview_PO_Service::get( $po_id );
		$result = WC_Inventory_Overview_PO_Service::duplicate( $po_id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $po_id ) );
		}
		$source_number = is_array( $source ) ? (string) $source['po_number'] : '';
		self::success_redirect(
			self::detail_url( (int) $result ),
			'duplicated',
			array( 'source' => $source_number )
		);
	}

	/**
	 * Parse posted lines into service payloads.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function parse_lines_from_post(): array {
		// Nonce verified by calling handler before this helper runs.
		$raw = isset( $_POST['lines'] ) && is_array( $_POST['lines'] ) ? wp_unslash( $_POST['lines'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$out = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$product_raw = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			if ( $product_raw <= 0 && empty( $row['id'] ) ) {
				continue;
			}
			$line = array(
				'id'                  => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
				'product_id'          => $product_raw,
				'variation_id'        => isset( $row['variation_id'] ) ? absint( $row['variation_id'] ) : 0,
				'supplier_sku'        => isset( $row['supplier_sku'] ) ? sanitize_text_field( (string) $row['supplier_sku'] ) : '',
				'qty_ordered'         => isset( $row['qty_ordered'] ) ? (float) $row['qty_ordered'] : 0,
				'unit_cost'           => isset( $row['unit_cost'] ) ? (float) $row['unit_cost'] : 0,
				'expected_date'       => isset( $row['expected_date'] ) ? sanitize_text_field( (string) $row['expected_date'] ) : '',
				'expected_confidence' => isset( $row['expected_confidence'] ) ? sanitize_key( (string) $row['expected_confidence'] ) : '',
				'note'                => isset( $row['note'] ) ? sanitize_textarea_field( (string) $row['note'] ) : '',
			);
			// Woo product search may return variation id in product_id.
			if ( $line['product_id'] > 0 && 0 === $line['variation_id'] ) {
				$product = wc_get_product( $line['product_id'] );
				if ( $product && $product->is_type( 'variation' ) ) {
					$line['variation_id'] = $line['product_id'];
					$line['product_id']   = (int) $product->get_parent_id();
				}
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Sync posted lines to an existing PO.
	 *
	 * @param int                            $po_id PO id.
	 * @param array<int,array<string,mixed>> $lines Posted lines.
	 * @return true|WP_Error
	 */
	private static function sync_lines( int $po_id, array $lines ) {
		$existing = WC_Inventory_Overview_Purchase_Order_Lines::list_by_po( $po_id );
		$keep     = array();

		foreach ( $lines as $line ) {
			$payload = $line;
			unset( $payload['id'] );
			if ( ! empty( $line['id'] ) ) {
				$updated = WC_Inventory_Overview_PO_Service::update_line( (int) $line['id'], $payload );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$keep[] = (int) $line['id'];
			} else {
				$added = WC_Inventory_Overview_PO_Service::add_line( $po_id, $payload );
				if ( is_wp_error( $added ) ) {
					return $added;
				}
				$keep[] = (int) $added;
			}
		}

		foreach ( $existing as $row ) {
			$id = (int) $row['id'];
			if ( in_array( $id, $keep, true ) ) {
				continue;
			}
			$removed = WC_Inventory_Overview_PO_Service::remove_line( $id );
			if ( is_wp_error( $removed ) ) {
				return $removed;
			}
		}

		return true;
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
		$token = isset( $_POST['wc_io_po_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_po_request_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified nonce first.
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, $context ) ) {
			wp_die( esc_html__( 'This form has already been submitted or expired. Please go back and try again.', 'wc-inventory-overview' ), 400 );
		}
	}

	/**
	 * Success PRG redirect.
	 *
	 * @param string               $url    URL.
	 * @param string               $notice Notice key.
	 * @param array<string,string> $extra  Extra args.
	 */
	private static function success_redirect( string $url, string $notice, array $extra = array() ) {
		$args = array_merge( array( self::NOTICE_QUERY => $notice ), $extra );
		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * Error PRG redirect with transient message.
	 *
	 * @param WP_Error $error   Error.
	 * @param string   $url     URL.
	 * @param string   $field   Optional field key for highlighting.
	 */
	private static function fail_and_redirect( WP_Error $error, string $url, string $field = '' ) {
		$user_id = get_current_user_id();
		set_transient( self::ERR_TRANSIENT_PREFIX . $user_id, $error->get_error_message(), 120 );
		if ( $field ) {
			$map  = array();
			$code = $error->get_error_code();
			if ( false !== strpos( $code, 'supplier' ) ) {
				$map['supplier_id'] = $error->get_error_message();
			} elseif ( false !== strpos( $code, 'currency' ) ) {
				$map['currency'] = $error->get_error_message();
			} elseif ( false !== strpos( $code, 'date' ) ) {
				$map['expected_date'] = $error->get_error_message();
			} elseif ( false !== strpos( $code, 'product' ) || false !== strpos( $code, 'qty' ) || false !== strpos( $code, 'line' ) ) {
				$map['lines'] = $error->get_error_message();
			} else {
				$map[ $field ] = $error->get_error_message();
			}
			set_transient( self::FIELD_ERR_PREFIX . $user_id, $map, 120 );
		}
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, 'err', $url ) );
		exit;
	}

	/**
	 * Consume field errors transient.
	 *
	 * @return array<string,string>
	 */
	private static function consume_field_errors(): array {
		$key  = self::FIELD_ERR_PREFIX . get_current_user_id();
		$data = get_transient( $key );
		delete_transient( $key );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Render PO notices (called from purchasing page).
	 */
	public static function render_notices() {
		if ( ! isset( $_GET[ self::NOTICE_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status   = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'saved'        => __( 'Purchase order saved.', 'wc-inventory-overview' ),
			'placed'       => __( 'Purchase order placed.', 'wc-inventory-overview' ),
			'cancelled'    => __( 'Purchase order cancelled.', 'wc-inventory-overview' ),
			'closed_short' => __( 'Purchase order closed short.', 'wc-inventory-overview' ),
			'deleted'      => __( 'Draft purchase order deleted.', 'wc-inventory-overview' ),
			'duplicated'   => __( 'Purchase order duplicated.', 'wc-inventory-overview' ),
		);
		if ( 'duplicated' === $status && ! empty( $_GET['source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$source                 = sanitize_text_field( wp_unslash( $_GET['source'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$messages['duplicated'] = sprintf(
				/* translators: %s: source PO number */
				__( 'Duplicated from %s. You are editing the new draft.', 'wc-inventory-overview' ),
				$source
			);
		}
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
