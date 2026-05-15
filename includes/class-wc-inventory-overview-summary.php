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

		return array(
			'total'        => $count( array() ),
			'in_stock'     => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::IN_STOCK ),
				)
			),
			'out_of_stock' => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::OUT_OF_STOCK ),
				)
			),
			'on_backorder' => $count(
				array(
					'sellable_stock_lines_only' => true,
					'stock_status'              => array( \Automattic\WooCommerce\Enums\ProductStockStatus::ON_BACKORDER ),
				)
			),
			'low_stock'    => self::count_low_sellable_lines( $base_params ),
			'draft'        => $count( array( 'post_status' => array( 'draft' ) ) ),
			'hidden'       => $count( array( 'visibility' => \Automattic\WooCommerce\Enums\CatalogVisibility::HIDDEN ) ),
		);
	}

	/**
	 * Count simple + variation lines that manage stock, are in stock, and at/below low-stock threshold.
	 *
	 * @param array<string, mixed> $base_params Base filters.
	 * @return int
	 */
	protected static function count_low_sellable_lines( array $base_params ) {
		$page     = 1;
		$per_page = 200;
		$max_page = 40;
		$total    = 0;

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
				if ( null !== $low && (float) $qty <= (float) $low ) {
					++$total;
				}
			}
			if ( $page >= ( $r['max_num_pages'] ?? 1 ) ) {
				break;
			}
			++$page;
		}

		return $total;
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
