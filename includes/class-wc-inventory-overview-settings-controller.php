<?php
/**
 * Settings admin controller — extracted from main Plugin class in M18.
 *
 * Handles: settings save, exchange rates, danger zone reset.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings tab controller.
 */
class WC_Inventory_Overview_Settings_Controller {

	/**
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks for Settings handlers and assets.
	 */
	public function init() {
		add_action( 'admin_post_wc_io_save_settings', array( $this, 'handle_save_settings_post' ) );
		add_action( 'admin_post_wc_io_add_exchange_rate', array( $this, 'handle_add_exchange_rate_post' ) );
		add_action( 'admin_post_wc_io_delete_exchange_rate', array( $this, 'handle_delete_exchange_rate_post' ) );
		add_action( 'admin_post_wc_io_danger_reset_preview', array( $this, 'handle_danger_reset_preview_post' ) );
		add_action( 'admin_post_wc_io_danger_reset_apply', array( $this, 'handle_danger_reset_apply_post' ) );
		add_action( 'wp_ajax_wc_io_get_exchange_rate', array( $this, 'ajax_get_exchange_rate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_shipping_assets' ) );
	}

	public function handle_save_settings_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_save_settings', '_wc_io_settings_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$res  = WC_Inventory_Overview_Settings::save_from_post( $post );
		if ( is_wp_error( $res ) ) {
			set_transient( 'wc_io_settings_save_err_' . get_current_user_id(), $res->get_error_message(), 120 );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'           => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'            => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_settings' => 'err',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'            => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
					'wc_io_settings' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_add_exchange_rate_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_add_exchange_rate', '_wc_io_add_exchange_rate_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$from = isset( $post['wc_io_fx_from'] ) ? strtoupper( sanitize_text_field( (string) $post['wc_io_fx_from'] ) ) : '';
		$to   = isset( $post['wc_io_fx_to'] ) ? strtoupper( sanitize_text_field( (string) $post['wc_io_fx_to'] ) ) : WC_Inventory_Overview_Exchange_Rates::TO_CURRENCY;
		$rate = isset( $post['wc_io_fx_rate'] ) ? (string) $post['wc_io_fx_rate'] : '';
		$date = isset( $post['wc_io_fx_date'] ) ? sanitize_text_field( (string) $post['wc_io_fx_date'] ) : '';
		$note = isset( $post['wc_io_fx_note'] ) ? sanitize_textarea_field( (string) $post['wc_io_fx_note'] ) : '';

		$res = WC_Inventory_Overview_Exchange_Rates::insert_rate( $from, $to, $rate, $date, $note );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'          => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( $res->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'      => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
					'wc_io_fx' => 'added',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_delete_exchange_rate_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$id   = isset( $post['wc_io_fx_delete_id'] ) ? absint( $post['wc_io_fx_delete_id'] ) : 0;
		if ( $id <= 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'          => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( __( 'Invalid exchange rate id.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		check_admin_referer( 'wc_io_delete_exchange_rate_' . $id, '_wc_io_delete_fx_nonce' );

		$res = WC_Inventory_Overview_Exchange_Rates::delete_rate( $id );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'          => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_fx_err' => rawurlencode( $res->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'      => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
					'wc_io_fx' => 'deleted',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_danger_reset_preview_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_danger_reset_preview', '_wc_io_danger_reset_preview_nonce' );

		$post    = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$payload = WC_Inventory_Overview_Data_Reset::parse_request_payload( $post );
		if ( is_wp_error( $payload ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'             => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( $payload->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$counts = WC_Inventory_Overview_Data_Reset::preview_counts( $payload );
		$token  = wp_generate_password( 32, false, false );
		WC_Inventory_Overview_Data_Reset::store_preview( $token, $payload, $counts );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'       => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
					'wc_io_rst' => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_danger_reset_apply_post() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wc-inventory-overview' ) );
		}
		check_admin_referer( 'wc_io_danger_reset_apply', '_wc_io_danger_reset_apply_nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();

		if ( empty( $post['wc_io_danger_confirm'] ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'             => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'You must confirm that you understand the consequences.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$typed = isset( $post['wc_io_danger_type_confirm'] ) ? trim( (string) $post['wc_io_danger_type_confirm'] ) : '';
		if ( 'RESET' !== $typed ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'             => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'Type RESET exactly in the confirmation field.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$token = isset( $post['wc_io_reset_preview_token'] ) ? sanitize_text_field( (string) $post['wc_io_reset_preview_token'] ) : '';
		$prev  = WC_Inventory_Overview_Data_Reset::get_preview( $token );
		if ( null === $prev ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'             => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( __( 'Preview expired or invalid. Run Preview Reset again.', 'wc-inventory-overview' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = WC_Inventory_Overview_Data_Reset::apply_reset( $prev['payload'], $prev['counts'] );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'            => WC_Inventory_Overview_Plugin::PAGE_SLUG,
						'tab'             => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
						'wc_io_reset_err' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		WC_Inventory_Overview_Data_Reset::delete_preview( $token );
		WC_Inventory_Overview_Data_Reset::store_result_notice(
			get_current_user_id(),
			array(
				'deleted' => $result,
				'counts'  => $prev['counts'],
			)
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                => WC_Inventory_Overview_Plugin::PAGE_SLUG,
					'tab'                 => WC_Inventory_Overview_Plugin::TAB_SETTINGS,
					'wc_io_reset_applied' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( isset( $_GET['wc_io_settings'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['wc_io_settings'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wc-inventory-overview' ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_settings'] ) && 'err' === sanitize_key( wp_unslash( $_GET['wc_io_settings'] ) ) ) {
			$err = get_transient( 'wc_io_settings_save_err_' . get_current_user_id() );
			if ( is_string( $err ) && '' !== $err ) {
				delete_transient( 'wc_io_settings_save_err_' . get_current_user_id() );
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
			}
		}

		if ( isset( $_GET['wc_io_fx'] ) && is_string( $_GET['wc_io_fx'] ) ) {
			$fxs = sanitize_key( wp_unslash( $_GET['wc_io_fx'] ) );
			if ( 'added' === $fxs ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exchange rate added.', 'wc-inventory-overview' ) . '</p></div>';
			} elseif ( 'deleted' === $fxs ) {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Exchange rate deleted.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}
		if ( isset( $_GET['wc_io_fx_err'] ) && is_string( $_GET['wc_io_fx_err'] ) ) {
			$fxe = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_fx_err'] ) ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $fxe ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_reset_err'] ) && is_string( $_GET['wc_io_reset_err'] ) ) {
			$err = sanitize_text_field( rawurldecode( wp_unslash( $_GET['wc_io_reset_err'] ) ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $err ) . '</p></div>';
		}

		if ( isset( $_GET['wc_io_reset_applied'] ) && '1' === sanitize_key( wp_unslash( $_GET['wc_io_reset_applied'] ) ) ) {
			$res = WC_Inventory_Overview_Data_Reset::consume_result_notice( get_current_user_id() );
			if ( is_array( $res ) && ! empty( $res['deleted'] ) && is_array( $res['deleted'] ) ) {
				$d    = $res['deleted'];
				$msg  = __( 'Danger Zone reset completed. Rows removed:', 'wc-inventory-overview' ) . ' ';
				$msg .= sprintf(
					/* translators: 1: movements, 2: batch costs, 3: batch lines, 4: batches, 5: line meta, 6: shipping meta, 7: product meta */
					__( 'movements %1$d; batch costs %2$d; batch lines %3$d; batches %4$d; order line snapshot meta %5$d; order shipping meta %6$d; product cost meta %7$d.', 'wc-inventory-overview' ),
					(int) ( $d['movements'] ?? 0 ),
					(int) ( $d['purchase_batch_costs'] ?? 0 ),
					(int) ( $d['purchase_batch_lines'] ?? 0 ),
					(int) ( $d['purchase_batches'] ?? 0 ),
					(int) ( $d['order_line_snapshot_meta'] ?? 0 ),
					(int) ( $d['order_shipping_meta'] ?? 0 ),
					(int) ( $d['product_cost_meta_rows'] ?? 0 )
				);
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Danger Zone reset completed.', 'wc-inventory-overview' ) . '</p></div>';
			}
		}

		$form_action = admin_url( 'admin-post.php' );
		$snap        = WC_Inventory_Overview_Settings::get_snapshot_order_status_mode();
		$inc_tax     = WC_Inventory_Overview_Settings::include_shipping_tax();
		$ship_pkg    = WC_Inventory_Overview_Settings::get_default_actual_shipping_package();
		$allow_zero  = WC_Inventory_Overview_Settings::allow_zero_supplier_cost();
		$auto_val    = WC_Inventory_Overview_Settings::auto_update_inventory_value_on_stock_edit();
		$low_def     = WC_Inventory_Overview_Settings::get_default_low_stock_threshold();
		$range       = WC_Inventory_Overview_Settings::get_default_reporting_range();
		$neg_hi      = WC_Inventory_Overview_Settings::highlight_negative_profit();
		$def_cur     = WC_Inventory_Overview_Settings::get_default_purchase_currency();
		$exp_deliv   = WC_Inventory_Overview_Settings::expected_delivery_renderer_enabled();
		$grace_days  = WC_Inventory_Overview_Settings::get_po_delay_grace_days();

		echo '<h2 class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Settings', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Configure how snapshots, costs, shipping, reporting ranges, and low-stock thresholds behave across Inventory & Profit.', 'wc-inventory-overview' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-settings-form" style="max-width:920px;margin-top:16px">';
		echo '<input type="hidden" name="action" value="wc_io_save_settings" />';
		wp_nonce_field( 'wc_io_save_settings', '_wc_io_settings_nonce' );

		echo '<h3>' . esc_html__( 'Profit calculation', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wc-io-snapshot-status">' . esc_html__( 'Snapshot order status', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_snapshot_order_status" id="wc-io-snapshot-status">';
		printf(
			'<option value="processing"%2$s>%1$s</option>',
			esc_html__( 'Processing only', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_PROCESSING, $snap, false )
		);
		printf(
			'<option value="completed"%2$s>%1$s</option>',
			esc_html__( 'Completed only', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_COMPLETED, $snap, false )
		);
		printf(
			'<option value="both"%2$s>%1$s</option>',
			esc_html__( 'Processing and completed', 'wc-inventory-overview' ),
			selected( WC_Inventory_Overview_Settings::SNAPSHOT_BOTH, $snap, false )
		);
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Controls when line-item price and cost snapshots are written, and which orders are included in product profitability aggregates.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Include shipping tax in revenue', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_include_shipping_tax" value="yes"' . checked( true, $inc_tax, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_include_shipping_tax" value="no"' . checked( false, $inc_tax, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'Order Profit “shipping paid” uses shipping total plus shipping tax when enabled; otherwise shipping total only.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Default actual shipping', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset class="wc-io-settings-def-ship"><legend class="screen-reader-text">' . esc_html__( 'Default actual shipping cost', 'wc-inventory-overview' ) . '</legend>';
		echo '<p><label for="wc-io-def-ship-entered-amount">' . esc_html__( 'Amount', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="wc_io_def_ship_entered_amount" id="wc-io-def-ship-entered-amount" inputmode="decimal" value="' . esc_attr( $ship_pkg['entered_amount'] ) . '" /></p>';
		echo '<p><label for="wc-io-def-ship-currency">' . esc_html__( 'Currency', 'wc-inventory-overview' ) . '</label><br />';
		echo '<select name="wc_io_def_ship_currency" id="wc-io-def-ship-currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $c ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $c ),
				selected( $ship_pkg['currency'], $c, false )
			);
		}
		echo '</select></p>';
		$rate_ro    = WC_Inventory_Overview_Settings::CURRENCY_EUR === $ship_pkg['currency'];
		$rate_class = 'small-text';
		if ( ! $rate_ro && (float) $ship_pkg['exchange_rate_to_eur'] <= 0 ) {
			$rate_class .= ' wc-io-batch-rate-manual-needed';
		}
		echo '<p><label for="wc-io-def-ship-exchange-rate">' . esc_html__( 'Exchange rate to EUR', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="' . esc_attr( $rate_class ) . '" name="wc_io_def_ship_exchange_rate_to_eur" id="wc-io-def-ship-exchange-rate" inputmode="decimal" value="' . esc_attr( wc_format_decimal( $ship_pkg['exchange_rate_to_eur'], 8 ) ) . '" data-server-rate="' . esc_attr( wc_format_decimal( $ship_pkg['exchange_rate_to_eur'], 8 ) ) . '"';
		echo $rate_ro ? ' readonly="readonly"' : '';
		echo ' /></p>';
		echo '<p><label for="wc-io-def-ship-fx-date">' . esc_html__( 'Exchange rate date', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="date" name="wc_io_def_ship_exchange_rate_date" id="wc-io-def-ship-fx-date" value="' . esc_attr( $ship_pkg['exchange_rate_date'] ) . '" /></p>';
		echo '<p class="description" id="wc-io-def-ship-rate-hint" aria-live="polite"></p>';
		echo '<p><strong>' . esc_html__( 'EUR value used in Order Profit', 'wc-inventory-overview' ) . '</strong>: ';
		echo '<span id="wc-io-def-ship-converted-saved">' . esc_html( wc_format_decimal( $ship_pkg['converted_eur'], 6 ) ) . '</span>';
		echo ' <span class="description">(' . esc_html__( 'estimate before save:', 'wc-inventory-overview' ) . ' <span id="wc-io-def-ship-converted-live">—</span>)</span></p>';
		echo '<p class="description">' . esc_html__( 'Used when an order has no saved Actual shipping cost. Converted internally to EUR for profitability reporting.', 'wc-inventory-overview' ) . ' ';
		echo esc_html__( 'Exchange rate uses Exchange Rate History (latest on or before the date), or enter a manual rate—same rules as Batch Intake.', 'wc-inventory-overview' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Inventory', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Allow zero supplier cost', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_allow_zero_supplier_cost" value="yes"' . checked( true, $allow_zero, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_allow_zero_supplier_cost" value="no"' . checked( false, $allow_zero, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'When set to No, purchase restock rejects a supplier unit cost of zero.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Auto-update inventory value on inline stock edit', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_auto_update_inventory_value_on_stock_edit" value="yes"' . checked( true, $auto_val, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_auto_update_inventory_value_on_stock_edit" value="no"' . checked( false, $auto_val, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'When Yes, changing stock on Inventory Overview recalculates inventory value as stock multiplied by average unit cost (when average cost exists).', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="wc-io-low-threshold">' . esc_html__( 'Default low stock threshold', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="0" step="1" name="wc_io_default_low_stock_threshold" id="wc-io-low-threshold" value="' . esc_attr( (string) $low_def ) . '" />';
		echo '<p class="description">' . esc_html__( 'Used for low-stock counts and badges when a product has no explicit low stock amount set.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Purchasing', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wc-io-po-delay-grace-days">' . esc_html__( 'PO delay grace period (days)', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" min="0" max="365" step="1" name="wc_io_po_delay_grace_days" id="wc-io-po-delay-grace-days" value="' . esc_attr( (string) $grace_days ) . '" />';
		echo '<p class="description">' . esc_html__( 'A Purchase Order line is only flagged "delayed" this many days after its expected date. Accepts 0-365. Invalid input is ignored and the previous value is kept.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Reporting', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="wc-io-def-range">' . esc_html__( 'Default reporting range', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_default_reporting_range" id="wc-io-def-range">';
		$ranges = array(
			WC_Inventory_Overview_Settings::RANGE_7D  => __( 'Last 7 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_30D => __( 'Last 30 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_90D => __( 'Last 90 days', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_YTD => __( 'Year to date', 'wc-inventory-overview' ),
			WC_Inventory_Overview_Settings::RANGE_ALL => __( 'All time', 'wc-inventory-overview' ),
		);
		foreach ( $ranges as $val => $label ) {
			printf(
				'<option value="%1$s"%3$s>%2$s</option>',
				esc_attr( $val ),
				esc_html( $label ),
				selected( $val, $range, false )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Initial date range on Order Profit and Product Profitability when you open the tab without date query parameters. Submit empty dates on those tabs to see all orders regardless of this default.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Highlight negative profit', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_highlight_negative_profit" value="yes"' . checked( true, $neg_hi, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_highlight_negative_profit" value="no"' . checked( false, $neg_hi, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'Applies emphasis styling to negative gross profit cells in Order Profit and Product Profitability.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Currency defaults (Batch Intake)', 'wc-inventory-overview' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Only the default purchase currency is stored here. Exchange rates for automatic prefills live in Exchange Rate History below. Each applied batch stores its own currency and rate; changing settings or history does not alter past batches.', 'wc-inventory-overview' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wc-io-def-purchase-currency">' . esc_html__( 'Default purchase currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_default_purchase_currency" id="wc-io-def-purchase-currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $cc ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $cc ),
				selected( $cc, $def_cur, false )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Batch Intake line costs are entered in this currency unless you change it on the batch form.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Storefront', 'wc-inventory-overview' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enable Expected Delivery display', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset><label><input type="radio" name="wc_io_expected_delivery_renderer_enabled" value="yes"' . checked( true, $exp_deliv, false ) . ' /> ' . esc_html__( 'Yes', 'wc-inventory-overview' ) . '</label> ';
		echo '<label><input type="radio" name="wc_io_expected_delivery_renderer_enabled" value="no"' . checked( false, $exp_deliv, false ) . ' /> ' . esc_html__( 'No', 'wc-inventory-overview' ) . '</label></fieldset>';
		echo '<p class="description">' . esc_html__( 'When Yes, an out-of-stock product with a customer-safe incoming delivery shows wording such as "Expected back around 1 September" instead of "Out of stock". Never shows supplier, PO, or quantity details.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'wc-inventory-overview' ) );
		echo '</form>';

		$this->render_exchange_rate_history_section();

		$this->render_settings_danger_zone();
	}

	protected function render_exchange_rate_history_section() {
		$form_action = admin_url( 'admin-post.php' );
		$rates       = WC_Inventory_Overview_Exchange_Rates::list_rates( 500 );

		echo '<div class="wc-io-exchange-rate-history" style="max-width:920px;margin-top:28px;">';
		echo '<h3>' . esc_html__( 'Exchange Rate History', 'wc-inventory-overview' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Exchange Rate History is the authoritative source for automatic Batch Intake FX prefills. Applied batches keep their stored rate; editing this list does not change past batches.', 'wc-inventory-overview' ) . '</p>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-fx-add-form" style="margin-top:16px;padding:12px 0;border-top:1px solid #dcdcde;">';
		echo '<input type="hidden" name="action" value="wc_io_add_exchange_rate" />';
		wp_nonce_field( 'wc_io_add_exchange_rate', '_wc_io_add_exchange_rate_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wc-io-fx-from">' . esc_html__( 'From currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select name="wc_io_fx_from" id="wc-io-fx-from">';
		echo '<option value="USD">USD</option>';
		echo '<option value="SEK">SEK</option>';
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-to">' . esc_html__( 'To currency', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<select id="wc-io-fx-to" disabled="disabled">';
		echo '<option value="EUR" selected="selected">EUR</option>';
		echo '</select>';
		echo '<input type="hidden" name="wc_io_fx_to" value="EUR" />';
		echo '<p class="description">' . esc_html__( 'Only EUR is supported as the target currency.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-rate">' . esc_html__( 'Exchange rate', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="small-text" name="wc_io_fx_rate" id="wc-io-fx-rate" inputmode="decimal" value="" required />';
		echo '<p class="description">' . esc_html__( 'Multiply one unit of the from-currency by this value to get EUR. Must be greater than zero.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-date">' . esc_html__( 'Rate date', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="date" name="wc_io_fx_date" id="wc-io-fx-date" value="' . esc_attr( wp_date( 'Y-m-d', null, wp_timezone() ) ) . '" required /></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-fx-note">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="wc_io_fx_note" id="wc-io-fx-note" maxlength="500" /></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Add rate', 'wc-inventory-overview' ), 'secondary', 'wc_io_fx_add_submit', false );
		echo '</form>';

		echo '<h4 style="margin-top:20px;">' . esc_html__( 'Recorded rates', 'wc-inventory-overview' ) . '</h4>';
		if ( empty( $rates ) ) {
			echo '<p class="description">' . esc_html__( 'No rates yet. Add one above.', 'wc-inventory-overview' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:920px;"><thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Date', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Rate', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Created by', 'wc-inventory-overview' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Actions', 'wc-inventory-overview' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rates as $r ) {
				$rid  = (int) $r['id'];
				$uid  = (int) $r['user_id'];
				$uobj = $uid > 0 ? get_userdata( $uid ) : false;
				$ulab = $uobj ? $uobj->display_name : __( '—', 'wc-inventory-overview' );
				$note = isset( $r['source_note'] ) && null !== $r['source_note'] ? (string) $r['source_note'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( (string) $r['rate_date'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['from_currency'] ) . '</td>';
				echo '<td>' . esc_html( (string) $r['to_currency'] ) . '</td>';
				echo '<td>' . esc_html( wc_format_decimal( (string) $r['exchange_rate'], 8 ) ) . '</td>';
				echo '<td>' . esc_html( $note ) . '</td>';
				echo '<td>' . esc_html( $ulab ) . '</td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( $form_action ) . '" style="display:inline;" onsubmit="return confirm(\'' . esc_js( __( 'Delete this exchange rate?', 'wc-inventory-overview' ) ) . '\');">';
				echo '<input type="hidden" name="action" value="wc_io_delete_exchange_rate" />';
				echo '<input type="hidden" name="wc_io_fx_delete_id" value="' . esc_attr( (string) $rid ) . '" />';
				wp_nonce_field( 'wc_io_delete_exchange_rate_' . $rid, '_wc_io_delete_fx_nonce' );
				submit_button( __( 'Delete', 'wc-inventory-overview' ), 'delete small', 'wc_io_fx_del_' . $rid, false );
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	protected function render_settings_danger_zone() {
		$form_action = admin_url( 'admin-post.php' );
		$rst_token   = isset( $_GET['wc_io_rst'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['wc_io_rst'] ) ) : '';
		$preview     = '' !== $rst_token ? WC_Inventory_Overview_Data_Reset::get_preview( $rst_token ) : null;

		echo '<div class="wc-io-danger-zone" style="max-width:920px;margin-top:32px;padding:16px;border:1px solid #c3c4c7;border-radius:4px;background:#fcf0f1;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Danger Zone', 'wc-inventory-overview' ) . '</h3>';
		echo '<div class="notice notice-warning" style="margin:12px 0;border-left-width:4px;max-width:none;"><p style="margin:0.65em 0;font-size:14px;line-height:1.5;">';
		echo esc_html__( 'Reset tools delete plugin reporting and audit data only. They do not undo WooCommerce stock changes, delete orders, delete products, or reverse inventory adjustments.', 'wc-inventory-overview' );
		echo '</p></div>';
		echo '<p class="description">' . esc_html__( 'Choose a date scope and reset options, then run Preview Reset and review counts before applying.', 'wc-inventory-overview' ) . '</p>';

		if ( null !== $preview ) {
			$this->render_danger_zone_apply_form( $rst_token, $preview );
		} else {
			if ( '' !== $rst_token ) {
				echo '<div class="notice notice-warning inline" style="margin:12px 0;"><p>' . esc_html__( 'That preview link expired or is invalid. Configure the reset again and run Preview Reset.', 'wc-inventory-overview' ) . '</p></div>';
			}
			$this->render_danger_zone_preview_form( $form_action );
		}

		echo '</div>';
	}

	protected function render_danger_zone_preview_form( $form_action ) {
		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-danger-preview-form" style="margin-top:12px">';
		echo '<input type="hidden" name="action" value="wc_io_danger_reset_preview" />';
		wp_nonce_field( 'wc_io_danger_reset_preview', '_wc_io_danger_reset_preview_nonce' );

		echo '<h4>' . esc_html__( 'Date scope', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Date scope', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_24H ) . '" /> ' . esc_html__( 'Last 24 hours', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_7D ) . '" checked="checked" /> ' . esc_html__( 'Last 7 days', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_CUSTOM ) . '" id="wc-io-danger-scope-custom" /> ' . esc_html__( 'Custom date range', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="radio" name="wc_io_reset_date_scope" value="' . esc_attr( WC_Inventory_Overview_Data_Reset::SCOPE_ALL ) . '" /> ' . esc_html__( 'All time', 'wc-inventory-overview' ) . '</label>';
		echo '</fieldset>';

		echo '<p id="wc-io-danger-custom-dates" class="wc-io-danger-custom-dates" style="display:none;margin-top:8px;">';
		echo '<label for="wc-io-danger-df">' . esc_html__( 'From', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="date" id="wc-io-danger-df" name="wc_io_reset_date_from" /> ';
		echo '<label for="wc-io-danger-dt">' . esc_html__( 'To', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="date" id="wc-io-danger-dt" name="wc_io_reset_date_to" />';
		echo '</p>';

		echo '<h4 style="margin-top:16px;">' . esc_html__( 'Reset options', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Reset options', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="checkbox" name="wc_io_reset_movements" value="1" /> ' . esc_html__( 'Clear inventory movements', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_batches" value="1" /> ' . esc_html__( 'Clear purchase batch history (batches, lines, landed costs)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_order_snapshots" value="1" /> ' . esc_html__( 'Clear order profit snapshots (order line item meta)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_pp_snapshots" value="1" /> ' . esc_html__( 'Clear product profitability snapshots (same line item meta as Order Profit)', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_all" value="1" id="wc-io-danger-reset-all" /> ' . esc_html__( 'Clear all plugin analytics / test data (selects every option above, plus optional items below when checked)', 'wc-inventory-overview' ) . '</label>';
		echo '</fieldset>';

		echo '<h4 style="margin-top:16px;">' . esc_html__( 'Optional removals', 'wc-inventory-overview' ) . '</h4>';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Optional removals', 'wc-inventory-overview' ) . '</legend>';
		echo '<label><input type="checkbox" name="wc_io_reset_shipping_meta" value="1" /> ' . esc_html__( 'Also remove per-order “Actual shipping cost” meta (_wc_io_actual_shipping_cost) for orders in the date scope', 'wc-inventory-overview' ) . '</label><br />';
		echo '<label><input type="checkbox" name="wc_io_reset_product_cost_meta" value="1" id="wc-io-danger-reset-product-cost" /> ' . esc_html__( 'Reset product costing meta (_wc_io_average_unit_cost, _wc_io_inventory_value) on all simple products and variations (ignores date scope)', 'wc-inventory-overview' ) . '</label>';
		echo '<p class="description" style="margin:10px 0 0 1.75em;max-width:52em;">' . esc_html__( 'Resetting product costing meta removes current average unit cost and inventory value. This does not change stock quantity.', 'wc-inventory-overview' ) . '</p>';
		echo '</fieldset>';

		echo '<p style="margin-top:16px;">';
		submit_button( __( 'Preview Reset', 'wc-inventory-overview' ), 'secondary', 'wc_io_danger_preview_submit', false );
		echo '</p>';
		echo '</form>';

		echo '<script>
(function(){
	var c=document.getElementById("wc-io-danger-scope-custom");
	var w=document.getElementById("wc-io-danger-custom-dates");
	var all=document.getElementById("wc-io-danger-reset-all");
	function sync(){if(!w)return;w.style.display=c&&c.checked?"block":"none";}
	if(c){c.addEventListener("change",sync);document.querySelectorAll(\'input[name="wc_io_reset_date_scope"]\').forEach(function(r){r.addEventListener("change",sync);});sync();}
	if(all){all.addEventListener("change",function(){if(all.checked){document.querySelectorAll(".wc-io-danger-preview-form input[type=checkbox]").forEach(function(x){if(x!==all&&x.name!=="wc_io_reset_shipping_meta"&&x.name!=="wc_io_reset_product_cost_meta"){x.checked=true;}});}});}
})();
</script>';
	}

	protected function render_danger_zone_apply_form( $token, array $preview ) {
		$form_action = admin_url( 'admin-post.php' );
		$payload     = $preview['payload'];
		$counts      = $preview['counts'];
		$t           = $payload['targets'];

		echo '<div class="notice notice-info inline" style="margin:12px 0;"><p><strong>' . esc_html__( 'Preview results — review counts before applying.', 'wc-inventory-overview' ) . '</strong></p></div>';
		echo '<ul class="wc-io-danger-preview-list" style="list-style:disc;margin-left:1.25em;">';
		if ( ! empty( $t['movements'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: row count */ __( 'Inventory movements to delete: %d', 'wc-inventory-overview' ), (int) $counts['movements'] ) ) . '</li>';
		}
		if ( ! empty( $t['batches'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: 1: batches, 2: lines, 3: costs */ __( 'Purchase batches: %1$d · Lines: %2$d · Landed cost rows: %3$d', 'wc-inventory-overview' ), (int) $counts['purchase_batches'], (int) $counts['purchase_batch_lines'], (int) $counts['purchase_batch_costs'] ) ) . '</li>';
		}
		if ( ! empty( $t['line_snapshots'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Order line snapshot meta rows to delete: %d', 'wc-inventory-overview' ), (int) $counts['order_line_snapshot_meta'] ) ) . '</li>';
		}
		if ( ! empty( $t['shipping_meta'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Order shipping meta rows to delete: %d', 'wc-inventory-overview' ), (int) $counts['order_shipping_meta'] ) ) . '</li>';
		}
		if ( ! empty( $t['product_cost_meta'] ) ) {
			echo '<li>' . esc_html( sprintf( /* translators: %d: meta rows */ __( 'Product costing meta rows to delete: %d (all products/variations)', 'wc-inventory-overview' ), (int) $counts['product_cost_meta_rows'] ) ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( $form_action ) . '" class="wc-io-danger-apply-form" style="margin-top:16px;padding-top:12px;border-top:1px solid #dcdcde;">';
		echo '<input type="hidden" name="action" value="wc_io_danger_reset_apply" />';
		echo '<input type="hidden" name="wc_io_reset_preview_token" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( 'wc_io_danger_reset_apply', '_wc_io_danger_reset_apply_nonce' );

		echo '<p><label><input type="checkbox" name="wc_io_danger_confirm" value="1" required /> ';
		echo '<span class="description">' . esc_html__( 'I understand this will permanently delete the listed plugin data and cannot be undone.', 'wc-inventory-overview' ) . '</span></label></p>';
		echo '<p><label for="wc-io-danger-type">' . esc_html__( 'Type RESET to confirm', 'wc-inventory-overview' ) . '</label><br />';
		echo '<input type="text" class="regular-text" name="wc_io_danger_type_confirm" id="wc-io-danger-type" value="" autocomplete="off" required /></p>';

		submit_button( __( 'Apply Reset', 'wc-inventory-overview' ), 'primary', 'wc_io_danger_apply_submit', false );
		echo '</form>';

		echo '<p style="margin-top:12px;"><a class="button" href="' . esc_url( WC_Inventory_Overview_Plugin::instance()->admin_url_tab( WC_Inventory_Overview_Plugin::TAB_SETTINGS ) ) . '">' . esc_html__( 'Cancel and start over', 'wc-inventory-overview' ) . '</a></p>';
	}

	public function enqueue_settings_shipping_assets( $hook_suffix ) {
		if ( 'woocommerce_page_' . WC_Inventory_Overview_Plugin::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		if ( WC_Inventory_Overview_Plugin::TAB_SETTINGS !== WC_Inventory_Overview_Plugin::instance()->get_requested_tab() ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_enqueue_style(
			'wc-inventory-overview-admin',
			plugins_url( 'assets/admin.css', WC_INVENTORY_OVERVIEW_FILE ),
			array(),
			WC_INVENTORY_OVERVIEW_VERSION
		);
		wp_enqueue_script(
			'wc-io-settings-shipping-fx',
			plugins_url( 'assets/settings-shipping-fx.js', WC_INVENTORY_OVERVIEW_FILE ),
			array( 'jquery' ),
			WC_INVENTORY_OVERVIEW_VERSION,
			true
		);
		wp_localize_script(
			'wc-io-settings-shipping-fx',
			'wcIoSettingsShipping',
			array(
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'exchangeRateNonce' => wp_create_nonce( 'wc_io_get_exchange_rate' ),
				'currencyEur'       => WC_Inventory_Overview_Settings::CURRENCY_EUR,
				'siteTodayYmd'      => wp_date( 'Y-m-d', null, wp_timezone() ),
				'strings'           => array(
					'rateHintHistory'   => __( 'Using historical rate from %s.', 'wc-inventory-overview' ),
					'rateHintNoHistory' => __( 'No historical exchange rate found for this date. Please enter a manual rate.', 'wc-inventory-overview' ),
					'rateHintEur'       => __( 'EUR always uses 1.', 'wc-inventory-overview' ),
					'rateHintAjaxError' => __( 'Could not load rate from server.', 'wc-inventory-overview' ),
				),
			)
		);
	}

	public function ajax_get_exchange_rate() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to load exchange rates.', 'wc-inventory-overview' ),
				),
				403
			);
		}
		check_ajax_referer( 'wc_io_get_exchange_rate', 'nonce' );

		$post = isset( $_POST ) && is_array( $_POST ) ? wp_unslash( $_POST ) : array();
		$cur  = isset( $post['currency'] ) ? strtoupper( sanitize_text_field( (string) $post['currency'] ) ) : '';
		$date = isset( $post['date'] ) ? sanitize_text_field( (string) $post['date'] ) : '';

		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $cur ) {
			wp_send_json_success(
				array(
					'rate'      => 1.0,
					'source'    => 'eur',
					'rate_date' => null,
				)
			);
		}

		$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $cur, $date );
		if ( is_wp_error( $lk ) ) {
			wp_send_json_error(
				array(
					'message' => $lk->get_error_message(),
					'code'    => $lk->get_error_code(),
				)
			);
		}

		wp_send_json_success(
			array(
				'rate'      => (float) $lk['rate'],
				'source'    => (string) $lk['source'],
				'rate_date' => $lk['rate_date'],
			)
		);
	}

}
