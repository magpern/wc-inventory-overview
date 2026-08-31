<?php
/**
 * Stock Adjustment admin UX (M27).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stock Adjustment admin panel, PRG handlers, and notices.
 */
class WC_Inventory_Overview_Stock_Adjustment_Admin {

	const NOTICE_QUERY         = 'wc_io_sa';
	const ERR_TRANSIENT_PREFIX = 'wc_io_sa_err_';

	/**
	 * Register admin-post handlers.
	 */
	public static function init() {
		add_action( 'admin_post_wc_io_sa_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wc_io_sa_delete_draft', array( __CLASS__, 'handle_delete_draft' ) );
		add_action( 'admin_post_wc_io_sa_post', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_post_wc_io_sa_void', array( __CLASS__, 'handle_void' ) );
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
					'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'       => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
					'sa_action' => 'list',
				),
				$extra
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * New/edit draft URL.
	 *
	 * @param int $id Adjustment id (0 = new).
	 */
	public static function detail_url( int $id = 0 ): string {
		$args = array(
			'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
			'tab'       => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
			'sa_action' => $id > 0 ? 'edit' : 'new',
		);
		if ( $id > 0 ) {
			$args['sa_id'] = $id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Read-only posted/voided detail URL.
	 *
	 * @param int $id Adjustment id.
	 */
	public static function view_url( int $id ): string {
		return add_query_arg(
			array(
				'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
				'tab'       => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
				'sa_action' => 'view',
				'sa_id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Overview prefill URL (GET navigation only — M22 pattern).
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id (0 if simple).
	 */
	public static function prefill_url( int $product_id, int $variation_id = 0 ): string {
		return add_query_arg(
			array(
				'page'                  => WC_Inventory_Overview_Plugin::PAGE_SLUG,
				'tab'                   => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
				'sa_action'             => 'new',
				'wc_io_sa_product_id'   => $product_id,
				'wc_io_sa_variation_id'   => $variation_id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Post confirmation URL.
	 *
	 * @param int $id Adjustment id.
	 */
	public static function post_confirm_url( int $id ): string {
		return add_query_arg(
			array(
				'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
				'tab'       => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
				'sa_action' => 'post_confirm',
				'sa_id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Void confirmation URL.
	 *
	 * @param int $id Adjustment id.
	 */
	public static function void_confirm_url( int $id ): string {
		return add_query_arg(
			array(
				'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
				'tab'       => WC_Inventory_Overview_Plugin::TAB_STOCK_ADJUSTMENTS,
				'sa_action' => 'void_confirm',
				'sa_id'     => $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render panel for the requested sub-action.
	 *
	 * @param string $action Action slug.
	 */
	public static function render_panel( string $action ) {
		switch ( $action ) {
			case 'new':
			case 'edit':
			case 'view':
				self::render_detail( $action );
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
	 * Render list table.
	 */
	private static function render_list() {
		$list_table = new WC_Inventory_Overview_Stock_Adjustments_List_Table();
		$list_table->prepare_items();
		?>
		<div class="wc-io-sa-list-header">
			<p class="description"><?php esc_html_e( 'Record personal-use stock withdrawals with weighted-average cost tracking. Use this instead of inline stock edits when reconciling historical consumption.', 'wc-inventory-overview' ); ?></p>
			<?php if ( WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( self::detail_url( 0 ) ); ?>">
					<?php esc_html_e( 'Record personal use', 'wc-inventory-overview' ); ?>
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
	 * @param string $action Action.
	 */
	private static function render_detail( string $action ) {
		$id         = isset( $_GET['sa_id'] ) ? absint( $_GET['sa_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$header     = null;
		$lines      = array();
		$prefill    = array();

		if ( $id > 0 ) {
			$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
			if ( is_wp_error( $header ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Stock Adjustment not found.', 'wc-inventory-overview' ) . '</p></div>';
				return;
			}
			$lines = WC_Inventory_Overview_Stock_Adjustment_Lines::list_for_adjustment( $id );
		} elseif ( 'new' === $action ) {
			$prefill = self::resolve_overview_prefill();
		}

		$is_new   = ( null === $header );
		$status   = $is_new ? WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT : (string) $header['status'];
		$editable = $is_new || WC_Inventory_Overview_Stock_Adjustment_Lifecycle::is_editable( $status );
		$can_edit = $editable && WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT );
		$actions  = $is_new ? array( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::ACTION_EDIT ) : WC_Inventory_Overview_Stock_Adjustment_Lifecycle::available_actions( $status );

		if ( 'view' === $action || ( ! $editable && 'edit' === $action ) ) {
			$can_edit = false;
		}

		if ( ! empty( $prefill ) ) {
			$lines = array( $prefill );
		}

		$title = $is_new
			? __( 'New personal use adjustment', 'wc-inventory-overview' )
			: sprintf(
				/* translators: %s: document number */
				__( 'Stock Adjustment %s', 'wc-inventory-overview' ),
				(string) $header['adjustment_number']
			);
		?>
		<p><a href="<?php echo esc_url( self::list_url() ); ?>">&larr; <?php esc_html_e( 'Back to Stock Adjustments', 'wc-inventory-overview' ); ?></a></p>
		<h2 class="wc-io-sa-detail-title">
			<?php echo esc_html( $title ); ?>
			<?php if ( ! $is_new ) : ?>
				<span class="wc-io-sa-status wc-io-sa-status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::status_label( $status ) ); ?></span>
			<?php endif; ?>
		</h2>

		<?php self::render_reconciliation_notice(); ?>

		<?php if ( ! empty( $prefill['prefill_notice'] ) ) : ?>
			<div class="notice notice-info inline"><p><?php echo esc_html( (string) $prefill['prefill_notice'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $can_edit ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wc-io-sa-form" id="wc-io-sa-form">
				<input type="hidden" name="action" value="wc_io_sa_save" />
				<input type="hidden" name="sa_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="wc_io_sa_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'sa_save' ) ); ?>" />
				<?php wp_nonce_field( 'wc_io_sa_save_' . $id, 'wc_io_sa_save_nonce' ); ?>

				<?php self::render_note_field( $header, true ); ?>
				<?php self::render_lines_editor( $lines, true ); ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save draft', 'wc-inventory-overview' ); ?></button>
				</p>
			</form>
		<?php else : ?>
			<?php self::render_note_field( $header, false ); ?>
			<?php self::render_lines_editor( $lines, false ); ?>
		<?php endif; ?>

		<?php if ( ! $is_new ) : ?>
			<?php self::render_action_forms( $header, $actions ); ?>
			<?php if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_VOIDED === $status ) : ?>
				<p><strong><?php esc_html_e( 'Void reason:', 'wc-inventory-overview' ); ?></strong> <?php echo esc_html( (string) ( $header['void_reason'] ?? '' ) ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Historical reconciliation notice (plan §4.1).
	 */
	private static function render_reconciliation_notice() {
		?>
		<div class="notice notice-warning inline wc-io-sa-reconciliation-notice">
			<p><strong><?php esc_html_e( 'Before posting personal use', 'wc-inventory-overview' ); ?></strong></p>
			<p><?php esc_html_e( 'Compare recorded on-hand to your physical count. Post only the difference (recorded minus physical remaining). Do not reduce stock elsewhere first — that would double-reduce inventory.', 'wc-inventory-overview' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Resolve Overview GET prefill (read-only navigation).
	 *
	 * @return array<string,mixed>
	 */
	private static function resolve_overview_prefill(): array {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) {
			return array();
		}

		$product_id   = isset( $_GET['wc_io_sa_product_id'] ) ? absint( $_GET['wc_io_sa_product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$variation_id = isset( $_GET['wc_io_sa_variation_id'] ) ? absint( $_GET['wc_io_sa_variation_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $product_id <= 0 && $variation_id <= 0 ) {
			return array();
		}

		$valid = WC_Inventory_Overview_PO_Product_Validator::validate( $product_id, $variation_id );
		if ( is_wp_error( $valid ) ) {
			return array(
				'prefill_notice' => __( 'The requested product could not be prefilled. Add lines manually.', 'wc-inventory-overview' ),
			);
		}

		$product = $valid['product'];
		return array(
			'product_id'    => (int) $valid['product_id'],
			'variation_id'  => (int) $valid['variation_id'],
			'qty'           => 1,
			'sku_snapshot'  => $product->get_sku(),
			'name_snapshot' => $product->get_name(),
			'prefill_notice' => __( 'Prefilled from Inventory Overview. Review quantity and note before saving.', 'wc-inventory-overview' ),
		);
	}

	/**
	 * @param array<string,mixed>|null $header Header.
	 * @param bool                     $editable Editable.
	 */
	private static function render_note_field( $header, bool $editable ) {
		$note = $header['note'] ?? '';
		?>
		<h3><?php esc_html_e( 'Note (required before posting)', 'wc-inventory-overview' ); ?></h3>
		<?php if ( $editable ) : ?>
			<textarea name="wc_io_sa_note" id="wc_io_sa_note" rows="3" class="large-text" required><?php echo esc_textarea( (string) $note ); ?></textarea>
		<?php else : ?>
			<p><?php echo esc_html( (string) $note ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $lines    Lines.
	 * @param bool                           $editable Editable.
	 */
	private static function render_lines_editor( array $lines, bool $editable ) {
		$max = WC_Inventory_Overview_Stock_Adjustment_Service::MAX_LINES;
		?>
		<h3><?php esc_html_e( 'Lines', 'wc-inventory-overview' ); ?></h3>
		<table class="widefat striped wc-io-sa-lines" id="wc-io-sa-lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product / variation', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Qty to withdraw', 'wc-inventory-overview' ); ?></th>
					<?php if ( $editable ) : ?>
						<th><?php esc_html_e( 'Preview', 'wc-inventory-overview' ); ?></th>
						<th></th>
					<?php else : ?>
						<th><?php esc_html_e( 'Unit cost at post', 'wc-inventory-overview' ); ?></th>
						<th><?php esc_html_e( 'Value removed', 'wc-inventory-overview' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
			<?php
			$rows = $editable ? ( ! empty( $lines ) ? $lines : array( array() ) ) : $lines;
			foreach ( $rows as $index => $line ) :
				self::render_line_row( $line, $index, $editable );
			endforeach;
			?>
			</tbody>
		</table>
		<?php if ( $editable ) : ?>
			<p>
				<button type="button" class="button" id="wc-io-sa-add-line" data-max-lines="<?php echo esc_attr( (string) $max ); ?>" <?php disabled( count( $rows ) >= $max ); ?>>
					<?php esc_html_e( 'Add line', 'wc-inventory-overview' ); ?>
				</button>
			</p>
			<script type="text/template" id="wc-io-sa-line-template">
				<?php self::render_line_row( array(), '__INDEX__', true ); ?>
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array<string,mixed> $line     Line.
	 * @param int|string          $index    Row index.
	 * @param bool                $editable Editable.
	 */
	private static function render_line_row( $line, $index, bool $editable ) {
		$purchasable_id = 0;
		if ( ! empty( $line ) ) {
			$purchasable_id = (int) ( $line['variation_id'] ?? 0 ) > 0 ? (int) $line['variation_id'] : (int) ( $line['product_id'] ?? 0 );
		}
		$label = (string) ( $line['name_snapshot'] ?? '' );
		if ( $purchasable_id > 0 && '' === $label ) {
			$product = wc_get_product( $purchasable_id );
			if ( $product ) {
				$label = $product->get_name();
			}
		}
		?>
		<tr class="wc-io-sa-line-row">
			<td>
				<?php if ( $editable ) : ?>
					<select class="wc-product-search wc-io-sa-product" style="width:260px;" name="wc_io_sa_line_product[<?php echo esc_attr( (string) $index ); ?>]" data-placeholder="<?php esc_attr_e( 'Search for a product…', 'wc-inventory-overview' ); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
						<?php if ( $purchasable_id > 0 ) : ?>
							<option value="<?php echo esc_attr( (string) $purchasable_id ); ?>" selected="selected"><?php echo esc_html( $label ); ?></option>
						<?php endif; ?>
					</select>
				<?php else : ?>
					<?php echo esc_html( $label ); ?>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $editable ) : ?>
					<input type="number" step="0.0001" min="0.0001" class="small-text wc-io-sa-qty" name="wc_io_sa_line_qty[<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_attr( (string) ( $line['qty'] ?? '' ) ); ?>" />
				<?php else : ?>
					<?php echo esc_html( wc_format_decimal( (float) ( $line['qty'] ?? 0 ), 4 ) ); ?>
				<?php endif; ?>
			</td>
			<?php if ( $editable ) : ?>
				<td class="wc-io-sa-preview" data-row="<?php echo esc_attr( (string) $index ); ?>"><span class="wc-io-muted"><?php esc_html_e( 'Enter product and qty', 'wc-inventory-overview' ); ?></span></td>
				<td><button type="button" class="button-link wc-io-sa-remove-line"><?php esc_html_e( 'Remove', 'wc-inventory-overview' ); ?></button></td>
			<?php else : ?>
				<td><?php echo esc_html( wc_format_decimal( (float) ( $line['unit_cost_at_post'] ?? 0 ), 4 ) ); ?></td>
				<td><?php echo esc_html( wc_format_decimal( (float) ( $line['value_removed'] ?? 0 ), 4 ) ); ?></td>
			<?php endif; ?>
		</tr>
		<?php
	}

	/**
	 * @param array<string,mixed> $header Header.
	 * @param array<int,string>   $actions Actions.
	 */
	private static function render_action_forms( array $header, array $actions ) {
		$id     = (int) $header['id'];
		$status = (string) $header['status'];

		if ( in_array( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::ACTION_DELETE_DRAFT, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT ) ) :
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wc-io-sa-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this draft?', 'wc-inventory-overview' ) ); ?>');">
				<input type="hidden" name="action" value="wc_io_sa_delete_draft" />
				<input type="hidden" name="sa_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<input type="hidden" name="wc_io_sa_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'sa_delete_draft' ) ); ?>" />
				<?php wp_nonce_field( 'wc_io_sa_delete_draft_' . $id, 'wc_io_sa_delete_draft_nonce' ); ?>
				<p><button type="submit" class="button"><?php esc_html_e( 'Delete draft', 'wc-inventory-overview' ); ?></button></p>
			</form>
			<?php
		endif;

		if ( in_array( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::ACTION_POST, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_STOCK_ADJUSTMENT ) ) :
			?>
			<p><a class="button button-primary" href="<?php echo esc_url( self::post_confirm_url( $id ) ); ?>"><?php esc_html_e( 'Post personal use', 'wc-inventory-overview' ); ?></a></p>
			<?php
		endif;

		if ( in_array( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::ACTION_VOID, $actions, true )
			&& WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_STOCK_ADJUSTMENT ) ) :
			?>
			<p><a class="button" href="<?php echo esc_url( self::void_confirm_url( $id ) ); ?>"><?php esc_html_e( 'Void personal use', 'wc-inventory-overview' ); ?></a></p>
			<?php
		endif;
	}

	/**
	 * Post confirmation screen.
	 */
	private static function render_post_confirm() {
		$id = isset( $_GET['sa_id'] ) ? absint( $_GET['sa_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::POST_STOCK_ADJUSTMENT ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Stock Adjustment not found.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}
		if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_DRAFT !== $header['status'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Only a draft Stock Adjustment can be posted.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}

		$lines = WC_Inventory_Overview_Stock_Adjustment_Lines::list_for_adjustment( $id );
		?>
		<p><a href="<?php echo esc_url( self::detail_url( $id ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wc-inventory-overview' ); ?></a></p>
		<h2><?php echo esc_html( sprintf( /* translators: %s: document number */ __( 'Confirm posting %s', 'wc-inventory-overview' ), (string) $header['adjustment_number'] ) ); ?></h2>
		<?php self::render_reconciliation_notice(); ?>
		<p class="notice notice-info inline"><strong><?php esc_html_e( 'Posting will reduce stock and inventory value immediately.', 'wc-inventory-overview' ); ?></strong></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Current stock', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Qty to withdraw', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'WAC (EUR)', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Value removed', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Resulting stock', 'wc-inventory-overview' ); ?></th>
				<th><?php esc_html_e( 'Resulting inventory value', 'wc-inventory-overview' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $lines as $line ) :
				$item_id = WC_Inventory_Overview_Stock_Adjustment_Lines::item_post_id( $line );
				$preview = WC_Inventory_Overview_Stock_Adjustment_Service::preview_line( $item_id, (float) $line['qty'] );
				?>
				<tr>
					<td><?php echo esc_html( (string) $line['name_snapshot'] ); ?></td>
					<td><?php echo esc_html( is_wp_error( $preview ) ? '—' : wc_format_decimal( $preview['on_hand'], 4 ) ); ?></td>
					<td><?php echo esc_html( wc_format_decimal( (float) $line['qty'], 4 ) ); ?></td>
					<td><?php echo esc_html( is_wp_error( $preview ) ? '—' : wc_format_decimal( $preview['avg_cost'], 4 ) ); ?></td>
					<td><?php echo esc_html( is_wp_error( $preview ) ? '—' : wc_format_decimal( $preview['value_removed'], 4 ) ); ?></td>
					<td><?php echo esc_html( is_wp_error( $preview ) ? '—' : wc_format_decimal( $preview['resulting_stock'], 4 ) ); ?></td>
					<td><?php echo esc_html( is_wp_error( $preview ) ? '—' : wc_format_decimal( $preview['resulting_value'], 4 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_io_sa_post" />
			<input type="hidden" name="sa_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="wc_io_sa_confirm" value="1" />
			<input type="hidden" name="wc_io_sa_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'sa_post' ) ); ?>" />
			<?php wp_nonce_field( 'wc_io_sa_post_' . $id, 'wc_io_sa_post_nonce' ); ?>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm & post', 'wc-inventory-overview' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Void confirmation screen.
	 */
	private static function render_void_confirm() {
		$id = isset( $_GET['sa_id'] ) ? absint( $_GET['sa_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::VOID_STOCK_ADJUSTMENT ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ) );
		}

		$header = WC_Inventory_Overview_Stock_Adjustments::get( $id );
		if ( is_wp_error( $header ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Stock Adjustment not found.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}
		if ( WC_Inventory_Overview_Stock_Adjustment_Lifecycle::STATUS_POSTED !== $header['status'] ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Only a posted Stock Adjustment can be voided.', 'wc-inventory-overview' ) . '</p></div>';
			return;
		}
		?>
		<p><a href="<?php echo esc_url( self::view_url( $id ) ); ?>">&larr; <?php esc_html_e( 'Back', 'wc-inventory-overview' ); ?></a></p>
		<h2><?php echo esc_html( sprintf( /* translators: %s: document number */ __( 'Void personal use %s', 'wc-inventory-overview' ), (string) $header['adjustment_number'] ) ); ?></h2>
		<p class="notice notice-warning inline"><?php esc_html_e( 'Voiding restores stock and inventory value using the immutable posted delta.', 'wc-inventory-overview' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_io_sa_void" />
			<input type="hidden" name="sa_id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<input type="hidden" name="wc_io_sa_request_token" value="<?php echo esc_attr( WC_Inventory_Overview_PO_Request_Token::issue( 'sa_void' ) ); ?>" />
			<?php wp_nonce_field( 'wc_io_sa_void_' . $id, 'wc_io_sa_void_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wc_io_sa_void_reason"><?php esc_html_e( 'Void reason (required)', 'wc-inventory-overview' ); ?></label></th>
					<td><textarea name="wc_io_sa_void_reason" id="wc_io_sa_void_reason" rows="3" class="large-text" required></textarea></td>
				</tr>
			</table>
			<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Confirm & void', 'wc-inventory-overview' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * @param array<string,mixed> $src Raw POST.
	 * @return array<string,mixed>
	 */
	private static function post_to_service_src( array $src ): array {
		$ids  = isset( $src['wc_io_sa_line_product'] ) && is_array( $src['wc_io_sa_line_product'] ) ? $src['wc_io_sa_line_product'] : array();
		$qtys = isset( $src['wc_io_sa_line_qty'] ) && is_array( $src['wc_io_sa_line_qty'] ) ? $src['wc_io_sa_line_qty'] : array();
		$lines = array();
		$max   = max( count( $ids ), count( $qtys ) );
		for ( $i = 0; $i < $max; $i++ ) {
			$pid = isset( $ids[ $i ] ) ? absint( $ids[ $i ] ) : 0;
			$qty = isset( $qtys[ $i ] ) ? (string) $qtys[ $i ] : '';
			if ( $pid <= 0 && '' === trim( $qty ) ) {
				continue;
			}
			$lines[] = array(
				'product_id'   => $pid,
				'variation_id' => 0,
				'qty'          => $qty,
			);
		}

		return array(
			'note'  => isset( $src['wc_io_sa_note'] ) ? (string) $src['wc_io_sa_note'] : '',
			'lines' => $lines,
		);
	}

	public static function handle_save() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT );
		$id = isset( $_POST['sa_id'] ) ? absint( $_POST['sa_id'] ) : 0;
		check_admin_referer( 'wc_io_sa_save_' . $id, 'wc_io_sa_save_nonce' );
		self::require_token( 'sa_save' );

		$src = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( $id <= 0 ) {
			$created = WC_Inventory_Overview_Stock_Adjustment_Service::create_draft();
			if ( is_wp_error( $created ) ) {
				self::fail_and_redirect( $created, self::detail_url( 0 ) );
			}
			$id = (int) $created;
		}

		$result = WC_Inventory_Overview_Stock_Adjustment_Service::save_draft_from_post( $id, self::post_to_service_src( $src ) );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::detail_url( $id ), 'saved' );
	}

	public static function handle_delete_draft() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::EDIT_STOCK_ADJUSTMENT );
		$id = isset( $_POST['sa_id'] ) ? absint( $_POST['sa_id'] ) : 0;
		check_admin_referer( 'wc_io_sa_delete_draft_' . $id, 'wc_io_sa_delete_draft_nonce' );
		self::require_token( 'sa_delete_draft' );

		$result = WC_Inventory_Overview_Stock_Adjustment_Service::delete_draft( $id );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::list_url(), 'deleted' );
	}

	public static function handle_post() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::POST_STOCK_ADJUSTMENT );
		$id = isset( $_POST['sa_id'] ) ? absint( $_POST['sa_id'] ) : 0;
		check_admin_referer( 'wc_io_sa_post_' . $id, 'wc_io_sa_post_nonce' );
		if ( empty( $_POST['wc_io_sa_confirm'] ) || '1' !== (string) $_POST['wc_io_sa_confirm'] ) {
			wp_die( esc_html__( 'Posting must be explicitly confirmed.', 'wc-inventory-overview' ), 400 );
		}
		$token  = isset( $_POST['wc_io_sa_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_sa_request_token'] ) ) : '';
		$result = WC_Inventory_Overview_Stock_Adjustment_Service::post( $id, $token );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::detail_url( $id ) );
		}
		self::success_redirect( self::view_url( $id ), 'posted' );
	}

	public static function handle_void() {
		self::guard( WC_Inventory_Overview_Purchasing_Caps::VOID_STOCK_ADJUSTMENT );
		$id = isset( $_POST['sa_id'] ) ? absint( $_POST['sa_id'] ) : 0;
		check_admin_referer( 'wc_io_sa_void_' . $id, 'wc_io_sa_void_nonce' );
		$reason = isset( $_POST['wc_io_sa_void_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wc_io_sa_void_reason'] ) ) : '';
		$token  = isset( $_POST['wc_io_sa_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_sa_request_token'] ) ) : '';
		$result = WC_Inventory_Overview_Stock_Adjustment_Service::void( $id, $token, $reason );
		if ( is_wp_error( $result ) ) {
			self::fail_and_redirect( $result, self::view_url( $id ) );
		}
		self::success_redirect( self::view_url( $id ), 'voided' );
	}

	/**
	 * @param string $cap_action Cap key.
	 */
	private static function guard( string $cap_action ) {
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( $cap_action ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ), 403 );
		}
	}

	/**
	 * @param string $context Token context.
	 */
	private static function require_token( string $context ) {
		$token = isset( $_POST['wc_io_sa_request_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wc_io_sa_request_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, $context ) ) {
			wp_die( esc_html__( 'This form has already been submitted or expired. Please go back and try again.', 'wc-inventory-overview' ), 400 );
		}
	}

	/**
	 * @param string $url    URL.
	 * @param string $notice Notice key.
	 */
	private static function success_redirect( string $url, string $notice ) {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, $notice, $url ) );
		exit;
	}

	/**
	 * @param WP_Error $error Error.
	 * @param string   $url   URL.
	 */
	private static function fail_and_redirect( WP_Error $error, string $url ) {
		set_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id(), $error->get_error_message(), 60 );
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, 'err', $url ) );
		exit;
	}

	public static function render_notices() {
		if ( ! isset( $_GET[ self::NOTICE_QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$status = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'err' === $status ) {
			$msg = get_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id() );
			delete_transient( self::ERR_TRANSIENT_PREFIX . get_current_user_id() );
			if ( $msg ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( (string) $msg ) . '</p></div>';
			}
			return;
		}

		$messages = array(
			'saved'   => __( 'Draft saved.', 'wc-inventory-overview' ),
			'deleted' => __( 'Draft deleted.', 'wc-inventory-overview' ),
			'posted'  => __( 'Personal use posted.', 'wc-inventory-overview' ),
			'voided'  => __( 'Personal use voided.', 'wc-inventory-overview' ),
		);
		if ( isset( $messages[ $status ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $messages[ $status ] ) . '</p></div>';
		}
	}
}
