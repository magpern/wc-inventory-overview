<?php
/**
 * Purchase Order printable-document renderer (M13).
 *
 * Presentation-only (INV-M13-2): receives an already-composed plain render
 * model — built by WC_Inventory_Overview_PO_Admin from the established read
 * owners (Purchase_Orders, Purchase_Order_Lines, Suppliers) — and formats/
 * escapes it into a standalone, print-friendly HTML document. Performs zero
 * database access, zero repository reads, zero authorization, zero
 * lifecycle checks, and zero mutation. The only arithmetic it performs is
 * trivial display arithmetic (qty_ordered * unit_cost per line, and the
 * plain sum of those for the PO total) -- never a business computation, and
 * never anything the caller was responsible for supplying pre-computed.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Printable Purchase Order HTML renderer.
 */
class WC_Inventory_Overview_PO_Print_Renderer {

	/**
	 * Display fallback for an absent textual value.
	 */
	const EMPTY_VALUE = '—';

	/**
	 * Render the standalone printable HTML document.
	 *
	 * Expected $model shape (all keys required; scalar values, no objects):
	 * - store_name          string
	 * - po_number           string
	 * - status_label        string
	 * - order_date          string
	 * - expected_date       string
	 * - expected_confidence string
	 * - currency            string
	 * - supplier            array{name:string,reference:string,email:string,phone:string}
	 * - lines               array<int,array{name:string,sku:string,supplier_sku:string,qty_ordered:float,qty_received:float,unit_cost:float}>
	 *
	 * Deliberately absent: any pre-computed line total or PO total. Per the
	 * approved renderer contract, qty_ordered * unit_cost and the line-total
	 * sum are this class's own trivial display arithmetic, not a caller
	 * concern -- the caller supplies only the raw ordered quantity and unit
	 * cost per line.
	 *
	 * @param array<string,mixed> $model Plain render model assembled by the caller.
	 * @return string Complete standalone HTML document.
	 */
	public static function render( array $model ): string {
		$lines = $model['lines'] ?? array();
		$total = self::sum_line_totals( $lines );
		ob_start();
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( self::text( $model['po_number'] ?? '' ) ); ?></title>
	<style>
		<?php echo self::inline_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, local, no dynamic value. ?>
	</style>
</head>
<body>
	<button type="button" class="wc-io-po-print-button no-print" onclick="window.print();"><?php esc_html_e( 'Print', 'wc-inventory-overview' ); ?></button>

	<div class="wc-io-po-print-document">
		<?php self::render_header( $model ); ?>
		<?php self::render_supplier( $model['supplier'] ?? array() ); ?>
		<?php self::render_lines( $lines, (string) ( $model['currency'] ?? '' ) ); ?>
		<?php self::render_total( $total, (string) ( $model['currency'] ?? '' ) ); ?>
	</div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Header block: store identity, PO number/status, dates, currency.
	 *
	 * @param array<string,mixed> $model Render model.
	 */
	private static function render_header( array $model ): void {
		?>
		<div class="wc-io-po-print-header">
			<h1><?php echo esc_html( self::text( $model['store_name'] ?? '' ) ); ?></h1>
			<table class="wc-io-po-print-meta">
				<tr>
					<th><?php esc_html_e( 'Purchase Order', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['po_number'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Status', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['status_label'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Order Date', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['order_date'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Expected Date', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['expected_date'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Expected Confidence', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['expected_confidence'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Currency', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $model['currency'] ?? '' ) ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Supplier block. Contact/reference fields are simply absent from the
	 * model (empty string) when the caller could not resolve the supplier
	 * row — this class has no knowledge of, and performs no check for, why
	 * a field is empty; it only ever formats what it is given.
	 *
	 * @param array<string,string> $supplier Supplier fields.
	 */
	private static function render_supplier( array $supplier ): void {
		?>
		<div class="wc-io-po-print-supplier">
			<h2><?php esc_html_e( 'Supplier', 'wc-inventory-overview' ); ?></h2>
			<table class="wc-io-po-print-meta">
				<tr>
					<th><?php esc_html_e( 'Name', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $supplier['name'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Reference', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $supplier['reference'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $supplier['email'] ?? '' ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Phone', 'wc-inventory-overview' ); ?></th>
					<td><?php echo esc_html( self::text( $supplier['phone'] ?? '' ) ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Line-items table. Product/variation identity is rendered exactly as
	 * supplied (the caller is responsible for sourcing it from the PO
	 * line's historical name_snapshot/sku_snapshot columns, never from a
	 * live product lookup — this class has no way to tell the difference
	 * and performs none).
	 *
	 * @param array<int,array<string,mixed>> $lines    Line rows.
	 * @param string                         $currency PO (header-level) currency code applied to every monetary cell.
	 */
	private static function render_lines( array $lines, string $currency ): void {
		?>
		<table class="wc-io-po-print-lines">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Supplier SKU', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Qty Ordered', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Qty Received', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Unit Price', 'wc-inventory-overview' ); ?></th>
					<th><?php esc_html_e( 'Line Total', 'wc-inventory-overview' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $lines as $line ) : ?>
					<tr>
						<td><?php echo esc_html( self::text( $line['name'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( self::text( $line['sku'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( self::text( $line['supplier_sku'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( self::quantity( $line['qty_ordered'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( self::quantity( $line['qty_received'] ?? 0 ) ); ?></td>
						<td><?php echo esc_html( self::money( $line['unit_cost'] ?? 0, $currency ) ); ?></td>
						<td><?php echo esc_html( self::money( self::line_total( $line ), $currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * PO total.
	 *
	 * @param float  $total    Pre-summed total (from sum_line_totals()).
	 * @param string $currency PO (header-level) currency code.
	 */
	private static function render_total( float $total, string $currency ): void {
		?>
		<div class="wc-io-po-print-total">
			<strong><?php esc_html_e( 'Total:', 'wc-inventory-overview' ); ?></strong>
			<?php echo esc_html( self::money( $total, $currency ) ); ?>
		</div>
		<?php
	}

	/**
	 * Trivial display arithmetic (task Renderer Contract): a single line's
	 * total is qty_ordered * unit_cost. Not a business computation -- the
	 * business fact (what was ordered, at what price) is already frozen on
	 * the PO line; this is only how it is displayed.
	 *
	 * @param array<string,mixed> $line Line row from the render model.
	 * @return float
	 */
	private static function line_total( array $line ): float {
		return (float) ( $line['qty_ordered'] ?? 0 ) * (float) ( $line['unit_cost'] ?? 0 );
	}

	/**
	 * Trivial display arithmetic: the PO total is the plain sum of every
	 * line's total.
	 *
	 * @param array<int,array<string,mixed>> $lines Line rows.
	 * @return float
	 */
	private static function sum_line_totals( array $lines ): float {
		$total = 0.0;
		foreach ( $lines as $line ) {
			$total += self::line_total( $line );
		}
		return $total;
	}

	/**
	 * Text formatting with the standard empty-value fallback. Pure display
	 * formatting, not a business rule.
	 *
	 * @param mixed $value Raw supplied value.
	 * @return string
	 */
	private static function text( $value ): string {
		$value = trim( (string) $value );
		return '' === $value ? self::EMPTY_VALUE : $value;
	}

	/**
	 * Quantity formatting (plain numeric display, no currency).
	 *
	 * @param mixed $value Raw supplied value.
	 * @return string
	 */
	private static function quantity( $value ): string {
		return (string) ( (float) $value );
	}

	/**
	 * Money formatting: number_format(..., 2) plus a bare currency code —
	 * never wc_price(), which formats in the store's own base currency
	 * rather than the PO's supplier currency.
	 *
	 * @param mixed  $amount   Raw supplied amount.
	 * @param string $currency Currency code (may be empty).
	 * @return string
	 */
	private static function money( $amount, $currency ): string {
		$formatted = number_format( (float) $amount, 2 );
		$currency  = trim( (string) $currency );
		return '' === $currency ? $formatted : $formatted . ' ' . $currency;
	}

	/**
	 * Minimal, local, inline print stylesheet. No external CSS/CDN/fonts.
	 *
	 * @return string
	 */
	private static function inline_css(): string {
		return '
			body { font-family: -apple-system, Helvetica, Arial, sans-serif; color: #111; margin: 2em; }
			h1 { font-size: 1.4em; margin-bottom: 0.5em; }
			h2 { font-size: 1.1em; margin-top: 1.5em; }
			table.wc-io-po-print-meta { border-collapse: collapse; margin-bottom: 1em; }
			table.wc-io-po-print-meta th { text-align: left; padding: 2px 12px 2px 0; color: #555; font-weight: normal; }
			table.wc-io-po-print-meta td { padding: 2px 0; font-weight: bold; }
			table.wc-io-po-print-lines { width: 100%; border-collapse: collapse; margin-top: 1em; }
			table.wc-io-po-print-lines th, table.wc-io-po-print-lines td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; font-size: 0.9em; }
			table.wc-io-po-print-lines th { background: #f5f5f5; }
			.wc-io-po-print-total { margin-top: 1em; text-align: right; font-size: 1.1em; }
			.wc-io-po-print-button { margin-bottom: 1.5em; padding: 8px 16px; font-size: 1em; cursor: pointer; }
			@media print {
				.no-print { display: none; }
				body { margin: 0; }
			}
		';
	}
}
