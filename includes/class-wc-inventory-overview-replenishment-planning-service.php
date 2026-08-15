<?php
/**
 * Replenishment Planning Service (M24).
 *
 * Read-only bulk orchestrator: composes the current needs-reorder worklist
 * (Summary), resolved supplier per item (Supplier_Preference_Resolver, fed
 * by Replenishment_Defaults + committed purchase history), and suggested
 * quantity (Replenishment_Defaults), grouped by supplier. Zero mutation,
 * zero schema, zero new domain owner -- every underlying calculation
 * (Position, needs_reorder classification, supplier precedence) is owned
 * elsewhere and only ever composed here (INV-M24-2/3/4).
 *
 * M24 is deliberately read-only: bulk Draft PO creation is M25's job. No
 * code path in this class calls PO_Service::create_draft(),
 * Purchase_Orders::create_draft(), Purchase_Order_Lines::create(),
 * PO_Events::add(), or PO_Product_Validator::validate() (§9.3/INV-M24-12).
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bulk read-only replenishment plan builder.
 */
class WC_Inventory_Overview_Replenishment_Planning_Service {

	/**
	 * Resolution/display cap for the catalog-wide (unscoped) discovery
	 * path (§9.5). Bounds RESOLUTION work (Replenishment_Defaults::get_bulk(),
	 * distinct_supplier_history_for_items_bulk(), Suppliers::list_by_ids(),
	 * display sort) -- never the catalog-wide gather/classify phase itself,
	 * which retains its own pre-existing, unmodified ~8,000-candidate
	 * ceiling (inherited from Summary, unaffected by this constant).
	 */
	const MAX_LINES = 500;

	/**
	 * Selection cap for the Inventory Overview bulk action (§10.1) -- kept
	 * bounded well under the redirect URL's 2,048-char safety margin.
	 * Enforced by the bulk-action handler (WP-M24-6), not by this class;
	 * declared here as the single source of truth both sides reference.
	 */
	const MAX_BULK_ACTION_SELECTION = 100;

