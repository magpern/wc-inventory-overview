<?php
/**
 * Product/variation configuration UI for replenishment defaults (M23).
 *
 * Wires two standard, unmodified WooCommerce extension hook pairs: the
 * Product Data → Inventory tab (simple products) and the variation panel
 * (variations). Renders no SQL of its own -- reads/writes route through
 * WC_Inventory_Overview_Replenishment_Defaults (the sole owner of the two
 * meta keys) and WC_Inventory_Overview_Suppliers (eligibility/listing).
 *
 * No new nonce: WooCommerce's own product-save nonce
 * ('woocommerce_meta_nonce'/'woocommerce_save_data', verified by
 * WC_Admin_Meta_Boxes::save_meta_boxes() before woocommerce_process_product_meta
 * fires) and its own variation-save AJAX nonce (verified by
 * WC_AJAX::save_variations() before woocommerce_save_product_variation
 * fires) already cover both hooks. Capability is WooCommerce core's own
 * product-edit gate (current_user_can('edit_product', $id)), identical at
 * render and save -- not Purchasing_Caps (§16 of the M23 plan).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the "Preferred supplier" / "Default replenishment
 * quantity" fields on the WooCommerce product edit screen.
 */
class WC_Inventory_Overview_Product_Replenishment_Admin {

	/**
	 * Register the four WooCommerce hooks this class wires. Registered
	 * behind is_admin() -- these hooks are admin-only anyway, but this
	 * makes the boundary explicit rather than relying only on the hook
	 * names themselves never firing on the frontend.
	 */
	public static function register(): void {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'woocommerce_product_options_stock_fields', array( __CLASS__, 'render_simple_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_simple_fields' ), 10, 2 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );
	}

	// ---------------------------------------------------------------
	// Simple product (Product Data → Inventory tab).
	// ---------------------------------------------------------------

	/**
	 * Render fields for a simple product. Zero args -- WooCommerce fires
	 * this hook with no parameters; $thepostid is the global WC sets for
	 * this exact template scope (WC_Meta_Box_Product_Data::output()).
	 */
	public static function render_simple_fields(): void {
		global $thepostid;

		$item_post_id = absint( $thepostid );
		if ( ! $item_post_id || ! current_user_can( 'edit_product', $item_post_id ) ) {
			return;
		}

		self::render_fields( $item_post_id, '', __( 'Applies to this product only.', 'wc-inventory-overview' ) );
	}

	/**
	 * Save handler for a simple product.
	 *
	 * @param int      $post_id Product post id.
	 * @param \WP_Post $post    Post object (unused; signature matches the WC hook).
	 */
	public static function save_simple_fields( $post_id, $post = null ): void {
		$item_post_id = absint( $post_id );
		if ( ! $item_post_id || ! current_user_can( 'edit_product', $item_post_id ) ) {
			return;
		}

		$supplier_key = self::field_name_supplier();
		$qty_key      = self::field_name_qty();

		self::save_fields(
			$item_post_id,
			isset( $_POST[ $supplier_key ] ) ? wp_unslash( $_POST[ $supplier_key ] ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core's own product-save nonce already verified this request before woocommerce_process_product_meta fired.
			isset( $_POST[ $qty_key ] ) ? wp_unslash( $_POST[ $qty_key ] ) : null // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	// ---------------------------------------------------------------
	// Variation panel.
	// ---------------------------------------------------------------

	/**
	 * Render fields for one variation row.
	 *
	 * @param int      $loop            Row index within the variation panel.
	 * @param array    $variation_data  Unused; signature matches the WC hook.
	 * @param \WP_Post $variation       Variation post object.
	 */
	public static function render_variation_fields( int $loop, $variation_data, $variation ): void {
		$item_post_id = $variation instanceof WP_Post ? absint( $variation->ID ) : absint( $variation );
		if ( ! $item_post_id || ! current_user_can( 'edit_product', $item_post_id ) ) {
			return;
		}

		self::render_fields( $item_post_id, (string) $loop, __( 'Applies to this variation only.', 'wc-inventory-overview' ) );
	}

	/**
	 * Save handler for one variation row.
	 *
	 * WC_AJAX::save_variations() only checks the general 'edit_products'
	 * capability, not per-item -- so this per-item current_user_can()
	 * check is a real, non-redundant addition (§16 of the M23 plan).
	 *
	 * @param int $variation_id Variation post id.
	 * @param int $i            Row index within the submitted variation batch.
	 */
	public static function save_variation_fields( $variation_id, $i ): void {
		$item_post_id = absint( $variation_id );
		if ( ! $item_post_id || ! current_user_can( 'edit_product', $item_post_id ) ) {
			return;
		}

		$supplier_key = self::field_name_supplier();
		$qty_key      = self::field_name_qty();

		self::save_fields(
			$item_post_id,
			isset( $_POST[ $supplier_key ][ $i ] ) ? wp_unslash( $_POST[ $supplier_key ][ $i ] ) : null, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC core's own variation-save AJAX nonce already verified this request before woocommerce_save_product_variation fired.
			isset( $_POST[ $qty_key ][ $i ] ) ? wp_unslash( $_POST[ $qty_key ][ $i ] ) : null // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
	}

	// ---------------------------------------------------------------
	// Shared rendering / saving.
	// ---------------------------------------------------------------

	private static function field_name_supplier(): string {
		return WC_Inventory_Overview_Replenishment_Defaults::META_PREFERRED_SUPPLIER;
	}

	private static function field_name_qty(): string {
		return WC_Inventory_Overview_Replenishment_Defaults::META_DEFAULT_QTY;
	}

	/**
	 * Persist both fields for one item. A null raw value means the field
	 * was not present in the submission at all (never happens for a
	 * genuine product/variation save, but defensive); an empty-string raw
	 * value is a deliberate clear.
	 *
	 * @param int                    $item_post_id  Simple product's own post id, or a variation's own post id.
	 * @param string|array|null      $supplier_raw  Raw submitted supplier id.
	 * @param string|array|null      $qty_raw       Raw submitted quantity.
	 */
	private static function save_fields( int $item_post_id, $supplier_raw, $qty_raw ): void {
		if ( null !== $supplier_raw ) {
			$result = WC_Inventory_Overview_Replenishment_Defaults::save_preferred_supplier( $item_post_id, absint( $supplier_raw ) );
			if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
			}
		}

		if ( null !== $qty_raw ) {
			$result = WC_Inventory_Overview_Replenishment_Defaults::save_default_qty( $item_post_id, sanitize_text_field( (string) $qty_raw ) );
			if ( is_wp_error( $result ) && class_exists( 'WC_Admin_Meta_Boxes' ) ) {
				WC_Admin_Meta_Boxes::add_error( $result->get_error_message() );
			}
		}
	}

	/**
	 * Render both fields for one item (simple product or one variation
	 * row). $loop_suffix is '' for a simple product (plain field
	 * name/id), or the variation panel's row index (index-arrayed field
	 * name/id, WooCommerce's own convention -- e.g. variable_description).
	 *
	 * @param int    $item_post_id Simple product's own post id, or a variation's own post id.
	 * @param string $loop_suffix  '' for a simple product, or the variation loop index.
	 * @param string $scope_note   Appended to both field descriptions.
	 */
	private static function render_fields( int $item_post_id, string $loop_suffix, string $scope_note ): void {
		$preferred_id = WC_Inventory_Overview_Replenishment_Defaults::get_preferred_supplier_id( $item_post_id );
		$default_qty  = WC_Inventory_Overview_Replenishment_Defaults::get_default_qty( $item_post_id );

		$options = array( 0 => __( '— None —', 'wc-inventory-overview' ) );

		$suppliers = WC_Inventory_Overview_Suppliers::list(
			array(
				'status'   => WC_Inventory_Overview_Suppliers::STATUS_ACTIVE,
				'per_page' => 200,
				'orderby'  => 'name',
				'order'    => 'ASC',
			)
		);

		$found_preferred = false;
		foreach ( $suppliers as $supplier ) {
			$id             = (int) $supplier['id'];
			$options[ $id ] = (string) $supplier['name'];
			if ( $id === $preferred_id ) {
				$found_preferred = true;
			}
		}

		// Silent-clobber guard: if the stored preferred supplier is no
		// longer in the active list, inject it as an explicit,
		// clearly-labeled option so an unrelated field save never
		// silently resets the preference to 0 (§8 of the M23 plan).
		if ( $preferred_id > 0 && ! $found_preferred ) {
			$stale = WC_Inventory_Overview_Suppliers::get( $preferred_id );
			$options[ $preferred_id ] = is_wp_error( $stale )
				? __( '(unavailable)', 'wc-inventory-overview' )
				/* translators: %s: supplier name */
				: sprintf( __( '%s (unavailable)', 'wc-inventory-overview' ), $stale['name'] );
		}

		$field_id_supplier    = self::field_name_supplier() . $loop_suffix;
		$field_id_qty         = self::field_name_qty() . $loop_suffix;
		$field_name_supplier  = '' === $loop_suffix ? self::field_name_supplier() : self::field_name_supplier() . '[' . $loop_suffix . ']';
		$field_name_qty       = '' === $loop_suffix ? self::field_name_qty() : self::field_name_qty() . '[' . $loop_suffix . ']';
		$qty_value            = $default_qty > 0 ? wc_format_decimal( $default_qty, 4, true ) : '';

		// Hand-rolled markup (matching PO_Admin's own established pattern,
		// e.g. render_header_fields()'s supplier <select>) rather than
		// woocommerce_wp_select()/woocommerce_wp_text_input() -- those
		// helpers live in WooCommerce's admin-only wc-meta-box-functions.php,
		// which core only loads when is_admin() is true at WC's own init
		// (never the case under the WP PHPUnit test harness), so relying on
		// them would make this class untestable without a bootstrap-order
		// workaround. selected() is a WP core function, always available.
		?>
		<p class="form-field <?php echo esc_attr( $field_id_supplier ); ?>_field">
			<label for="<?php echo esc_attr( $field_id_supplier ); ?>"><?php esc_html_e( 'Preferred supplier', 'wc-inventory-overview' ); ?></label>
			<select id="<?php echo esc_attr( $field_id_supplier ); ?>" name="<?php echo esc_attr( $field_name_supplier ); ?>" class="select short">
				<?php foreach ( $options as $option_id => $option_label ) : ?>
					<option value="<?php echo esc_attr( (string) $option_id ); ?>" <?php selected( $preferred_id, (int) $option_id ); ?>><?php echo esc_html( $option_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php echo esc_html( __( 'Preselected on the "Create Draft PO" quick action when currently eligible.', 'wc-inventory-overview' ) . ' ' . $scope_note ); ?></span>
		</p>
		<p class="form-field <?php echo esc_attr( $field_id_qty ); ?>_field">
			<label for="<?php echo esc_attr( $field_id_qty ); ?>"><?php esc_html_e( 'Default replenishment quantity', 'wc-inventory-overview' ); ?></label>
			<input type="number" step="0.0001" min="0" class="short" id="<?php echo esc_attr( $field_id_qty ); ?>" name="<?php echo esc_attr( $field_name_qty ); ?>" value="<?php echo esc_attr( $qty_value ); ?>" />
			<span class="description"><?php echo esc_html( __( 'Prefills the quantity on the "Create Draft PO" quick action. Leave blank to use the ordinary default.', 'wc-inventory-overview' ) . ' ' . $scope_note ); ?></span>
		</p>
		<?php
	}
}
