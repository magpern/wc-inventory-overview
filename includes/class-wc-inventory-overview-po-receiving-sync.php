<?php
/**
 * PO receiving synchronization — sole owner of qty_received mutations (M5).
 *
 * WC_Inventory_Overview_PO_Receiving_Sync sits in the middle of the M5 ownership
 * chain: WC_Inventory_Overview_Goods_Receipt_Service (sole business orchestrator,
 * initiates the change) -> WC_Inventory_Overview_PO_Receiving_Sync (sole owner of
 * the qty_received mutation: computes the delta, recomputes PO status, authors the
 * PO event) -> WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received()
 * (sole physical database writer). No class may skip a tier. See the M5
 * implementation plan §Receiving-status ownership / Formal invariant.
 *
 * This class never opens its own database transaction. apply_line_delta() must run
 * inside Goods_Receipt_Service's already-open WC_Inventory_Overview_DB_Transaction
 * closure — its only caller anywhere in the codebase — so a failure here rolls back
 * together with the stock/cost mutation it accompanies. reconcile_line() is called
 * only by the reconciliation CLI command, which wraps each call in its own
 * transaction (one per corrected line).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sole owner of qty_received mutations and their PO-status/PO-event side effects.
 */
class WC_Inventory_Overview_PO_Receiving_Sync {

	/**
	 * Apply a receiving delta to a PO line — the normal receiving path.
	 *
	 * Called only from inside Goods_Receipt_Service::post()/void()'s transaction
	 * closure, immediately after that line's Restock_Service call and Movements
	 * insert succeed. Returns WP_Error on failure (never throws) — the caller is
	 * responsible for bridging that into its own throw_if_error() pattern, exactly
	 * like every other fallible call inside that transaction.
	 *
	 * @param int    $po_line_id     PO line id.
	 * @param float  $qty_delta      Signed delta: positive on post, negative (this
	 *                               receipt line's own exact stored qty) on void.
	 * @param int    $receipt_id     Originating Goods Receipt id.
	 * @param string $receipt_number Originating Goods Receipt number (for the event summary).
	 * @param int    $user_id        Acting user id.
	 * @param bool   $is_void        True when reversing a void; false when posting.
	 * @param bool   $over_receipt   Whether this post exceeds the line's outstanding at post time. Ignored when $is_void is true.
	 * @param float  $qty_over       Amount over outstanding, when $over_receipt is true.
	 * @return array{po_id:int,po_line_id:int,old_qty_received:float,new_qty_received:float,old_status:string,new_status:string}|WP_Error
	 */
	public static function apply_line_delta(
		int $po_line_id,
		float $qty_delta,
		int $receipt_id,
		string $receipt_number,
		int $user_id,
		bool $is_void,
		bool $over_receipt = false,
		float $qty_over = 0.0
	) {
		$context = array(
			'mode'           => $is_void ? 'void' : 'post',
			'receipt_id'     => $receipt_id,
			'receipt_number' => $receipt_number,
			'over_receipt'   => $over_receipt,
			'qty_over'       => $qty_over,
		);
		return self::apply_delta( $po_line_id, $qty_delta, $user_id, $context );
	}

	/**
	 * Correct a drifted qty_received counter to its verified-correct value — the
	 * reconciliation-CLI path. Called only by the reconciliation CLI command file
	 * (wc-io reconcile-qty-received --fix). Never bypasses increment_qty_received();
	 * it computes the corrective delta internally and reuses the identical private
	 * steps apply_line_delta() uses, differing only in the PO_Events type written
	 * and in requiring no originating receipt.
	 *
	 * @param int   $po_line_id           PO line id.
	 * @param float $correct_qty_received Verified-correct qty_received value.
	 * @param int   $user_id              Acting (CLI-invoking) user id.
	 * @return array{po_id:int,po_line_id:int,old_qty_received:float,new_qty_received:float,old_status:string,new_status:string}|WP_Error
	 */
	public static function reconcile_line( int $po_line_id, float $correct_qty_received, int $user_id ) {
		$line = WC_Inventory_Overview_Purchase_Order_Lines::get( $po_line_id );
		if ( is_wp_error( $line ) ) {
			return $line;
		}

		$current_qty_received = (float) $line['qty_received'];
		$delta                = round( $correct_qty_received - $current_qty_received, 4 );

		if ( 0.0 === $delta ) {
			// Nothing to correct — return a no-op result shape rather than performing
			// a zero-value UPDATE and a spurious event row.
			$po = WC_Inventory_Overview_Purchase_Orders::get( (int) $line['po_id'] );
			if ( is_wp_error( $po ) ) {
				return $po;
			}
			return array(
				'po_id'            => (int) $line['po_id'],
				'po_line_id'       => $po_line_id,
				'old_qty_received' => $current_qty_received,
				'new_qty_received' => $current_qty_received,
				'old_status'       => (string) $po['status'],
				'new_status'       => (string) $po['status'],
			);
		}

		return self::apply_delta( $po_line_id, $delta, $user_id, array( 'mode' => 'reconcile' ) );
	}

