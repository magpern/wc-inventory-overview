<?php
/**
 * One-shot request tokens for Purchasing admin PRG (M2-D).
 *
 * Prevents browser refresh / double-submit from repeating a mutation after a
 * successful POST. Consumed on first valid use.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * User-scoped one-shot request tokens.
 */
class WC_Inventory_Overview_PO_Request_Token {

	const TRANSIENT_PREFIX = 'wc_io_po_rt_';
	const TTL              = 600;

	/**
	 * Issue a new token for the current user and optional context.
	 *
	 * @param string $context Context key (e.g. place, save, duplicate).
	 * @return string Token.
	 */
	public static function issue( string $context = 'po' ): string {
		$token = wp_generate_password( 32, false, false );
		$key   = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		set_transient(
			$key,
			array(
				'user_id' => get_current_user_id(),
				'context' => sanitize_key( $context ),
				'issued'  => time(),
			),
			self::TTL
		);
		return $token;
	}

	/**
	 * Validate and consume a token. Returns true once; subsequent uses fail.
	 *
	 * @param string $token   Token from the form.
	 * @param string $context Expected context.
	 */
	public static function consume( string $token, string $context = 'po' ): bool {
		$token = sanitize_text_field( $token );
		if ( '' === $token ) {
			return false;
		}

		$key  = self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
		$data = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( (int) ( $data['user_id'] ?? 0 ) !== get_current_user_id() ) {
			return false;
		}
		if ( sanitize_key( (string) ( $data['context'] ?? '' ) ) !== sanitize_key( $context ) ) {
			return false;
		}

		return true;
	}
}
