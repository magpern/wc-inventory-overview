<?php
/**
 * Replenishment bulk-commit admin controller (M25 §11/§21/WP-M25-4).
 *
 * Registers admin_post_wc_io_replenishment_commit. Implements the exact
 * revalidation order from §11: capability -> nonce -> idempotency token ->
 * two-phase request-shape parsing (filter to selected rows, then validate
 * identity/qty on survivors only) -> delegate to
 * WC_Inventory_Overview_Replenishment_Commit_Service::commit() -> opaque
 * result transient -> PRG redirect to the Planning tab.
 *
 * Mirrors WC_Inventory_Overview_Goods_Receipt_Admin's structure (a
 * dedicated admin controller class, not grown onto PO_Admin or
 * Purchasing_Page directly).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk replenishment-commit admin controller.
 */
class WC_Inventory_Overview_Replenishment_Commit_Admin {

	const NONCE_ACTION                    = 'wc_io_replenishment_commit';
	const NONCE_FIELD                     = 'wc_io_replenishment_commit_nonce';
	const TOKEN_FIELD                     = 'wc_io_replenishment_commit_request_token';
	const TOKEN_CONTEXT                   = 'replenishment_commit';
	const RESULT_QUERY_ARG                = 'wc_io_commit_result';
	const VALIDATION_ERR_ARG              = 'wc_io_commit_validation_err';
	const RESULT_TRANSIENT_PREFIX         = 'wc_io_replen_result_';
	const VALIDATION_ERR_TRANSIENT_PREFIX = 'wc_io_replen_commit_validation_err_';
	const RESULT_TTL                      = 120;

	/**
	 * Register the admin-post handler.
	 */
	public static function init() {
		add_action( 'admin_post_wc_io_replenishment_commit', array( __CLASS__, 'handle_commit' ) );
	}

	/**
	 * Issue a fresh commit nonce+token pair for the Planning tab's form.
	 *
	 * @return array{nonce_action:string, nonce_field:string, token_field:string, token:string}
	 */
	public static function form_fields(): array {
		return array(
			'nonce_action' => self::NONCE_ACTION,
			'nonce_field'  => self::NONCE_FIELD,
			'token_field'  => self::TOKEN_FIELD,
			'token'        => WC_Inventory_Overview_PO_Request_Token::issue( self::TOKEN_CONTEXT ),
		);
	}

	/**
	 * Handle admin_post_wc_io_replenishment_commit.
	 */
	public static function handle_commit() {
		// §11 step 1: capability, independently of the Planning tab's own
		// VIEW_PO gate -- commit requires EDIT_PO (BR-M25-18/19).
		if ( ! WC_Inventory_Overview_Purchasing_Caps::current_user_can( WC_Inventory_Overview_Purchasing_Caps::EDIT_PO ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'wc-inventory-overview' ), 403 );
		}

