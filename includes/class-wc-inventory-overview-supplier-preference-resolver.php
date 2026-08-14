<?php
/**
 * Supplier preference precedence decision (M24).
 *
 * Pure, stateless decider extracted from
 * WC_Inventory_Overview_Reorder_Prefill_Service's supplier-resolution logic
 * (M22/M23) so both the single-item prefill (Reorder_Prefill_Service) and
 * the new bulk Replenishment Planning Service share exactly one precedence
 * implementation (INV-M24-4). Issues no queries, loads no products, owns no
 * notice text -- callers own their own notice/outcome presentation, exactly
 * as Reorder_Prefill_Service already did before this extraction.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sole owner of the "preferred supplier vs committed history" precedence
 * rule (INV-M24-4).
 */
class WC_Inventory_Overview_Supplier_Preference_Resolver {

	/**
	 * Decide the resolved supplier for one purchasable item.
	 *
	 * Precedence (M23 §10, unchanged by this extraction):
	 * 1. A configured, currently-eligible preferred supplier is used
	 *    directly -- committed history is never consulted
	 *    ($history_outcome = 'not_consulted').
	 * 2. A configured-but-ineligible (stale) preferred supplier falls back
	 *    to committed history; $preferred_was_stale = true regardless of
	 *    what history then resolves to.
	 * 3. No preferred supplier configured -- committed history alone
	 *    decides, $preferred_was_stale = false.
	 *
	 * History outcome (once consulted): zero eligible suppliers => 'none'
	 * (supplier_id 0); exactly one => 'single' (that supplier_id); more
	 * than one => 'ambiguous' (supplier_id 0, never auto-picked).
	 *
	 * @param int   $preferred_supplier_id         Configured preferred supplier id, or 0 if unset.
	 * @param bool  $preferred_supplier_eligible    Whether that supplier (if any) is currently eligible (WC_Inventory_Overview_Suppliers::is_eligible_for_selection()). Ignored when $preferred_supplier_id <= 0.
	 * @param int[] $eligible_history_supplier_ids  Distinct eligible supplier ids from committed purchase history, most-recent-order-first. Caller decides whether history needed to be queried at all (a currently-eligible preferred supplier never requires it).
	 * @return array{supplier_id:int,preferred_was_stale:bool,history_outcome:'not_consulted'|'none'|'single'|'ambiguous'}
	 */
	public static function decide( int $preferred_supplier_id, bool $preferred_supplier_eligible, array $eligible_history_supplier_ids ): array {
		if ( $preferred_supplier_id > 0 && $preferred_supplier_eligible ) {
			return array(
				'supplier_id'         => $preferred_supplier_id,
				'preferred_was_stale' => false,
				'history_outcome'     => 'not_consulted',
			);
		}

		$preferred_was_stale = ( $preferred_supplier_id > 0 && ! $preferred_supplier_eligible );

		$eligible_history_supplier_ids = array_values( $eligible_history_supplier_ids );
		$count                         = count( $eligible_history_supplier_ids );

		if ( 0 === $count ) {
			return array(
				'supplier_id'         => 0,
				'preferred_was_stale' => $preferred_was_stale,
				'history_outcome'     => 'none',
			);
		}

		if ( 1 === $count ) {
			return array(
				'supplier_id'         => (int) $eligible_history_supplier_ids[0],
				'preferred_was_stale' => $preferred_was_stale,
				'history_outcome'     => 'single',
			);
		}

		return array(
			'supplier_id'         => 0,
			'preferred_was_stale' => $preferred_was_stale,
			'history_outcome'     => 'ambiguous',
		);
	}
}
