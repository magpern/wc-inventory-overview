<?php
/**
 * Unit tests for Milestone M13 — WC_Inventory_Overview_PO_Print_Renderer.
 *
 * Covers the Document Content Contract (m13-implementation-plan.md Part I):
 * every approved field renders, dynamic values are contextually escaped,
 * missing textual values fall back to the em dash, supplier contact fields
 * are simply absent (not an error) when the caller omitted them, and the
 * renderer's own trivial display arithmetic (line total, PO total) is
 * correct.
 *
 * @package WC_Inventory_Overview_Tests
 */

/**
 * M13 renderer unit tests.
 */
class Test_WC_IO_PO_Print_Renderer extends WP_UnitTestCase {

	/**
	 * A complete, valid render model -- the shape PO_Admin::handle_print()
	 * assembles.
	 *
	 * @return array<string,mixed>
	 */
	private function base_model(): array {
		return array(
			'store_name'          => 'Acme Test Store',
			'po_number'           => 'PO-0001',
			'status_label'        => 'Placed',
			'order_date'          => '2026-01-15',
			'expected_date'       => '2026-02-01',
			'expected_confidence' => 'estimated',
			'currency'            => 'EUR',
			'supplier'            => array(
				'name'      => 'Alpha Supply Co',
				'reference' => 'SUP-REF-1',
				'email'     => 'orders@alpha.example',
				'phone'     => '+1 555 0100',
			),
			'lines'               => array(
				array(
					'name'         => 'Widget A',
					'sku'          => 'WID-A',
					'supplier_sku' => 'ALPHA-WID-A',
					'qty_ordered'  => 10.0,
					'qty_received' => 4.0,
					'unit_cost'    => 2.50,
				),
				array(
					'name'         => 'Widget B',
					'sku'          => 'WID-B',
					'supplier_sku' => 'ALPHA-WID-B',
					'qty_ordered'  => 3.0,
					'qty_received' => 0.0,
					'unit_cost'    => 5.0,
				),
			),
		);
	}

	/**
	 * Every approved header/supplier/line field (Part I) appears in output.
	 */
	public function test_all_approved_fields_present() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		foreach ( array( 'Acme Test Store', 'PO-0001', 'Placed', '2026-01-15', '2026-02-01', 'estimated', 'EUR' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, 'Header field missing from output: ' . $needle );
		}

		foreach ( array( 'Alpha Supply Co', 'SUP-REF-1', 'orders@alpha.example', '+1 555 0100' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, 'Supplier field missing from output: ' . $needle );
		}