	/**
	 * Build a read-only replenishment plan.
	 *
	 * Catalog-wide (empty $item_ids, BR-M24-1): discovers via
	 * Summary::get_needs_reorder_items() with $limit = self::MAX_LINES + 1
	 * (501 -- 500 for resolution/display plus one sentinel proving more
	 * exist, §9.5), so the classified needs-reorder result is already
	 * capped to at most 501 items before this method does anything else.
	 *
	 * Scoped (non-empty $item_ids, BR-M24-2): discovers via the same method
	 * with $limit = 0 (unbounded) -- the input is already bounded to
	 * <=self::MAX_BULK_ACTION_SELECTION externally by the bulk-action
	 * handler, so no additional truncation is required here (still guarded
	 * defensively below in case a future caller violates that contract).
	 *
	 * @param array<string, mixed> $base_params Base filters (search, category, stock_status, exclude_private) -- same shape Summary already accepts.
	 * @param int[]                $item_ids    Scoped item (product/variation) ids, or empty for catalog-wide.
	 * @return array{
	 *   groups: array<int, array{supplier_id:int, supplier_name:string, currency:string, lines: array<int, array{product_id:int, variation_id:int, name:string, sku:string, on_hand:float, incoming:float, position:float, threshold:float, qty_suggested:float, preferred_supplier_stale:bool}>}>,
	 *   unresolved: array<int, array{product_id:int, variation_id:int, name:string, sku:string, reason:string}>,
	 *   truncated: bool,
	 *   candidate_count: int,
	 * }
	 */
	public static function build_plan( array $base_params = array(), array $item_ids = array() ): array {
		$scoped = ! empty( $item_ids );
		$limit  = $scoped ? 0 : ( self::MAX_LINES + 1 );

		$discovery = WC_Inventory_Overview_Summary::get_needs_reorder_items( $base_params, $item_ids, $limit );
		$items     = $discovery['items'];
		$truncated = $discovery['truncated'];

		$candidate_count = count( $items );

		// §9.5 resolution bound: never hand more than MAX_LINES items to the
		// supplier/defaults/history resolution stages below, regardless of
		// which path produced them. Under contract this only ever fires on
		// the catalog-wide path (Summary already capped it to 501); kept
		// unconditional as a defensive backstop for the scoped path too.
		if ( count( $items ) > self::MAX_LINES ) {
			$truncated = true;
			$items     = array_slice( $items, 0, self::MAX_LINES );
		}

		if ( empty( $items ) ) {
			return array(
				'groups'          => array(),
				'unresolved'      => array(),
				'truncated'       => $truncated,
				'candidate_count' => $candidate_count,
			);
		}

		$product_ids   = array();
		$variation_ids = array();
		$item_post_ids = array();
		foreach ( $items as $item ) {
			$item_post_id    = $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			$item_post_ids[] = $item_post_id;
			if ( $item['variation_id'] > 0 ) {
				$variation_ids[] = $item['variation_id'];
			} else {
				$product_ids[] = $item['product_id'];
			}
		}

		$defaults = WC_Inventory_Overview_Replenishment_Defaults::get_bulk( $item_post_ids );
		$history  = WC_Inventory_Overview_Purchase_Order_Lines::distinct_supplier_history_for_items_bulk( $product_ids, $variation_ids );

		// §12 step 3: union every touched supplier id (preferred + history)
		// into exactly one Suppliers::list_by_ids() call.
		$touched_supplier_ids = array();
		foreach ( $item_post_ids as $item_post_id ) {
			$preferred = (int) ( $defaults[ $item_post_id ]['preferred_supplier_id'] ?? 0 );
			if ( $preferred > 0 ) {
				$touched_supplier_ids[] = $preferred;
			}
			foreach ( $history[ $item_post_id ] ?? array() as $supplier_id ) {
				$touched_supplier_ids[] = $supplier_id;
			}
		}
		$touched_supplier_ids = array_values( array_unique( $touched_supplier_ids ) );
		$supplier_rows        = WC_Inventory_Overview_Suppliers::list_by_ids( $touched_supplier_ids );

		$groups     = array();
		$unresolved = array();

		foreach ( $items as $item ) {
			$item_post_id = $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			$default      = $defaults[ $item_post_id ] ?? array(
				'preferred_supplier_id' => 0,
				'default_qty'           => 0.0,
			);
			$preferred_id = (int) $default['preferred_supplier_id'];

			$preferred_eligible = false;
			if ( $preferred_id > 0 && isset( $supplier_rows[ $preferred_id ] ) ) {
				$preferred_eligible = WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_rows[ $preferred_id ] );
			}

			$eligible_history_ids = array();
			foreach ( $history[ $item_post_id ] ?? array() as $supplier_id ) {
				if ( isset( $supplier_rows[ $supplier_id ] ) && WC_Inventory_Overview_Suppliers::is_eligible_for_selection( $supplier_rows[ $supplier_id ] ) ) {
					$eligible_history_ids[] = $supplier_id;
				}
			}

			// INV-M24-4: the sole precedence implementation, shared with
			// Reorder_Prefill_Service (BR-M24-4/5/6/7/8).
			$decision = WC_Inventory_Overview_Supplier_Preference_Resolver::decide(
				$preferred_id,
				$preferred_eligible,
				$eligible_history_ids
			);

			// §13: qty_suggested is Replenishment_Defaults' own value or
			// 0.0 -- never guessed, never defaulted to 1, never forecast.
			$qty_suggested = (float) $default['default_qty'];

			// Position/incoming derivation: get_needs_reorder_items() already
			// carries the item's Position (computed exclusively by
			// Inventory_Position_Service::get_positions_bulk() inside
			// Summary's own classify_needs_reorder_bulk() pass, INV-M24-2).
			// incoming = position - on_hand is the same non-speculative
			// algebraic inverse this codebase already uses at
			// Summary::get_low_stock_lines_for_chart() for an identical
			// purpose -- not a second Position computation, and not a
			// second get_positions_bulk() call for an already-resolved set
			// (INV-M24-12/§9.3).
			$incoming = max( 0.0, (float) $item['position'] - (float) $item['on_hand'] );

			$resolved_supplier_id = $decision['supplier_id'] > 0 && isset( $supplier_rows[ $decision['supplier_id'] ] )
				? $decision['supplier_id']
				: 0;

			if ( $resolved_supplier_id > 0 ) {
				$line = array(
					'product_id'               => $item['product_id'],
					'variation_id'              => $item['variation_id'],
					'name'                      => $item['name'],
					'sku'                       => $item['sku'],
					'on_hand'                   => (float) $item['on_hand'],
					'incoming'                  => $incoming,
					'position'                  => (float) $item['position'],
					'threshold'                 => (float) $item['threshold'],
					'qty_suggested'             => $qty_suggested,
					'preferred_supplier_stale'  => (bool) $decision['preferred_was_stale'],
				);

				if ( ! isset( $groups[ $resolved_supplier_id ] ) ) {
					$groups[ $resolved_supplier_id ] = array(
						'supplier_id'   => $resolved_supplier_id,
						'supplier_name' => (string) $supplier_rows[ $resolved_supplier_id ]['name'],
						'currency'      => (string) $supplier_rows[ $resolved_supplier_id ]['default_currency'],
						'lines'         => array(),
					);
				}
				$groups[ $resolved_supplier_id ]['lines'][] = $line;
			} else {
				$reason       = ( 'ambiguous' === $decision['history_outcome'] ) ? 'multiple_suppliers' : 'no_supplier';
				$unresolved[] = array(
					'product_id'   => $item['product_id'],
					'variation_id' => $item['variation_id'],
					'name'         => $item['name'],
					'sku'          => $item['sku'],
					'reason'       => $reason,
				);
			}
		}

