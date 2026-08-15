<?php
/**
 * Dashboard counts: list total vs sellable SKU lines for stock metrics.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Summary metrics for the inventory screen.
 */
class WC_Inventory_Overview_Summary {

	/**
	 * @param array<string, mixed> $base_params Same base as list table (no pagination keys).
	 * @return array<string, int>
	 */
	public static function build( array $base_params ) {
		$count = static function ( array $extra ) use ( $base_params ) {
			$params = array_merge(
				$base_params,
				$extra,
				array(
					'per_page' => 1,
					'paged'    => 1,
				)
			);
			$r = WC_Inventory_Overview_Repository::query_products( $params );

			return isset( $r['total'] ) ? (int) $r['total'] : 0;
		};

		// M21 (BR-M21-7): low_stock and needs_reorder are computed together
		// from one shared candidate scan -- see scan_low_stock_and_needs_reorder().
		$low_and_reorder = self::scan_low_stock_and_needs_reorder( $base_params );

		return array(
			'total'         => $count( array() ),
			'in_stock'      => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
				)
			),
			'out_of_stock'  => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK ),
				)
			),
			'on_backorder'  => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER ),
				)
			),
			'low_stock'     => $low_and_reorder['low_stock'],
			'needs_reorder' => $low_and_reorder['needs_reorder'],
			'draft'         => $count( array( 'post_status' => array( 'draft' ) ) ),
			'hidden'        => $count( array( 'visibility' => \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN ) ),
		);
	}

	/**
	 * Count simple + variation lines that manage stock, are in stock, and at/below
	 * low-stock threshold (low_stock, unchanged value/population from pre-M21 --
	 * BR-M21-7), together with how many of those are also Position-classified
	 * needs_reorder (M21, BR-M21-2). One shared pagination scan; the bulk
	 * Position lookup for the accumulated candidates happens exactly once,
	 * after the scan completes, never inside the loop or once per candidate
	 * (INV-M21-4, BR-M21-12).
	 *
	 * M24 (WP-M24-3, BR-M24-17): the scan itself is now the shared
	 * gather_low_stock_candidates() helper (no item_ids, catalog-wide) --
	 * output/query-count byte-identical to the pre-M24 inline implementation
	 * (characterization-proven). low_stock counts every qualifying candidate
	 * ENCOUNTERED (gather order), matching the pre-M24 unconditional
	 * per-candidate increment exactly -- not a deduplicated id count (the two
	 * only differ if the same id is somehow encountered twice across pages,
	 * which never happens under normal pagination, but this preserves the
	 * original counting semantics exactly regardless).
	 *
	 * @param array<string, mixed> $base_params Base filters.
	 * @return array{low_stock: int, needs_reorder: int}
	 */
	protected static function scan_low_stock_and_needs_reorder( array $base_params ) {
		$gathered  = self::gather_low_stock_candidates( $base_params );
		$low_total = count( $gathered['order'] );

		$needs_reorder = 0;
		foreach ( self::classify_needs_reorder_bulk( $gathered['product_candidates'], $gathered['variation_candidates'] ) as $classification ) {
			if ( ! empty( $classification['needs_reorder'] ) ) {
				++$needs_reorder;
			}
		}

		return array(
			'low_stock'     => $low_total,
			'needs_reorder' => $needs_reorder,
		);
	}

	/**
	 * M24 (WP-M24-3, §9.4/§9.5): shared low-stock/managing-stock/in-stock
	 * candidate gather, extracted from the pre-M24 body of
	 * scan_low_stock_and_needs_reorder() so both that method (catalog-wide,
	 * no item_ids, unchanged 40-page/200-per-page ceiling) and the new
	 * get_needs_reorder_items() (catalog-wide OR item_ids-scoped) share one
	 * gather implementation (INV-M24-11: Summary remains the sole
	 * full-catalog/scoped low-stock/needs-reorder scanner).
	 *
	 * Scoped path (non-empty $item_ids, BR-M24-2/BR-M24-22): one bounded
	 * `include`-based Repository::query_products() call (Design A, proven
	 * sufficient by test-repository-include-variation-proof.php, WP-M24-1 --
	 * no per-ID fallback loop, §9.4). An id that no longer qualifies (wrong
	 * type, stock unmanaged, out of stock, no threshold, above threshold) or
	 * no longer exists is simply absent from the returned candidate set --
	 * silently dropped, not surfaced as an error (BR-M24-3).
	 *
	 * Catalog-wide path (empty $item_ids): unchanged inherited 40-page loop.
	 *
	 * Captures name/sku/parent_id directly from the already-loaded
	 * WC_Product during this single pass (§9.3/§9.4) -- no second
	 * wc_get_product()/PO_Product_Validator::validate() call anywhere
	 * downstream needs to re-fetch identity.
	 *
	 * @param array<string, mixed> $base_params Base filters (search, category, stock_status, exclude_private).
	 * @param int[]                $item_ids    Scoped item (product/variation) ids, or empty for catalog-wide.
	 * @return array{
	 *   product_candidates: array<int, array{on_hand:float, threshold:float}>,
	 *   variation_candidates: array<int, array{on_hand:float, threshold:float}>,
	 *   meta: array<int, array{name:string, sku:string, parent_id:int, is_variation:bool, on_hand:float, threshold:float}>,
	 *   order: array<int>,
	 * }
	 */
	private static function gather_low_stock_candidates( array $base_params, array $item_ids = array() ) {
		$product_candidates   = array();
		$variation_candidates = array();
		$meta                 = array();
		$order                = array();

		$consume = static function ( array $products ) use ( &$product_candidates, &$variation_candidates, &$meta, &$order ) {
			foreach ( $products as $p ) {
				if ( ! $p instanceof WC_Product || ! $p->managing_stock() || ! $p->is_in_stock() ) {
					continue;
				}
				$qty = $p->get_stock_quantity();
				if ( null === $qty || '' === $qty ) {
					continue;
				}
				$low = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $p );
				if ( null === $low || (float) $qty > (float) $low ) {
					continue;
				}

				$id           = (int) $p->get_id();
				$is_variation = $p->is_type( 'variation' );

				$candidate = array(
					'on_hand'   => (float) $qty,
					'threshold' => (float) $low,
				);
				if ( $is_variation ) {
					$variation_candidates[ $id ] = $candidate;
				} else {
					$product_candidates[ $id ] = $candidate;
				}

				$meta[ $id ] = array(
					'name'         => (string) $p->get_name(),
					'sku'          => (string) $p->get_sku(),
					'parent_id'    => $is_variation ? (int) $p->get_parent_id() : 0,
					'is_variation' => $is_variation,
					'on_hand'      => (float) $qty,
					'threshold'    => (float) $low,
				);
				$order[] = $id;
			}
		};

		if ( ! empty( $item_ids ) ) {
			$params = array_merge(
				$base_params,
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
					'include'                   => array_map( 'absint', $item_ids ),
				)
			);
			$r = WC_Inventory_Overview_Repository::query_products( $params );
			$consume( isset( $r['products'] ) && is_array( $r['products'] ) ? $r['products'] : array() );
		} else {
			$page     = 1;
			$per_page = 200;
			$max_page = 40;

			$params = array_merge(
				$base_params,
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
					'per_page'                  => $per_page,
				)
			);

			while ( $page <= $max_page ) {
				$params['paged'] = $page;
				$r               = WC_Inventory_Overview_Repository::query_products( $params );
				if ( empty( $r['products'] ) ) {
					break;
				}
				$consume( $r['products'] );
				if ( $page >= ( $r['max_num_pages'] ?? 1 ) ) {
					break;
				}
				++$page;
			}
		}

		return array(
			'product_candidates'   => $product_candidates,
			'variation_candidates' => $variation_candidates,
			'meta'                 => $meta,
			'order'                => $order,
		);
	}

	/**
	 * M24 (WP-M24-3, §9.5/§21): itemized sibling of
	 * scan_low_stock_and_needs_reorder(). Same gather + classify pipeline
	 * (gather_low_stock_candidates(), then one classify_needs_reorder_bulk()
	 * call over the FULL gathered set -- never stopped early, so the
	 * catalog-wide inherited ~8,000-candidate ceiling is unaffected by
	 * $limit). $limit, when > 0, truncates the CLASSIFIED needs-reorder
	 * result to its first $limit members in the SAME order the candidates
	 * were gathered (Repository::query_products()'s own ordering) -- never
	 * re-sorted before truncation, since sorting the full up-to-8000-item
	 * set first would defeat the resolution-cost bound this truncation
	 * exists to provide (§9.5). $limit = 0 means unbounded (the item_ids-
	 * scoped path only ever passes 0, since that input is already bounded to
	 * <=100 externally by the bulk-action cap, BR-M24-21).
	 *
	 * Captures name/sku/parent_id from the already-loaded WC_Product during
	 * the gather pass (no re-fetch, §9.3) -- callers must never re-run
	 * PO_Product_Validator::validate() or a second wc_get_product() for an
	 * item already present in this result (INV-M24-12).
	 *
	 * @param array<string, mixed> $base_params Base filters, same shape as scan_low_stock_and_needs_reorder().
	 * @param int[]                $item_ids    Scoped item ids (BR-M24-2), or empty for catalog-wide (BR-M24-1).
	 * @param int                  $limit       Max items to return (0 = unbounded).
	 * @return array{
	 *   items: array<int, array{product_id:int, variation_id:int, name:string, sku:string, on_hand:float, threshold:float, position:float}>,
	 *   truncated: bool,
	 * }
	 */
	public static function get_needs_reorder_items( array $base_params, array $item_ids = array(), int $limit = 0 ) {
		$gathered   = self::gather_low_stock_candidates( $base_params, $item_ids );
		$classified = self::classify_needs_reorder_bulk( $gathered['product_candidates'], $gathered['variation_candidates'] );

		$items = array();
		foreach ( $gathered['order'] as $id ) {
			$c = $classified[ $id ] ?? null;
			if ( null === $c || empty( $c['needs_reorder'] ) ) {
				continue;
			}
			$meta      = $gathered['meta'][ $id ];
			$items[] = array(
				'product_id'   => $meta['is_variation'] ? $meta['parent_id'] : $id,
				'variation_id' => $meta['is_variation'] ? $id : 0,
				'name'         => $meta['name'],
				'sku'          => $meta['sku'],
				'on_hand'      => $meta['on_hand'],
				'threshold'    => $meta['threshold'],
				'position'     => (float) $c['position'],
			);
		}

		$truncated = false;
		if ( $limit > 0 && count( $items ) > $limit ) {
			$truncated = true;
			$items     = array_slice( $items, 0, $limit );
		}

		return array(
			'items'     => $items,
			'truncated' => $truncated,
		);
	}

	/**
	 * Sole caller of WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk()
	 * on this class's behalf (INV-M21-2, D12 discipline extended by analogy):
	 * every WC_Inventory_Overview_Summary method that needs Reorder Signal
	 * classification goes through this one method, exactly one bulk Position
	 * call per invocation, regardless of candidate count.
	 *
	 * @param array<int, array{on_hand: float, threshold: float}> $product_candidates   Product ID => on-hand/threshold.
	 * @param array<int, array{on_hand: float, threshold: float}> $variation_candidates Variation ID => on-hand/threshold.
	 * @return array<int, array{position: float, needs_reorder: bool, covered_by_incoming: bool}>
	 */
	protected static function classify_needs_reorder_bulk( array $product_candidates, array $variation_candidates ) {
		$product_on_hand = array();
		foreach ( $product_candidates as $id => $candidate ) {
			$product_on_hand[ $id ] = $candidate['on_hand'];
		}

		$variation_on_hand = array();
		foreach ( $variation_candidates as $id => $candidate ) {
			$variation_on_hand[ $id ] = $candidate['on_hand'];
		}

		$positions = WC_Inventory_Overview_Inventory_Position_Service::get_positions_bulk( $product_on_hand, $variation_on_hand );

		$result = array();
		foreach ( $product_candidates + $variation_candidates as $id => $candidate ) {
			if ( ! isset( $positions[ $id ] ) ) {
				continue;
			}
			$position = (float) $positions[ $id ]['position'];
			$signal   = WC_Inventory_Overview_Reorder_Signal_Resolver::resolve( $position, (float) $candidate['threshold'] );

			$result[ $id ] = array(
				'position'            => $position,
				'needs_reorder'       => $signal['needs_reorder'],
				'covered_by_incoming' => $signal['covered_by_incoming'],
			);
		}

		return $result;
	}

	/**
	 * Low-stock sellable lines (same rules as low-stock count), for dashboard chart.
	 *
	 * M21 (BR-M21-8): each returned row is additively extended with
	 * incoming/position/needs_reorder/covered_by_incoming -- the pre-M21
	 * id/name/qty/low keys, their values, and the qty-then-name sort order
	 * are all unchanged. Classification runs once, in one bulk call, only
	 * over the rows actually being returned (a strict subset of this
	 * method's already-existing bounded candidate pool) -- never once per
	 * row (INV-M21-4).
	 *
	 * @param array<string, mixed> $base_params Base filters (search, category, stock_status, exclude_private).
	 * @param int                    $limit       Max rows.
	 * @return array<int, array{id:int,name:string,qty:float,low:float,incoming:float,position:float,needs_reorder:bool,covered_by_incoming:bool}>
	 */
	public static function get_low_stock_lines_for_chart( array $base_params, $limit = 10 ) {
		$limit = max( 1, min( 50, (int) $limit ) );
		$rows  = array();

		$page     = 1;
		$per_page = 200;
		$max_page = 40;

		$params = array_merge(
			$base_params,
			array(
				'sellable_stock_lines_only' => true,
				'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
				'per_page'                  => $per_page,
			)
		);

		$product_candidates   = array();
		$variation_candidates = array();

		while ( $page <= $max_page && count( $rows ) < $limit * 5 ) {
			$params['paged'] = $page;
			$r               = WC_Inventory_Overview_Repository::query_products( $params );
			if ( empty( $r['products'] ) ) {
				break;
			}
			foreach ( $r['products'] as $p ) {
				if ( ! $p instanceof WC_Product || ! $p->managing_stock() || ! $p->is_in_stock() ) {
					continue;
				}
				$qty = $p->get_stock_quantity();
				if ( null === $qty || '' === $qty ) {
					continue;
				}
				$low = WC_Inventory_Overview_Settings::get_effective_low_stock_amount( $p );
				if ( null === $low || (float) $qty > (float) $low ) {
					continue;
				}
				$id     = (int) $p->get_id();
				$rows[] = array(
					'id'   => $id,
					'name' => wp_strip_all_tags( $p->get_formatted_name() ),
					'qty'  => (float) wc_stock_amount( $qty ),
					'low'  => (float) $low,
				);

				$candidate = array(
					'on_hand'   => (float) $qty,
					'threshold' => (float) $low,
				);
				if ( $p->is_type( 'variation' ) ) {
					$variation_candidates[ $id ] = $candidate;
				} else {
					$product_candidates[ $id ] = $candidate;
				}
			}
			if ( $page >= ( $r['max_num_pages'] ?? 1 ) ) {
				break;
			}
			++$page;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['qty'] === $b['qty'] ) {
					return strcmp( (string) $a['name'], (string) $b['name'] );
				}
				return $a['qty'] <=> $b['qty'];
			}
		);

		$rows = array_slice( $rows, 0, $limit );

		$returned_product_candidates   = array();
		$returned_variation_candidates = array();
		foreach ( $rows as $row ) {
			if ( isset( $product_candidates[ $row['id'] ] ) ) {
				$returned_product_candidates[ $row['id'] ] = $product_candidates[ $row['id'] ];
			} elseif ( isset( $variation_candidates[ $row['id'] ] ) ) {
				$returned_variation_candidates[ $row['id'] ] = $variation_candidates[ $row['id'] ];
			}
		}
		$classified = self::classify_needs_reorder_bulk( $returned_product_candidates, $returned_variation_candidates );

		foreach ( $rows as &$row ) {
			$c                          = $classified[ $row['id'] ] ?? null;
			$row['position']            = null !== $c ? $c['position'] : $row['qty'];
			$row['incoming']            = null !== $c ? max( 0.0, $row['position'] - $row['qty'] ) : 0.0;
			$row['needs_reorder']       = null !== $c ? $c['needs_reorder'] : true;
			$row['covered_by_incoming'] = null !== $c ? $c['covered_by_incoming'] : false;
		}
		unset( $row );

		return $rows;
	}
}
