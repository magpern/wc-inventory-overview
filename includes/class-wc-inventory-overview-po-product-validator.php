<?php
/**
 * INV-8 purchasable-item validation for PO lines (M2-B).
 *
 * Purchasing always references the purchasable inventory item: simple product or
 * variation — never a variable parent, grouped, or external product.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Product / variation validator for PO lines.
 */
class WC_Inventory_Overview_PO_Product_Validator {

	/**
	 * Validate a product_id / variation_id pair under INV-8.
	 *
	 * Convention (movements / batch lines):
	 * - Simple product: product_id = id, variation_id = 0
	 * - Variation: product_id = parent id, variation_id = variation id
	 *   (also accepts product_id = variation id with variation_id = 0, resolved to parent/variation)
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id (0 if none).
	 * @return array{product_id:int,variation_id:int,product:WC_Product}|WP_Error Normalized ids + product.
	 */
	public static function validate( int $product_id, int $variation_id = 0 ) {
		if ( $product_id <= 0 && $variation_id <= 0 ) {
			return new WP_Error( 'wc_io_po_product', 'A product or variation id is required' );
		}

		$resolved = self::resolve( $product_id, $variation_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$product = $resolved['product'];
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'wc_io_po_product', 'Resolved product is invalid' );
		}

		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return new WP_Error(
				'wc_io_po_product_type',
				'Select a variation or simple product, not a parent variable, grouped, or external product.'
			);
		}

		if ( ! $product->is_type( 'variation' ) && ! $product->is_type( 'simple' ) ) {
			return new WP_Error( 'wc_io_po_product_type', 'Unsupported product type for purchasing.' );
		}

		if ( ! $product->managing_stock() ) {
			return new WP_Error( 'wc_io_po_product_stock', 'Enable stock management for this product.' );
		}

		return $resolved;
	}

	/**
	 * Resolve and cross-check product/variation ids.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id.
	 * @return array{product_id:int,variation_id:int,product:WC_Product}|WP_Error
	 */
	private static function resolve( int $product_id, int $variation_id ) {
		if ( $variation_id > 0 ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
				return new WP_Error( 'wc_io_po_product', sprintf( 'Variation %d not found', $variation_id ) );
			}
			$parent_id = (int) $variation->get_parent_id();
			if ( $product_id > 0 && $product_id !== $parent_id && $product_id !== $variation_id ) {
				return new WP_Error(
					'wc_io_po_product_mismatch',
					sprintf( 'Product %d is not the parent of variation %d', $product_id, $variation_id )
				);
			}
			return array(
				'product_id'   => $parent_id,
				'variation_id' => $variation_id,
				'product'      => $variation,
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product ) {
			return new WP_Error( 'wc_io_po_product', sprintf( 'Product %d not found', $product_id ) );
		}

		if ( $product->is_type( 'variation' ) ) {
			return array(
				'product_id'   => (int) $product->get_parent_id(),
				'variation_id' => $product_id,
				'product'      => $product,
			);
		}

		return array(
			'product_id'   => $product_id,
			'variation_id' => 0,
			'product'      => $product,
		);
	}
}