		foreach ( array( 'Widget A', 'WID-A', 'ALPHA-WID-A', 'Widget B', 'WID-B', 'ALPHA-WID-B' ) as $needle ) {
			$this->assertStringContainsString( $needle, $html, 'Line field missing from output: ' . $needle );
		}
	}

	/**
	 * Line total is the renderer's own trivial display arithmetic:
	 * qty_ordered * unit_cost.
	 */
	public function test_line_total_is_qty_ordered_times_unit_cost() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		// Line 1: 10 * 2.50 = 25.00. Line 2: 3 * 5.0 = 15.00.
		$this->assertStringContainsString( '25.00 EUR', $html );
		$this->assertStringContainsString( '15.00 EUR', $html );
	}

	/**
	 * PO total is the plain sum of every line's total: 25.00 + 15.00 = 40.00.
	 */
	public function test_total_is_sum_of_line_totals() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		$this->assertStringContainsString( '40.00 EUR', $html );
	}

	/**
	 * An empty PO (no lines) totals zero without error.
	 */
	public function test_total_with_zero_lines_is_zero() {
		$model          = $this->base_model();
		$model['lines'] = array();
		$html           = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		$this->assertStringContainsString( '0.00 EUR', $html );
	}

	/**
	 * Missing textual header/supplier/line values fall back to the em dash,
	 * never a raw blank cell and never a PHP notice/warning.
	 */
	public function test_missing_textual_values_show_em_dash_fallback() {
		$model                          = $this->base_model();
		$model['expected_date']         = '';
		$model['supplier']['reference'] = '';
		$model['lines'][0]['sku']       = '';

		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		$this->assertStringContainsString( '—', $html );
	}

	/**
	 * M13 plan Part D.2: when the caller could not resolve the supplier row,
	 * contact/reference fields are simply empty strings in the model -- the
	 * renderer must still succeed and show the fallback, never omit the
	 * whole supplier block or error.
	 */
	public function test_unresolvable_supplier_contact_fields_render_fallback_without_error() {
		$model             = $this->base_model();
		$model['supplier'] = array(
			'name'      => 'Header Snapshot Supplier Name',
			'reference' => '',
			'email'     => '',
			'phone'     => '',
		);

		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		$this->assertStringContainsString( 'Header Snapshot Supplier Name', $html );
		$this->assertStringContainsString( '—', $html );
	}

	/**
	 * Product/variation identity renders exactly as supplied by the caller
	 * -- the renderer has no way to know (and does not care) whether it
	 * came from a live product or a historical snapshot of a since-deleted
	 * one; it just formats what it is given.
	 */
	public function test_line_snapshot_values_used_as_supplied() {
		$model                     = $this->base_model();
		$model['lines'][0]['name'] = 'Deleted Product (Historical Snapshot)';
		$model['lines'][0]['sku']  = 'WAS-DELETED-SKU';

		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		$this->assertStringContainsString( 'Deleted Product (Historical Snapshot)', $html );
		$this->assertStringContainsString( 'WAS-DELETED-SKU', $html );
	}

	/**
	 * Dynamic values are contextually escaped -- a stored value containing
	 * markup must never reach the page as live HTML.
	 */
	public function test_dynamic_values_are_html_escaped() {
		$model                     = $this->base_model();
		$model['po_number']        = '<script>alert(1)</script>';
		$model['supplier']['name'] = '<img src=x onerror=alert(2)>';
		$model['lines'][0]['name'] = '<b>bold</b>';

		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( '<img src=x onerror=alert(2)>', $html );
		$this->assertStringNotContainsString( '<b>bold</b>', $html );

		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringContainsString( '&lt;img', $html );
		$this->assertStringContainsString( '&lt;b&gt;bold&lt;/b&gt;', $html );
	}

	/**
	 * Money formatting is number_format(..., 2) plus a bare currency code --
	 * never a store-base-currency wc_price() rendering (which would be
	 * wrong for a PO's own supplier currency).
	 */
	public function test_money_formatting_uses_two_decimals_and_bare_currency_code() {
		$model             = $this->base_model();
		$model['currency'] = 'USD';
		$model['lines']    = array(
			array(
				'name'         => 'X',
				'sku'          => 'X',
				'supplier_sku' => 'X',
				'qty_ordered'  => 1.0,
				'qty_received' => 0.0,
				'unit_cost'    => 3.999,
			),
		);

		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $model );

		// 3.999 rounds to 4.00 at two decimals.
		$this->assertStringContainsString( '4.00 USD', $html );
	}

	/**
	 * Print UX (Part J): a screen-only Print button calling window.print(),
	 * and a @media print rule that hides screen-only chrome -- so the page
	 * remains usable without JavaScript via the browser's native print
	 * command.
	 */
	public function test_print_button_and_media_print_rule_present() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		$this->assertStringContainsString( 'window.print()', $html );
		$this->assertStringContainsString( '@media print', $html );
		$this->assertStringContainsString( 'no-print', $html );
	}

	/**
	 * The document is standalone -- its own doctype/html/head/body, not a
	 * wp-admin fragment.
	 */
	public function test_document_is_standalone_html() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( '<html', $html );
		$this->assertStringContainsString( '<head>', $html );
		$this->assertStringContainsString( '<body>', $html );
	}

	/**
	 * No external CSS/JS/CDN/web-font reference anywhere in the document.
	 */
	public function test_no_external_resources() {
		$html = WC_Inventory_Overview_PO_Print_Renderer::render( $this->base_model() );

		$this->assertStringNotContainsString( 'http://', $html );
		$this->assertStringNotContainsString( 'https://', $html );
		$this->assertStringNotContainsString( '<link', $html );
		$this->assertStringNotContainsString( '<script src', $html );
	}
}