	/**
	 * Shared implementation for apply_line_delta() and reconcile_line(). Never
	 * called directly by any other file (architecture-guard enforced) — both
	 * public methods above are the only callers.
	 *
	 * @param int                  $po_line_id PO line id.
	 * @param float                $qty_delta  Signed delta.
	 * @param int                  $user_id    Acting user id.
	 * @param array<string, mixed> $context    'mode' => post|void|reconcile, plus mode-specific keys.
	 * @return array{po_id:int,po_line_id:int,old_qty_received:float,new_qty_received:float,old_status:string,new_status:string}|WP_Error
	 */
	private static function apply_delta( int $po_line_id, float $qty_delta, int $user_id, array $context ) {
		$before = WC_Inventory_Overview_Purchase_Order_Lines::get( $po_line_id );
		if ( is_wp_error( $before ) ) {
			return $before;
		}
		$old_qty_received = (float) $before['qty_received'];
		$po_id            = (int) $before['po_id'];

		$affected = WC_Inventory_Overview_Purchase_Order_Lines::increment_qty_received( $po_line_id, $qty_delta );
		if ( 1 !== $affected ) {
			return new WP_Error(
				'wc_io_po_receiving_sync_line_not_found',
				sprintf( 'Purchase order line %d could not be updated (0 rows affected).', $po_line_id )
			);
		}

		$after = WC_Inventory_Overview_Purchase_Order_Lines::get( $po_line_id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$new_qty_received = (float) $after['qty_received'];

		$po = WC_Inventory_Overview_Purchase_Orders::get( $po_id );
		if ( is_wp_error( $po ) ) {
			return $po;
		}
		$old_status = (string) $po['status'];

		$lines             = WC_Inventory_Overview_Purchase_Order_Lines::list_for_po( $po_id );
		$total_outstanding = 0.0;
		$total_received    = 0.0;
		foreach ( $lines as $l ) {
			$total_outstanding += WC_Inventory_Overview_PO_Quantities::outstanding(
				$l['qty_ordered'] ?? 0,
				$l['qty_received'] ?? 0,
				$l['qty_cancelled'] ?? 0
			);
			$total_received    += (float) ( $l['qty_received'] ?? 0 );
		}
		$total_outstanding = round( $total_outstanding, 4 );
		$total_received    = round( $total_received, 4 );

		$new_status = WC_Inventory_Overview_PO_Statuses::recompute_for_receiving( $old_status, $total_outstanding, $total_received );

		if ( $new_status !== $old_status ) {
			$status_updated = WC_Inventory_Overview_Purchase_Orders::update_fields(
				$po_id,
				array(
					'status'     => $new_status,
					'updated_by' => $user_id,
				)
			);
			if ( is_wp_error( $status_updated ) ) {
				return $status_updated;
			}
		}

		$line_event = self::write_line_event( $po_id, $po_line_id, $qty_delta, $old_qty_received, $new_qty_received, $user_id, $context );
		if ( is_wp_error( $line_event ) ) {
			return $line_event;
		}

		if ( $new_status !== $old_status && in_array( $new_status, array( WC_Inventory_Overview_PO_Statuses::PARTIALLY_RECEIVED, WC_Inventory_Overview_PO_Statuses::RECEIVED ), true ) ) {
			$header_event = self::write_header_status_event( $po_id, $new_status, $total_received, $total_outstanding, $user_id );
			if ( is_wp_error( $header_event ) ) {
				return $header_event;
			}
		}

		return array(
			'po_id'            => $po_id,
			'po_line_id'       => $po_line_id,
			'old_qty_received' => $old_qty_received,
			'new_qty_received' => $new_qty_received,
			'old_status'       => $old_status,
			'new_status'       => $new_status,
		);
	}

	/**
	 * Write the per-line PO event (po_line_received / po_line_receipt_voided / po_qty_received_reconciled).
	 *
	 * @param int                  $po_id             PO id.
	 * @param int                  $po_line_id        PO line id.
	 * @param float                $qty_delta         Signed delta applied.
	 * @param float                $old_qty_received  qty_received before this delta.
	 * @param float                $new_qty_received  qty_received after this delta.
	 * @param int                  $user_id            Acting user id.
	 * @param array<string, mixed> $context            See apply_delta().
	 * @return int|WP_Error New event id.
	 */
	private static function write_line_event( int $po_id, int $po_line_id, float $qty_delta, float $old_qty_received, float $new_qty_received, int $user_id, array $context ) {
		$mode = $context['mode'];

		if ( 'reconcile' === $mode ) {
			$summary = sprintf(
				/* translators: 1: old qty_received, 2: new qty_received, 3: signed delta */
				__( 'Reconciliation corrected qty_received from %1$s to %2$s (drift %3$s).', 'wc-inventory-overview' ),
				wc_format_decimal( $old_qty_received, 4 ),
				wc_format_decimal( $new_qty_received, 4 ),
				( $qty_delta >= 0 ? '+' : '' ) . wc_format_decimal( $qty_delta, 4 )
			);
			$event_type = WC_Inventory_Overview_PO_Events::TYPE_QTY_RECEIVED_RECONCILED;
			$data       = array(
				'old_qty_received' => $old_qty_received,
				'new_qty_received' => $new_qty_received,
				'qty_delta'        => $qty_delta,
			);
		} elseif ( 'void' === $mode ) {
			$summary = sprintf(
				/* translators: 1: quantity voided, 2: receipt number, 3: new qty_received */
				__( 'Voided %1$s from Goods Receipt %2$s (line now %3$s received).', 'wc-inventory-overview' ),
				wc_format_decimal( abs( $qty_delta ), 4 ),
				$context['receipt_number'],
				wc_format_decimal( $new_qty_received, 4 )
			);
			$event_type = WC_Inventory_Overview_PO_Events::TYPE_LINE_RECEIPT_VOIDED;
			$data       = array(
				'receipt_id'       => $context['receipt_id'],
				'receipt_number'   => $context['receipt_number'],
				'qty_delta'        => $qty_delta,
				'old_qty_received' => $old_qty_received,
				'new_qty_received' => $new_qty_received,
			);
		} else {
			$over_note = ! empty( $context['over_receipt'] )
				? ' ' . sprintf(
					/* translators: %s: quantity over outstanding */
					__( 'Over-received by %s units.', 'wc-inventory-overview' ),
					wc_format_decimal( $context['qty_over'], 4 )
				)
				: '';
			$summary = sprintf(
				/* translators: 1: quantity received, 2: receipt number, 3: new qty_received */
				__( 'Received %1$s via Goods Receipt %2$s (line now %3$s received).', 'wc-inventory-overview' ),
				wc_format_decimal( $qty_delta, 4 ),
				$context['receipt_number'],
				wc_format_decimal( $new_qty_received, 4 )
			) . $over_note;
			$event_type = WC_Inventory_Overview_PO_Events::TYPE_LINE_RECEIVED;
			$data       = array(
				'receipt_id'       => $context['receipt_id'],
				'receipt_number'   => $context['receipt_number'],
				'qty_delta'        => $qty_delta,
				'old_qty_received' => $old_qty_received,
				'new_qty_received' => $new_qty_received,
				'over_receipt'     => ! empty( $context['over_receipt'] ),
				'qty_over'         => (float) ( $context['qty_over'] ?? 0.0 ),
			);
		}

		return WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $po_id,
				'po_line_id' => $po_line_id,
				'event_type' => $event_type,
				'summary'    => $summary,
				'data'       => $data,
				'user_id'    => $user_id,
			)
		);
	}

	/**
	 * Write the header-level status-transition event (only called when the header
	 * status actually changed and the new status is partially_received or received —
	 * a downgrade back to `placed` writes no header event of its own; see the M5
	 * implementation plan §Receiving-status ownership).
	 *
	 * @param int    $po_id             PO id.
	 * @param string $new_status        New header status.
	 * @param float  $total_received    Sum of qty_received across all lines.
	 * @param float  $total_outstanding Sum of outstanding across all lines.
	 * @param int    $user_id           Acting user id.
	 * @return int|WP_Error New event id.
	 */
	private static function write_header_status_event( int $po_id, string $new_status, float $total_received, float $total_outstanding, int $user_id ) {
		if ( WC_Inventory_Overview_PO_Statuses::RECEIVED === $new_status ) {
			$event_type = WC_Inventory_Overview_PO_Events::TYPE_RECEIVED;
			$summary    = __( 'Purchase order fully received.', 'wc-inventory-overview' );
		} else {
			$event_type = WC_Inventory_Overview_PO_Events::TYPE_PARTIALLY_RECEIVED;
			$summary    = sprintf(
				/* translators: 1: total received, 2: total outstanding */
				__( 'Purchase order partially received (%1$s received, %2$s outstanding).', 'wc-inventory-overview' ),
				wc_format_decimal( $total_received, 4 ),
				wc_format_decimal( $total_outstanding, 4 )
			);
		}

		return WC_Inventory_Overview_PO_Events::add(
			array(
				'po_id'      => $po_id,
				'event_type' => $event_type,
				'summary'    => $summary,
				'data'       => array(
					'total_received'    => $total_received,
					'total_outstanding' => $total_outstanding,
				),
				'user_id'    => $user_id,
			)
		);
	}
}