		self::sort_display_order( $groups, $unresolved );

		return array(
			'groups'          => $groups,
			'unresolved'      => $unresolved,
			'truncated'       => $truncated,
			'candidate_count' => $candidate_count,
		);
	}

	/**
	 * §10.3 display-stage ordering, applied only to the already-capped
	 * (<=500 catalog-wide / <=100 scoped) resolved set -- never the full
	 * possibly-thousands-of-candidates pool (that would defeat §9.5's
	 * resolution bound). Supplier groups: name case-insensitive, then
	 * supplier_id ascending. The unresolved section itself always renders
	 * after groups (a template/consumer concern, not a sort -- see
	 * render_planning_tab()). Lines within a group/section: product name
	 * case-insensitive, then SKU, then item id (variation_id if set, else
	 * product_id).
	 *
	 * @param array<int, array{supplier_id:int, supplier_name:string, currency:string, lines: array}> $groups     By reference; sorted in place, keys (supplier_id) preserved.
	 * @param array<int, array{product_id:int, variation_id:int, name:string, sku:string, reason:string}>            $unresolved By reference; sorted in place.
	 */
	private static function sort_display_order( array &$groups, array &$unresolved ): void {
		$line_cmp = static function ( array $a, array $b ): int {
			$a_name = mb_strtolower( $a['name'] );
			$b_name = mb_strtolower( $b['name'] );
			if ( $a_name !== $b_name ) {
				return $a_name <=> $b_name;
			}
			if ( $a['sku'] !== $b['sku'] ) {
				return $a['sku'] <=> $b['sku'];
			}
			$a_id = $a['variation_id'] > 0 ? $a['variation_id'] : $a['product_id'];
			$b_id = $b['variation_id'] > 0 ? $b['variation_id'] : $b['product_id'];
			return $a_id <=> $b_id;
		};

		uasort(
			$groups,
			static function ( array $a, array $b ) {
				$a_name = mb_strtolower( $a['supplier_name'] );
				$b_name = mb_strtolower( $b['supplier_name'] );
				if ( $a_name !== $b_name ) {
					return $a_name <=> $b_name;
				}
				return $a['supplier_id'] <=> $b['supplier_id'];
			}
		);

		foreach ( $groups as &$group ) {
			usort( $group['lines'], $line_cmp );
		}
		unset( $group );

		usort( $unresolved, $line_cmp );
	}
}
