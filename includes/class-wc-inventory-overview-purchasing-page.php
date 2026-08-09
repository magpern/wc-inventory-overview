<?php
/**
 * Purchasing admin page controller (Suppliers section for M1).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purchasing page controller.
 */
class WC_Inventory_Overview_Purchasing_Page {

	const PAGE_SLUG     = 'wc-io-purchasing';
	const TAB_SUPPLIERS = 'suppliers';
	const TAB_ORDERS    = 'orders';
	const TAB_RECEIPTS  = 'receipts';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 61 );
		add_action( 'load-woocommerce_page_' . self::PAGE_SLUG, array( $this, 'load_page' ) );
		add_action( 'admin_post_wc_io_supplier_save', array( $this, 'handle_supplier_save' ) );
		add_action( 'admin_post_wc_io_supplier_archive', array( $this, 'handle_supplier_archive' ) );
		add_action( 'admin_post_wc_io_supplier_reactivate', array( $this, 'handle_supplier_reactivate' ) );
		add_action( 'wp_ajax_wc_io_search_suppliers', array( $this, 'ajax_search_suppliers' ) );
		add_action( 'wp_ajax_wc_io_quick_create_supplier', array( $this, 'ajax_quick_create_supplier' ) );
		WC_Inventory_Overview_PO_Admin::init();
		WC_Inventory_Overview_Goods_Receipt_Admin::init();
	}

	/**
	 * Register the Purchasing submenu page. Feature-gated on schema assertion.
	 */
	public function register_menu() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check schema assertion: canonical option first, v6 fallback for upgrades in flight.
		$assertion = get_option( 'wc_io_schema_assertion', null );
		if ( ! is_array( $assertion ) ) {
			$assertion = get_option( 'wc_io_schema_v6_assertion', array() );
		}
		if ( ! isset( $assertion['ok'] ) || ! $assertion['ok'] ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Purchasing', 'wc-inventory-overview' ),
			__( 'Purchasing', 'wc-inventory-overview' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load page assets and state.
	 */
	public function load_page() {
		// Capability check.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		// Add help tabs, enqueue scripts, etc. (deferred for M2+).
	}

	/**
	 * Render the main Purchasing page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : self::TAB_ORDERS;
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Purchasing', 'wc-inventory-overview' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'tab'    => self::TAB_ORDERS,
							'action' => 'list',
						),
						admin_url( 'admin.php?page=' . self::PAGE_SLUG )
					)
				);
				?>
							" class="nav-tab <?php echo self::TAB_ORDERS === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Purchase Orders', 'wc-inventory-overview' ); ?>
				</a>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'tab'    => self::TAB_RECEIPTS,
							'action' => 'list',
						),
						admin_url( 'admin.php?page=' . self::PAGE_SLUG )
					)
				);
				?>
							" class="nav-tab <?php echo self::TAB_RECEIPTS === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Receive Stock', 'wc-inventory-overview' ); ?>
				</a>
				<a href="
				<?php
				echo esc_url(
					add_query_arg(
						array(
							'tab'    => self::TAB_SUPPLIERS,
							'action' => 'list',
						),
						admin_url( 'admin.php?page=' . self::PAGE_SLUG )
					)
				);
				?>
							" class="nav-tab <?php echo self::TAB_SUPPLIERS === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Suppliers', 'wc-inventory-overview' ); ?>
				</a>
			</nav>

			<?php $this->render_notices(); ?>
			<?php WC_Inventory_Overview_PO_Admin::render_notices(); ?>
			<?php WC_Inventory_Overview_Goods_Receipt_Admin::render_notices(); ?>

			<?php
			switch ( $tab ) {
				case self::TAB_ORDERS:
					WC_Inventory_Overview_PO_Admin::render_panel( $action );
					break;
				case self::TAB_RECEIPTS:
					WC_Inventory_Overview_Goods_Receipt_Admin::render_panel( $action );
					break;
				case self::TAB_SUPPLIERS:
					$this->render_suppliers_panel( $action );
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render status notices (errors, success, etc).
	 */
	private function render_notices() {
		if ( isset( $_GET['wc_io_supplier'] ) ) {
			$status = sanitize_key( $_GET['wc_io_supplier'] );
			switch ( $status ) {
				case 'saved':
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Supplier saved.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				case 'archived':
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Supplier archived.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				case 'reactivated':
					echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Supplier reactivated.', 'wc-inventory-overview' ) . '</p></div>';
					break;
				case 'err':
					$error = get_transient( 'wc_io_supplier_save_err_' . get_current_user_id() );
					if ( $error ) {
						echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
						delete_transient( 'wc_io_supplier_save_err_' . get_current_user_id() );
					}
					break;
			}
		}
	}

	/**
	 * Render the Suppliers section (list or detail).
	 *
	 * @param string $action Panel action.
	 */
	private function render_suppliers_panel( string $action ) {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'edit' === $action || 'new' === $action ) {
			$this->render_supplier_detail( $action );
		} else {
			$this->render_suppliers_list();
		}
	}

	/**
	 * Render the Suppliers list table.
	 */
	private function render_suppliers_list() {
		$list_table = new WC_Inventory_Overview_Suppliers_List_Table();
		$list_table->prepare_items();

		?>
		<div class="alignright">
			<a href="
			<?php
			echo esc_url(
				add_query_arg(
					array(
						'tab'    => self::TAB_SUPPLIERS,
						'action' => 'new',
					),
					admin_url( 'admin.php?page=' . self::PAGE_SLUG )
				)
			);
			?>
						" class="button button-primary">
				<?php esc_html_e( '+ New Supplier', 'wc-inventory-overview' ); ?>
			</a>
		</div>

		<form method="post">
			<?php $list_table->display(); ?>
		</form>
		<?php
	}

	/**
	 * Render supplier create/edit detail form.
	 *
	 * @param string $action new|edit.
	 */
	private function render_supplier_detail( string $action ) {
		$supplier_id = isset( $_GET['supplier_id'] ) ? absint( $_GET['supplier_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$supplier    = null;

		if ( $supplier_id > 0 ) {
			$result = WC_Inventory_Overview_Suppliers::get( $supplier_id );
			if ( is_wp_error( $result ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Supplier not found.', 'wc-inventory-overview' ) . '</p></div>';
				return;
			}
			$supplier = $result;
		}

		$is_new     = ( 'new' === $action );
		$page_title = $is_new ? __( 'New Supplier', 'wc-inventory-overview' ) : __( 'Edit Supplier', 'wc-inventory-overview' );

		?>
		<h2><?php echo esc_html( $page_title ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wc_io_supplier_save">
			<input type="hidden" name="supplier_id" value="<?php echo esc_attr( $supplier_id ); ?>">
			<?php wp_nonce_field( 'wc_io_supplier_save', 'wc_io_supplier_save_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="wc_io_supplier_name"><?php esc_html_e( 'Name', 'wc-inventory-overview' ); ?> *</label></th>
					<td>
						<input type="text" id="wc_io_supplier_name" name="supplier[name]" maxlength="190" required value="<?php echo esc_attr( $supplier['name'] ?? '' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_currency"><?php esc_html_e( 'Default Currency', 'wc-inventory-overview' ); ?> *</label></th>
					<td>
						<select id="wc_io_supplier_currency" name="supplier[default_currency]" required>
							<option value="EUR" <?php selected( $supplier['default_currency'] ?? 'EUR', 'EUR' ); ?>>EUR</option>
							<option value="USD" <?php selected( $supplier['default_currency'] ?? 'EUR', 'USD' ); ?>>USD</option>
							<option value="SEK" <?php selected( $supplier['default_currency'] ?? 'EUR', 'SEK' ); ?>>SEK</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_lead_time"><?php esc_html_e( 'Default Lead Time (days)', 'wc-inventory-overview' ); ?></label></th>
					<td>
						<input type="number" id="wc_io_supplier_lead_time" name="supplier[default_lead_time_days]" min="0" step="1" value="<?php echo esc_attr( $supplier['default_lead_time_days'] ?? '' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_email"><?php esc_html_e( 'Email', 'wc-inventory-overview' ); ?></label></th>
					<td>
						<input type="email" id="wc_io_supplier_email" name="supplier[email]" value="<?php echo esc_attr( $supplier['email'] ?? '' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_phone"><?php esc_html_e( 'Phone', 'wc-inventory-overview' ); ?></label></th>
					<td>
						<input type="text" id="wc_io_supplier_phone" name="supplier[phone]" maxlength="64" value="<?php echo esc_attr( $supplier['phone'] ?? '' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_reference"><?php esc_html_e( 'Our Account/Reference Number', 'wc-inventory-overview' ); ?></label></th>
					<td>
						<input type="text" id="wc_io_supplier_reference" name="supplier[supplier_reference]" maxlength="100" value="<?php echo esc_attr( $supplier['supplier_reference'] ?? '' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wc_io_supplier_note"><?php esc_html_e( 'Note', 'wc-inventory-overview' ); ?></label></th>
					<td>
						<textarea id="wc_io_supplier_note" name="supplier[note]"><?php echo esc_textarea( $supplier['note'] ?? '' ); ?></textarea>
					</td>
				</tr>
			</table>

			<?php submit_button( $is_new ? __( 'Create Supplier', 'wc-inventory-overview' ) : __( 'Update Supplier', 'wc-inventory-overview' ) ); ?>
		</form>

		<?php
		if ( ! $is_new && $supplier ) {
			?>
			<h3><?php esc_html_e( 'Supplier Status', 'wc-inventory-overview' ); ?></h3>
			<p>
				<?php
				if ( 'active' === $supplier['status'] ) {
					echo '<strong>' . esc_html__( 'Active', 'wc-inventory-overview' ) . '</strong>';
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="wc_io_supplier_archive">
						<input type="hidden" name="supplier_id" value="<?php echo esc_attr( $supplier_id ); ?>">
						<?php wp_nonce_field( 'wc_io_supplier_archive_' . $supplier_id, 'wc_io_supplier_archive_nonce' ); ?>
						<?php submit_button( __( 'Archive', 'wc-inventory-overview' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php
				} else {
					echo '<strong>' . esc_html__( 'Archived', 'wc-inventory-overview' ) . '</strong>';
					?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="wc_io_supplier_reactivate">
						<input type="hidden" name="supplier_id" value="<?php echo esc_attr( $supplier_id ); ?>">
						<?php wp_nonce_field( 'wc_io_supplier_reactivate_' . $supplier_id, 'wc_io_supplier_reactivate_nonce' ); ?>
						<?php submit_button( __( 'Reactivate', 'wc-inventory-overview' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php
				}
				?>
			</p>
			<?php
			$this->render_observed_lead_time( $supplier_id, $supplier );
		}
	}

	/**
	 * Read-only "Observed lead time" panel (M9). Computed exclusively from
	 * operational purchasing history (Purchase Orders, Goods Receipts,
	 * Receipt Lines) via WC_Inventory_Overview_Supplier_Lead_Time_Service --
	 * never editable here, never a second source of truth alongside the
	 * merchant-editable "Default Lead Time" field above. Renders unchanged
	 * whether the supplier is active or archived (archiving never removes
	 * historical purchasing evidence -- see the M9 plan's §6 "Archived
	 * suppliers" note).
	 *
	 * @param int   $supplier_id Supplier id.
	 * @param array $supplier    Supplier row (for the configured-lead-time
	 *                           comparison value).
	 */
	private function render_observed_lead_time( int $supplier_id, array $supplier ) {
		$grace_days      = WC_Inventory_Overview_PO_Delay::grace_days_from_option();
		$stats           = WC_Inventory_Overview_Supplier_Lead_Time_Service::get_stats_for_supplier( $supplier_id, $grace_days );
		$configured_days = $supplier['default_lead_time_days'] ?? null;
		$enough_data     = $stats['has_data'] && $stats['sample_count'] >= WC_Inventory_Overview_Supplier_Lead_Time_Service::MINIMUM_SAMPLE_COUNT_FOR_DISPLAY;
		$on_time_usable  = WC_Inventory_Overview_Supplier_Lead_Time_Service::is_on_time_rate_usable( $stats );
		?>
		<h3><?php esc_html_e( 'Observed Lead Time', 'wc-inventory-overview' ); ?></h3>
		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Configured Lead Time', 'wc-inventory-overview' ); ?></th>
				<td>
					<?php
					if ( null !== $configured_days && '' !== $configured_days ) {
						echo esc_html(
							sprintf(
								/* translators: %d: configured lead time in days. */
								_n( '%d day (configured fallback)', '%d days (configured fallback)', (int) $configured_days, 'wc-inventory-overview' ),
								(int) $configured_days
							)
						);
					} else {
						esc_html_e( 'Not set.', 'wc-inventory-overview' );
					}
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Observed Lead Time', 'wc-inventory-overview' ); ?></th>
				<td>
					<?php if ( $enough_data ) : ?>
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: average observed lead time in days, rounded to the nearest whole calendar day for display only. */
									_n( 'Average: %d day', 'Average: %d days', (int) round( $stats['average_days'] ), 'wc-inventory-overview' ),
									(int) round( $stats['average_days'] )
								)
							);
							?>
						</p>
						<p>
							<?php
							printf(
								/* translators: 1: fastest observed lead time in days, 2: slowest observed lead time in days. */
								esc_html__( 'Fastest: %1$d days — Slowest: %2$d days', 'wc-inventory-overview' ),
								(int) $stats['fastest_days'],
								(int) $stats['slowest_days']
							);
							?>
						</p>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of completed (fully received) purchase orders the statistics are based on. */
									_n( 'Based on %d completed order.', 'Based on %d completed orders.', $stats['sample_count'], 'wc-inventory-overview' ),
									$stats['sample_count']
								)
							);
							?>
						</p>
					<?php elseif ( $stats['sample_count'] > 0 ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of completed purchase orders on record (below the display threshold). */
									_n( 'Not enough data yet (%d completed order on record).', 'Not enough data yet (%d completed orders on record).', $stats['sample_count'], 'wc-inventory-overview' ),
									$stats['sample_count']
								)
							);
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Not enough data yet — no completed orders on record for this supplier.', 'wc-inventory-overview' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Computed from posted receiving history. Read-only — it cannot be edited or overridden.', 'wc-inventory-overview' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'On-Time Delivery Rate', 'wc-inventory-overview' ); ?></th>
				<td>
					<?php if ( $on_time_usable ) : ?>
						<p>
							<?php
							$on_time_percent = (int) round( ( $stats['on_time_count'] / $stats['rated_order_count'] ) * 100 );
							echo esc_html(
								sprintf(
									/* translators: 1: rounded on-time percentage, 2: number of rated completed orders (known expected date) the rate is based on. */
									_n( '%1$d%% — Based on %2$d rated order', '%1$d%% — Based on %2$d rated orders', $stats['rated_order_count'], 'wc-inventory-overview' ),
									$on_time_percent,
									$stats['rated_order_count']
								)
							);
							?>
						</p>
					<?php elseif ( $stats['rated_order_count'] > 0 ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: number of rated completed orders on record (below the display threshold). */
									_n( 'Not enough data yet (%d rated order on record).', 'Not enough data yet (%d rated orders on record).', $stats['rated_order_count'], 'wc-inventory-overview' ),
									$stats['rated_order_count']
								)
							);
							?>
						</p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Not enough data yet — no completed orders with a known expected date on record for this supplier.', 'wc-inventory-overview' ); ?></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Computed from posted receiving history compared against each order\'s expected date. Read-only — it cannot be edited or overridden.', 'wc-inventory-overview' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Handle supplier save (create/update) via admin-post.
	 */
	public function handle_supplier_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		check_admin_referer( 'wc_io_supplier_save', 'wc_io_supplier_save_nonce' );

		$supplier_id = isset( $_POST['supplier_id'] ) ? absint( $_POST['supplier_id'] ) : 0;
		$data        = isset( $_POST['supplier'] ) ? wp_unslash( $_POST['supplier'] ) : array();

		if ( $supplier_id > 0 ) {
			$result = WC_Inventory_Overview_Suppliers::update( $supplier_id, $data );
		} else {
			$result = WC_Inventory_Overview_Suppliers::create( $data );
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'wc_io_supplier_save_err_' . get_current_user_id(), $result->get_error_message(), 120 );
			wp_safe_redirect(
				admin_url(
					'admin.php?page=' . self::PAGE_SLUG . '&tab=' . self::TAB_SUPPLIERS . '&wc_io_supplier=err'
				)
			);
			exit;
		}

		$redirect_id = $supplier_id > 0 ? $supplier_id : absint( $result );
		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::PAGE_SLUG
				. '&tab=' . self::TAB_SUPPLIERS
				. '&action=edit'
				. '&supplier_id=' . $redirect_id
				. '&wc_io_supplier=saved'
			)
		);
		exit;
	}

	/**
	 * Handle supplier archive via admin-post (POST form or nonce-safe GET row action).
	 */
	public function handle_supplier_archive() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$supplier_id = isset( $_REQUEST['supplier_id'] ) ? absint( $_REQUEST['supplier_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $supplier_id ) {
			wp_die( 'Invalid supplier ID.' );
		}

		check_admin_referer( 'wc_io_supplier_archive_' . $supplier_id, 'wc_io_supplier_archive_nonce' );

		WC_Inventory_Overview_Suppliers::archive( $supplier_id );
		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::PAGE_SLUG
				. '&tab=' . self::TAB_SUPPLIERS
				. '&action=edit'
				. '&supplier_id=' . $supplier_id
				. '&wc_io_supplier=archived'
			)
		);
		exit;
	}

	/**
	 * Handle supplier reactivate via admin-post (POST form or nonce-safe GET row action).
	 */
	public function handle_supplier_reactivate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$supplier_id = isset( $_REQUEST['supplier_id'] ) ? absint( $_REQUEST['supplier_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $supplier_id ) {
			wp_die( 'Invalid supplier ID.' );
		}

		check_admin_referer( 'wc_io_supplier_reactivate_' . $supplier_id, 'wc_io_supplier_reactivate_nonce' );

		WC_Inventory_Overview_Suppliers::reactivate( $supplier_id );
		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::PAGE_SLUG
				. '&tab=' . self::TAB_SUPPLIERS
				. '&action=edit'
				. '&supplier_id=' . $supplier_id
				. '&wc_io_supplier=reactivated'
			)
		);
		exit;
	}

	/**
	 * AJAX: Search suppliers autocomplete.
	 */
	public function ajax_search_suppliers() {
		check_ajax_referer( 'wc_io_search_suppliers', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

		$suppliers = WC_Inventory_Overview_Suppliers::list(
			array(
				'status'   => 'active',
				'search'   => $term,
				'per_page' => 20,
			)
		);

		$data = array();
		foreach ( $suppliers as $supplier ) {
			$data[] = array(
				'id'   => $supplier['id'],
				'text' => $supplier['name'],
			);
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Quick-create a new supplier.
	 */
	public function ajax_quick_create_supplier() {
		check_ajax_referer( 'wc_io_quick_create_supplier', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Insufficient permissions.' );
		}

		$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$currency = isset( $_POST['currency'] ) ? sanitize_key( wp_unslash( $_POST['currency'] ) ) : 'EUR';

		if ( ! $name ) {
			wp_send_json_error( 'Name is required.' );
		}

		$result = WC_Inventory_Overview_Suppliers::create(
			array(
				'name'             => $name,
				'default_currency' => $currency,
			)
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$supplier = WC_Inventory_Overview_Suppliers::get( $result );
		wp_send_json_success(
			array(
				'id'   => $result,
				'name' => $supplier['name'],
			)
		);
	}
}
