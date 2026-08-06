<?php
/**
 * WP-CLI: `wp wc-io reconcile-qty-received` — qty_received drift diagnostic/repair (M5).
 *
 * Satisfies INV-4's closing clause ("asserted by a reconciliation check") and is
 * the one explicitly-named, narrow exception to the qty_received sole-mutator
 * invariant — and even this exception never bypasses the chain: --fix writes only
 * through WC_Inventory_Overview_PO_Receiving_Sync::reconcile_line(), never through
 * WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received() directly and
 * never via raw SQL. See the M5 implementation plan §Reconciliation tooling.
 *
 * This is a diagnostic/repair tool for operational drift (a direct database edit, a
 * restored backup, a bug in an earlier version) — never invoked automatically as
 * part of posting or voiding, only run by an administrator on demand.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

/**
 * `wp wc-io reconcile-qty-received [--fix] [--po=<id>]`
 */
class WC_Inventory_Overview_Reconcile_CLI_Command {

	/**
	 * Verify (and, with --fix, repair) qty_received drift on Purchase Order lines.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Perform the repair. Without this flag, the command is strictly read-only —
	 * it reports drift but writes nothing.
	 *
	 * [--po=<id>]
	 * : Limit to one Purchase Order's lines. Without this, every PO line in the
	 * database is checked.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc-io reconcile-qty-received
	 *     wp wc-io reconcile-qty-received --fix
	 *     wp wc-io reconcile-qty-received --po=42 --fix
	 *
	 * @param array<int,string>    $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Associative args (--fix, --po).
	 */
	public function __invoke( $args, $assoc_args ) {
		$fix   = isset( $assoc_args['fix'] );
		$po_id = isset( $assoc_args['po'] ) ? absint( $assoc_args['po'] ) : 0;

		$lines = $po_id > 0
			? WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id )
			: self::all_po_lines();

		$verified = 0;
		$drift    = 0;
		$repaired = 0;

		foreach ( $lines as $line ) {
			$line_id = (int) $line['id'];
			$stored  = (float) $line['qty_received'];
			$correct = self::compute_correct_qty_received( $line_id );

			if ( 0.0 === round( $correct - $stored, 4 ) ) {
				++$verified;
				continue;
			}

			++$drift;
			$drift_amount = round( $correct - $stored, 4 );
			WP_CLI::log(
				sprintf(
					'PO line %d (PO %d): qty_received %s -> %s (drift %s%s)',
					$line_id,
					(int) $line['po_id'],
					wc_format_decimal( $stored, 4 ),
					wc_format_decimal( $correct, 4 ),
					$drift_amount >= 0 ? '+' : '',
					wc_format_decimal( $drift_amount, 4 )
				)
			);

			if ( ! $fix ) {
				continue;
			}

			$result = WC_Inventory_Overview_PO_Receiving_Sync::reconcile_line( $line_id, $correct, get_current_user_id() );
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( 'PO line %d: repair failed — %s', $line_id, $result->get_error_message() ) );
				continue;
			}
			++$repaired;
		}

		WP_CLI::success(
			sprintf(
				'Verified: %d, Drift found: %d, Repaired: %d%s',
				$verified,
				$drift,
				$repaired,
				$fix ? '' : ( $drift > 0 ? ' (dry run — pass --fix to apply)' : '' )
			)
		);
	}

	/**
	 * Sum of posted receipt-line quantities against a PO line — the verified-correct
	 * value INV-4 requires qty_received to equal. Voided receipts need no separate
	 * subtraction: their contribution was already reversed out of qty_received at
	 * void time via the same apply_line_delta()/reconcile_line() chain, so only
	 * currently-posted receipts are summed.
	 *
	 * @param int $po_line_id PO line id.
	 */
	private static function compute_correct_qty_received( int $po_line_id ): float {
		global $wpdb;
		$receipt_lines = WC_Inventory_Overview_Receipt_Lines::table_name();
		$receipts      = WC_Inventory_Overview_Goods_Receipts::table_name();

		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(rl.qty) FROM {$receipt_lines} rl INNER JOIN {$receipts} gr ON gr.id = rl.receipt_id WHERE rl.po_line_id = %d AND gr.status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$po_line_id,
				WC_Inventory_Overview_Goods_Receipt_Lifecycle::STATUS_POSTED
			)
		);

		return round( (float) $sum, 4 );
	}

	/**
	 * Every PO line in the database (no --po filter). Direct SQL, not a service
	 * method — this is the CLI's own read-only scan, not part of the sole-mutator
	 * write chain.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function all_po_lines(): array {
		global $wpdb;
		$table = WC_Inventory_Overview_Purchase_Order_Lines::table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY po_id ASC, line_index ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is a fixed, non-user-supplied table name.
		return is_array( $rows ) ? $rows : array();
	}
}

WP_CLI::add_command( 'wc-io reconcile-qty-received', 'WC_Inventory_Overview_Reconcile_CLI_Command' );
