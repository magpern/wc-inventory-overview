<?php
/**
 * Landed cost type vocabulary (M6, extracted from Batch Intake).
 *
 * Closes the hidden-coupling remediation trigger M4's own implementation
 * plan flagged: WC_Inventory_Overview_Goods_Receipt_Costing previously
 * called WC_Inventory_Overview_Batch_Intake_Service::allowed_cost_types()/
 * landed_cost_type_labels() directly, because at M4 time that vocabulary was
 * protected/internal to a feature slated for M6 retirement. Now that M6
 * disables Batch Intake's write path, that cross-reference is replaced with
 * this small, neutral class both Goods_Receipt_Costing (still needed) and,
 * while it remains disabled-not-deleted, Batch_Intake_Service depend on.
 *
 * Behavior-preserving extraction only — the seven cost-type slugs and their
 * admin labels are unchanged from Batch Intake's original vocabulary.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Landed cost type slug/label vocabulary.
 */
class WC_Inventory_Overview_Landed_Cost_Types {

	public const COST_TYPE_SHIPPING = 'shipping';

	public const COST_TYPE_CUSTOMS_VAT = 'customs_vat';

	public const COST_TYPE_EXCHANGE_FEE = 'exchange_fee';

	public const COST_TYPE_CRYPTO_TRANSFER = 'crypto_transfer_fee';

	public const COST_TYPE_PAYMENT_PROCESSOR = 'payment_processor_fee';

	public const COST_TYPE_BANK_FEE = 'bank_fee';

	public const COST_TYPE_MISCELLANEOUS = 'miscellaneous';

	/**
	 * Allowed landed cost type slugs => admin label.
	 *
	 * @return array<string, string>
	 */
	public static function landed_cost_type_labels() {
		return array(
			self::COST_TYPE_SHIPPING           => __( 'Shipping', 'wc-inventory-overview' ),
			self::COST_TYPE_CUSTOMS_VAT        => __( 'Customs / VAT', 'wc-inventory-overview' ),
			self::COST_TYPE_EXCHANGE_FEE       => __( 'Exchange fee', 'wc-inventory-overview' ),
			self::COST_TYPE_CRYPTO_TRANSFER    => __( 'Crypto transfer fee', 'wc-inventory-overview' ),
			self::COST_TYPE_PAYMENT_PROCESSOR  => __( 'Payment processor fee', 'wc-inventory-overview' ),
			self::COST_TYPE_BANK_FEE           => __( 'Bank fee', 'wc-inventory-overview' ),
			self::COST_TYPE_MISCELLANEOUS      => __( 'Miscellaneous', 'wc-inventory-overview' ),
		);
	}

	/**
	 * @return string[]
	 */
	public static function allowed_cost_types() {
		return array_keys( self::landed_cost_type_labels() );
	}
}
