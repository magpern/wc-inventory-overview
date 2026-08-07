<?php
/**
 * Batch Intake admin form markup.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Echo Batch Intake HTML (preview via AJAX; apply via admin-post).
 *
 * @deprecated M6 (v1.23.0) — disabled-not-deleted. No longer called from
 * WC_Inventory_Overview_Plugin::render_restock_panel() — the Batch Intake
 * subview and its admin entry points were retired in M6. Retained,
 * unreachable, for reference during a migration audit; slated for physical
 * removal in M8 (see the M6 implementation plan's Retirement strategy —
 * "Disabled, not deleted").
 */
class WC_Inventory_Overview_Batch_Intake_UI {

	/**
	 * @deprecated M6 (v1.23.0) — unreachable; see class docblock.
	 * @param string $form_action admin-post.php URL (escaped).
	 */
	public static function render_panel( $form_action = '' ) {
		if ( '' === $form_action ) {
			$form_action = esc_url( admin_url( 'admin-post.php' ) );
		}

		$labels   = WC_Inventory_Overview_Batch_Intake_Service::landed_cost_type_labels();
		$def_cur  = WC_Inventory_Overview_Settings::get_default_purchase_currency();
		$def_date = wp_date( 'Y-m-d', null, wp_timezone() );
		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $def_cur ) {
			$def_rate = '1';
		} else {
			$lk = WC_Inventory_Overview_Exchange_Rates::get_exchange_rate_to_eur( $def_cur, $def_date );
			$def_rate = is_wp_error( $lk ) ? '' : wc_format_decimal( (string) $lk['rate'], 8 );
		}
		$rate_disabled = WC_Inventory_Overview_Settings::CURRENCY_EUR === $def_cur;

		echo '<div class="wc-io-batch-intake wc-io-wrap">';
		echo '<h2 id="wc-io-batch-intake-section" class="wp-heading-inline wc-io-tab-panel-title">' . esc_html__( 'Batch Intake', 'wc-inventory-overview' ) . '</h2>';
		echo '<p class="wc-io-batch-intake__lead description">' . esc_html__( 'Record a multi-line purchase: supplier details, purchase currency and exchange rate to EUR (stored on the batch), product line totals in that currency, and optional landed costs (each with its own currency and rate). Inventory costing and weighted averages always use EUR.', 'wc-inventory-overview' ) . '</p>';

		echo '<ol class="wc-io-batch-intake__steps">';
		echo '<li>' . esc_html__( 'Fill batch details, currency, and lines.', 'wc-inventory-overview' ) . '</li>';
		echo '<li>' . esc_html__( 'Preview to validate allocation and costing.', 'wc-inventory-overview' ) . '</li>';
		echo '<li>' . esc_html__( 'Confirm, then Apply Batch.', 'wc-inventory-overview' ) . '</li>';
		echo '</ol>';

		echo '<form id="wc-io-batch-intake-form" class="wc-io-batch-intake-form" method="post" action="' . $form_action . '" autocomplete="off">';
		echo '<input type="hidden" name="action" value="wc_io_batch_apply" />';
		wp_nonce_field( 'wc_io_batch_apply' );