		// §11 step 2: nonce.
		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		// §11 step 3: one-shot idempotency token.
		$token = isset( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above.
		if ( ! WC_Inventory_Overview_PO_Request_Token::consume( $token, self::TOKEN_CONTEXT ) ) {
			wp_die( esc_html__( 'This form has already been submitted or expired. Please go back and try again.', 'wc-inventory-overview' ), 400 );
		}

		// §11 step 4 / §7: two-phase request-shape parsing.
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) ) {
			wp_die( esc_html__( 'Malformed request.', 'wc-inventory-overview' ), 400 );
		}
		$raw_items = wp_unslash( $_POST['items'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized individually below.

		// Phase 1: filter to selected rows only. Rows never selected are
		// read and immediately discarded -- never validated, never counted
		// toward MAX_COMMIT_LINES.
		$selected_rows = array();
		foreach ( $raw_items as $row ) {
			if ( ! is_array( $row ) || empty( $row['selected'] ) ) {
				continue;
			}
			if ( ! array_key_exists( 'product_id', $row ) || ! array_key_exists( 'variation_id', $row ) || ! array_key_exists( 'qty', $row ) ) {
				// A *selected* row missing a required sibling field is a
				// structurally malformed request (never a normal operator
				// mistake) -- wp_die, not a PRG notice.
				wp_die( esc_html__( 'Malformed request.', 'wc-inventory-overview' ), 400 );
			}
			$selected_rows[] = $row;
		}

		if ( count( $selected_rows ) > WC_Inventory_Overview_Replenishment_Commit_Service::MAX_COMMIT_LINES ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %d: maximum allowed lines per commit. */
						__( 'You selected more than %d lines. Please select fewer and try again.', 'wc-inventory-overview' ),
						WC_Inventory_Overview_Replenishment_Commit_Service::MAX_COMMIT_LINES
					)
				),
				400
			);
		}

		if ( empty( $selected_rows ) ) {
			self::fail_validation_redirect( __( 'No lines were selected.', 'wc-inventory-overview' ) );
		}

		// Phase 2: validate identity/qty on survivors only. A selected row
		// with an invalid quantity fails the whole request with a specific,
		// correctable PRG notice -- not wp_die (BR-M25-27) -- since this is
		// a normal, correctable operator mistake.
		$items = array();
		foreach ( $selected_rows as $row ) {
			$product_id_raw   = $row['product_id'];
			$variation_id_raw = $row['variation_id'];
			$qty_raw          = $row['qty'];

			$ids_numeric  = is_numeric( $product_id_raw ) && is_numeric( $variation_id_raw );
			$product_id   = $ids_numeric ? (int) $product_id_raw : -1;
			$variation_id = $ids_numeric ? (int) $variation_id_raw : -1;
			$valid_ids    = $ids_numeric && $product_id >= 0 && $variation_id >= 0 && ( $product_id > 0 || $variation_id > 0 );

			$qty_numeric = is_numeric( $qty_raw );
			$qty         = $qty_numeric ? (float) $qty_raw : 0.0;
			$valid_qty   = $qty_numeric && is_finite( $qty ) && $qty > 0 && $qty <= 1000000;

			if ( ! $valid_ids || ! $valid_qty ) {
				self::fail_validation_redirect(
					sprintf(
						/* translators: %s: submitted product id. */
						__( 'Invalid quantity or product selection for product #%s. Please correct it and try again.', 'wc-inventory-overview' ),
						is_scalar( $product_id_raw ) ? (string) $product_id_raw : ''
					)
				);
			}

			$items[] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'qty'          => $qty,
			);
		}

		$result = WC_Inventory_Overview_Replenishment_Commit_Service::commit( $items );
		if ( is_wp_error( $result ) ) {
			// wc_io_replen_commit_too_many / wc_io_replen_commit_malformed --
			// both service-boundary violations the controller's own
			// pre-checks should already prevent under normal use; a crafted
			// request that still reaches here is rejected hard.
			wp_die( esc_html( $result->get_error_message() ), 400 );
		}

		// §21: opaque result transient, keyed by the current user's own id.
		$result_id = bin2hex( random_bytes( 6 ) );
		set_transient( self::RESULT_TRANSIENT_PREFIX . get_current_user_id() . '_' . $result_id, $result, self::RESULT_TTL );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                 => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'                  => WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING,
					self::RESULT_QUERY_ARG => $result_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * PRG redirect for a selected-row validation failure (not wp_die).
	 *
	 * @param string $message Human-readable message.
	 */
	private static function fail_validation_redirect( string $message ) {
		set_transient( self::VALIDATION_ERR_TRANSIENT_PREFIX . get_current_user_id(), $message, self::RESULT_TTL );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                   => WC_Inventory_Overview_Purchasing_Page::PAGE_SLUG,
					'tab'                    => WC_Inventory_Overview_Purchasing_Page::TAB_PLANNING,
					self::VALIDATION_ERR_ARG => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
