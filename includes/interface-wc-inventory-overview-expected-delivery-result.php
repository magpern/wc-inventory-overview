<?php
/**
 * Public contract for a resolved storefront expected-delivery answer (M7, API v1).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * The public API v1 contract returned by WC_Inventory_Overview_Expected_Delivery_Service.
 *
 * This interface — not the concrete implementation class — is the stable public
 * contract. Both Service methods, the `wc_io_expected_delivery_text` filter argument,
 * and all consumer documentation are typed against this interface.
 *
 * Within API_VERSION 1: this method set never shrinks, the STATE_* constants are
 * never removed or given new meanings, and expected_date() never changes format.
 * A backward-incompatible change requires a new API_VERSION, not a silent edit here.
 */
interface WC_Inventory_Overview_Expected_Delivery_Result_Interface {

	/**
	 * WooCommerce reports the item available. Renderer behavior: leave output unchanged.
	 */
	public const STATE_IN_STOCK = 'in_stock';

	/**
	 * Not available, and no open incoming supply at all. Renderer behavior: leave output unchanged.
	 */
	public const STATE_UNAVAILABLE = 'unavailable';

	/**
	 * Not available; a customer-safe earliest expected receipt exists. Renderer behavior: replace text, worded by confidence.
	 */
	public const STATE_EXPECTED_DATE = 'expected_date';

	/**
	 * Not available; incoming supply exists, but no date is safe enough to publish. Renderer behavior: replace text with "Expected soon".
	 */
	public const STATE_EXPECTED_SOON = 'expected_soon';

	/**
	 * The API_VERSION of the Service that produced this Result.
	 *
	 * Informational only. Consumers must never branch on it — the Service owns
	 * compatibility, and a contract change is expressed as a new API_VERSION on
	 * the Service, not as a runtime `if` here.
	 *
	 * @return int
	 */
	public function api_version(): int;

	/**
	 * WooCommerce's own is_in_stock() answer, echoed.
	 *
	 * @return bool
	 */
	public function available_now(): bool;

	/**
	 * One of the four STATE_* constants.
	 *
	 * @return string
	 */
	public function state(): string;

	/**
	 * Raw Y-m-d date, never localized. Non-null only in STATE_EXPECTED_DATE.
	 *
	 * @return string|null
	 */
	public function expected_date(): ?string;

	/**
	 * 'exact' or 'estimated'. Non-null only in STATE_EXPECTED_DATE. Never 'unknown'.
	 *
	 * @return string|null
	 */
	public function confidence(): ?string;
}