		echo '<div class="wc-io-batch-card">';
		echo '<div class="wc-io-batch-card__header"><h3>' . esc_html__( '1. Batch details', 'wc-inventory-overview' ) . '</h3></div>';
		echo '<div class="wc-io-batch-card__body">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wc-io-batch-supplier">' . esc_html__( 'Supplier name', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="wc-io-batch-supplier" name="wc_io_batch_supplier" maxlength="190" autocomplete="organization" />';
		echo '<p class="description">' . esc_html__( 'Shown on movement records for this batch.', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-batch-reference">' . esc_html__( 'Invoice / reference', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="wc-io-batch-reference" name="wc_io_batch_reference" maxlength="190" />';
		echo '<p class="description">' . esc_html__( 'Appears at the top of each movement note (e.g. invoice number).', 'wc-inventory-overview' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="wc-io-batch-note">' . esc_html__( 'Batch note', 'wc-inventory-overview' ) . '</label></th><td>';
		echo '<textarea class="large-text" rows="2" id="wc-io-batch-note" name="wc_io_batch_note"></textarea>';
		echo '<p class="description">' . esc_html__( 'Optional context stored on the batch and copied into movement notes.', 'wc-inventory-overview' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Batch currency', 'wc-inventory-overview' ) . '</th><td>';
		echo '<fieldset class="wc-io-batch-fx-fieldset"><legend class="screen-reader-text">' . esc_html__( 'Batch currency and exchange rate', 'wc-inventory-overview' ) . '</legend>';
		echo '<p><label for="wc-io-batch-purchase-currency">' . esc_html__( 'Purchase currency', 'wc-inventory-overview' ) . '</label> ';
		echo '<select id="wc-io-batch-purchase-currency" name="wc_io_batch_purchase_currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '"' . selected( $def_cur, $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select></p>';
		$rate_input_class = 'small-text';
		if ( ! $rate_disabled && '' === trim( (string) $def_rate ) ) {
			$rate_input_class .= ' wc-io-batch-rate-manual-needed';
		}
		echo '<p><label for="wc-io-batch-exchange-rate">' . esc_html__( 'Exchange rate to EUR', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="text" inputmode="decimal" class="' . esc_attr( $rate_input_class ) . '" id="wc-io-batch-exchange-rate" name="wc_io_batch_exchange_rate_to_eur" value="' . esc_attr( $def_rate ) . '" data-server-rate="' . esc_attr( $def_rate ) . '"';
		echo $rate_disabled ? ' readonly="readonly"' : '';
		echo ' /> ';
		echo '<span class="description">' . esc_html__( 'Rate is prefilled from Exchange Rate History. You may override it for this batch. Applied batches keep their own stored rate.', 'wc-inventory-overview' ) . '</span></p>';
		echo '<p class="description wc-io-batch-rate-source-hint" id="wc-io-batch-rate-source-hint" aria-live="polite"></p>';
		echo '<p><label for="wc-io-batch-exchange-rate-date">' . esc_html__( 'Exchange rate date', 'wc-inventory-overview' ) . '</label> ';
		echo '<input type="date" id="wc-io-batch-exchange-rate-date" name="wc_io_batch_exchange_rate_date" value="' . esc_attr( $def_date ) . '" /></p>';
		echo '</fieldset></td></tr>';

		echo '</tbody></table>';
		echo '</div></div>';

		echo '<div class="wc-io-batch-card wc-io-batch-card--with-table">';
		echo '<div class="wc-io-batch-card__header"><h3>' . esc_html__( '2. Product lines', 'wc-inventory-overview' ) . '</h3></div>';
		echo '<div class="wc-io-batch-card__body">';
		echo '<p class="description">' . esc_html__( 'Each row: a variation or simple product with stock management enabled. Enter the line subtotal in the batch currency; the Converted EUR column estimates EUR using the batch exchange rate above (Preview applies the same math on the server).', 'wc-inventory-overview' ) . '</p>';
		echo '<div class="wc-io-batch-table-shell">';
		echo '<table class="widefat striped wc-io-batch-lines"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Product / variation', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Quantity', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num" id="wc-io-batch-line-subtotal-heading">' . esc_html( sprintf( /* translators: %s: ISO currency code (EUR, USD, SEK) */ __( 'Line subtotal (%s)', 'wc-inventory-overview' ), $def_cur ) ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Converted EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-batch-col--narrow">' . esc_html__( 'Remove', 'wc-inventory-overview' ) . '</th>';
		echo '</tr></thead><tbody id="wc-io-batch-lines-body">';

		self::render_product_line_row();

		echo '</tbody></table>';
		echo '</div>';
		echo '<p class="description wc-io-batch-lines-eur-footnote">' . esc_html__( 'All inventory valuation and weighted-average costing is stored internally in EUR.', 'wc-inventory-overview' ) . '</p>';
		echo '<p class="wc-io-batch-card__actions"><button type="button" class="button" id="wc-io-batch-add-product-line">' . esc_html__( 'Add product line', 'wc-inventory-overview' ) . '</button></p>';
		echo '</div></div>';

		echo '<div class="wc-io-batch-card wc-io-batch-card--with-table">';
		echo '<div class="wc-io-batch-card__header"><h3>' . esc_html__( '3. Additional landed costs', 'wc-inventory-overview' ) . '</h3></div>';
		echo '<div class="wc-io-batch-card__body">';
		echo '<p class="description">' . esc_html__( 'Optional. Allocated across product lines in proportion to each line’s EUR base cost after conversion. Each row can use a different currency: when it matches the batch currency the batch rate is used; otherwise the rate is prefilled from Exchange Rate History for the batch exchange date, or you can enter a rate manually.', 'wc-inventory-overview' ) . '</p>';
		echo '<div class="wc-io-batch-table-shell">';
		echo '<table class="widefat striped wc-io-batch-landed"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Cost type', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Amount', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Currency', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-num">' . esc_html__( 'Exchange rate to EUR', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Note', 'wc-inventory-overview' ) . '</th>';
		echo '<th scope="col" class="wc-io-batch-col--narrow">' . esc_html__( 'Remove', 'wc-inventory-overview' ) . '</th></tr></thead><tbody id="wc-io-batch-landed-body">';

		self::render_landed_row( $labels, $def_cur, $def_rate );

		echo '</tbody></table>';
		echo '</div>';
		echo '<p class="wc-io-batch-card__actions"><button type="button" class="button" id="wc-io-batch-add-landed">' . esc_html__( 'Add landed cost', 'wc-inventory-overview' ) . '</button></p>';
		echo '</div></div>';

		echo '<div class="wc-io-batch-card wc-io-batch-card--actions">';
		echo '<div class="wc-io-batch-card__header"><h3>' . esc_html__( '4. Preview & apply', 'wc-inventory-overview' ) . '</h3></div>';
		echo '<div class="wc-io-batch-card__body">';
		echo '<p class="description">' . esc_html__( 'Preview runs on the server and does not change data. Apply runs the same validation again, then writes the batch, stock, costs, and movements.', 'wc-inventory-overview' ) . '</p>';
		echo '<div class="wc-io-batch-actions">';
		echo '<button type="button" class="button button-primary" id="wc-io-batch-preview-btn">' . esc_html__( 'Preview Batch', 'wc-inventory-overview' ) . '</button>';
		echo '</div>';
		echo '<p class="wc-io-batch-confirm-wrap">';
		echo '<label class="wc-io-batch-confirm-label" for="wc-io-batch-confirm"><input type="checkbox" name="wc_io_batch_confirm" id="wc-io-batch-confirm" value="1" required="required" /> ';
		echo '<span class="wc-io-batch-confirm-label__text">' . esc_html__( 'I understand this batch will update stock and weighted average costs for the selected products.', 'wc-inventory-overview' ) . '</span></label>';
		echo '</p>';
		echo '<p class="wc-io-batch-apply-wrap">';
		echo '<button type="submit" class="button button-primary button-large" id="wc-io-batch-apply-btn" disabled="disabled" aria-disabled="true">' . esc_html__( 'Apply Batch', 'wc-inventory-overview' ) . '</button>';
		echo '</p>';
		echo '</div></div>';

		echo '</form>';

		echo '<div id="wc-io-batch-preview-root" class="wc-io-batch-preview-root" aria-live="polite" aria-relevant="additions removals"></div>';

		echo '<template id="wc-io-batch-line-template">';
		self::render_product_line_row();
		echo '</template>';
		echo '<template id="wc-io-batch-landed-template">';
		self::render_landed_row( $labels, $def_cur, $def_rate );
		echo '</template>';

		echo '</div>';
	}

	/**
	 * @param array<string, string> $labels           Cost type labels.
	 * @param string                  $default_currency Default row currency (usually batch purchase currency).
	 * @param string                  $default_fx_rate  Default FX to EUR for that currency.
	 */
	protected static function render_landed_row( array $labels, $default_currency = 'EUR', $default_fx_rate = '1' ) {
		$default_currency = strtoupper( (string) $default_currency );
		if ( WC_Inventory_Overview_Settings::CURRENCY_EUR === $default_currency ) {
			$default_fx_display = '1';
		} elseif ( '' === trim( (string) $default_fx_rate ) ) {
			$default_fx_display = '';
		} else {
			$default_fx_display = wc_format_decimal( (string) $default_fx_rate, 8 );
		}
		$rate_disabled = WC_Inventory_Overview_Settings::CURRENCY_EUR === $default_currency;

		echo '<tr class="wc-io-batch-landed-row">';
		echo '<td><select name="wc_io_batch_cost_type[]" class="wc-io-batch-cost-type">';
		echo '<option value="">' . esc_html__( '— Select —', 'wc-inventory-overview' ) . '</option>';
		foreach ( $labels as $slug => $lab ) {
			echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $lab ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="text" inputmode="decimal" class="small-text" name="wc_io_batch_cost_amount[]" value="" placeholder="0.00" /></td>';
		echo '<td><select name="wc_io_batch_cost_currency[]" class="wc-io-batch-cost-currency">';
		foreach ( WC_Inventory_Overview_Settings::allowed_purchase_currencies() as $c ) {
			echo '<option value="' . esc_attr( $c ) . '"' . selected( $default_currency, $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select></td>';
		echo '<td><input type="text" inputmode="decimal" class="small-text wc-io-batch-cost-exchange-rate" name="wc_io_batch_cost_exchange_rate[]" value="' . esc_attr( $default_fx_display ) . '"';
		echo $rate_disabled ? ' readonly="readonly"' : '';
		echo ' /></td>';
		echo '<td><input type="text" class="regular-text" name="wc_io_batch_cost_note[]" value="" maxlength="190" /></td>';
		echo '<td><button type="button" class="button-link wc-io-batch-remove-row" aria-label="' . esc_attr__( 'Remove row', 'wc-inventory-overview' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	protected static function render_product_line_row() {
		echo '<tr class="wc-io-batch-line-row">';
		echo '<td><select class="wc-product-search wc-io-batch-product" style="width:100%;min-width:16em;" name="wc_io_batch_line_product[]" data-placeholder="' . esc_attr__( 'Search for a product or variation…', 'wc-inventory-overview' ) . '" data-action="woocommerce_json_search_products_and_variations"><option value=""></option></select></td>';
		echo '<td class="wc-io-num"><input type="number" step="any" min="0.0001" class="small-text" name="wc_io_batch_line_qty[]" value="" /></td>';
		echo '<td class="wc-io-num"><input type="text" inputmode="decimal" class="small-text" name="wc_io_batch_line_cost[]" value="" placeholder="0.00" /></td>';
		echo '<td class="wc-io-num"><span class="wc-io-batch-line-eur-preview" aria-label="' . esc_attr__( 'Estimated EUR for this line (subtotal × batch rate)', 'wc-inventory-overview' ) . '">—</span></td>';
		echo '<td><button type="button" class="button-link wc-io-batch-remove-row" aria-label="' . esc_attr__( 'Remove line', 'wc-inventory-overview' ) . '">&times;</button></td>';
		echo '</tr>';
	}
}
