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
	 * @param array<string, mixed> $base_params Base filters.
	 * @return array{low_stock: int, needs_reorder: int}
	 */
	protected static function scan_low_stock_and_needs_reorder( array $base_params ) {
		$page     = 1;
		$per_page = 200;
		$max_page = 40;
		$low_total = 0;

		$product_candidates   = array();
		$variation_candidates = array();

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

				++$low_total;

				$candidate = array(
					'on_hand'   => (float) $qty,
					'threshold' => (float) $low,
				);
				if ( $p->is_type( 'variation' ) ) {
					$variation_candidates[ $p->get_id() ] = $candidate;
				} else {
					$product_candidates[ $p->get_id() ] = $candidate;
				}
			}
			if ( $page >= ( $r['max_num_pages'] ?? 1 ) ) {
				break;
			}
			++$page;
		}

		$needs_reorder = 0;
		foreach ( self::classify_needs_reorder_bulk( $product_candidates, $variation_candidates ) as $classification ) {
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
	 * @param array<string, mixed> $base_params Base filters (search, category, stock_status, exclude_private).
	 * @param int                    $limit       Max rows.
	 * @return array<int, array{id:int,name:string,qty:float,low:float}>
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
				$rows[] = array(
					'id'   => (int) $p->get_id(),
					'name' => wp_strip_all_tags( $p->get_formatted_name() ),
					'qty'  => (float) wc_stock_amount( $qty ),
					'low'  => (float) $low,
				);
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

		return array_slice( $rows, 0, $limit );
	}
}
