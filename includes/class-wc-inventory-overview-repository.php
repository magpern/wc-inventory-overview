<?php
/**
 * Product query layer: paginated wc_get_products with category/search SQL hooks.
 *
 * @package WC_Inventory_Overview
 */

defined( 'ABSPATH' ) || exit;

/**
 * Repository for inventory list queries.
 */
class WC_Inventory_Overview_Repository {

	/**
	 * Request-scoped query modifiers (single admin request).
	 *
	 * @var array{category_tt_id?:int,search?:string,exclude_private?:bool}
	 */
	private static $context = array();

	/**
	 * Depth counter for nested WC product queries.
	 *
	 * @var int
	 */
	private static $query_depth = 0;

	/**
	 * @var bool
	 */
	private static $hooks_registered = false;

	/**
	 * Whether to omit private products (and variations of private parents). Default: yes.
	 */
	public static function request_excludes_private() {
		return ! ( isset( $_REQUEST['wc_io_exclude_private'] ) && '0' === (string) wp_unslash( $_REQUEST['wc_io_exclude_private'] ) );
	}

	/**
	 * Run a paginated product query.
	 *
	 * @param array<string, mixed> $params Parameters from the list table.
	 * @return array{products: WC_Product[], total: int, max_num_pages: int}
	 */
	public static function query_products( array $params ) {
		self::register_hooks();

		$per_page = isset( $params['per_page'] ) ? max( 1, (int) $params['per_page'] ) : 20;
		$page     = isset( $params['paged'] ) ? max( 1, (int) $params['paged'] ) : 1;

		$exclude_private = ! empty( $params['exclude_private'] );

		self::$context = array(
			'category_tt_id'  => isset( $params['category_tt_id'] ) ? (int) $params['category_tt_id'] : 0,
			'search'          => isset( $params['search'] ) ? sanitize_text_field( (string) $params['search'] ) : '',
			'exclude_private' => $exclude_private,
		);

		++self::$query_depth;

		$statuses = isset( $params['post_status'] ) && is_array( $params['post_status'] )
			? array_map( 'sanitize_key', $params['post_status'] )
			: array( 'publish', 'draft', 'pending', 'private', 'future' );

		if ( $exclude_private ) {
			$statuses = array_values( array_diff( $statuses, array( 'private' ) ) );
		}

		$parent_id = isset( $params['parent'] ) ? (int) $params['parent'] : 0;
		$children  = $parent_id > 0;

		$paginate = ! $children;
		if ( isset( $params['paginate'] ) ) {
			$paginate = (bool) $params['paginate'];
		}

		$args = array(
			'status'   => $statuses,
			'return'   => 'objects',
			'paginate' => $paginate,
		);

		if ( $children ) {
			$args['parent']   = $parent_id;
			$args['type']     = \Automattic\WooCommerce\Enums\ProductType::VARIATION;
			$args['limit']    = isset( $params['limit'] ) ? min( 500, max( 1, (int) $params['limit'] ) ) : 500;
			$args['orderby']  = isset( $params['orderby'] ) ? (string) $params['orderby'] : 'menu_order';
			$args['order']    = isset( $params['order'] ) && 'DESC' === strtoupper( (string) $params['order'] ) ? 'DESC' : 'ASC';
		} else {
			if ( ! empty( $params['sellable_stock_lines_only'] ) ) {
				$args['type'] = array(
					\Automattic\WooCommerce\Enums\ProductType::SIMPLE,
					\Automattic\WooCommerce\Enums\ProductType::VARIATION,
				);
			} else {
				$args['type'] = array(
					\Automattic\WooCommerce\Enums\ProductType::SIMPLE,
					\Automattic\WooCommerce\Enums\ProductType::GROUPED,
					\Automattic\WooCommerce\Enums\ProductType::EXTERNAL,
					\Automattic\WooCommerce\Enums\ProductType::VARIABLE,
					\Automattic\WooCommerce\Enums\ProductType::VARIATION,
				);
			}
			$args['limit']    = $per_page;
			$args['page']     = $page;
			$args['orderby']  = isset( $params['orderby'] ) ? (string) $params['orderby'] : 'modified';
			$args['order']    = isset( $params['order'] ) && 'ASC' === strtoupper( (string) $params['order'] ) ? 'ASC' : 'DESC';
		}

		// M24 (§9.2): additive bounded-include passthrough. When present,
		// overrides pagination entirely with a single bounded result set
		// sized exactly to the include list -- no page math, no per-ID
		// fallback. Does not alter the 'type'/'status' filtering already
		// established above, so callers that also pass
		// sellable_stock_lines_only=>true (M24's own discovery path) still
		// get the simple+variation type scoping, just resolved to a fixed
		// id list instead of a page.
		if ( ! empty( $params['include'] ) && is_array( $params['include'] ) ) {
			$args['include']  = array_map( 'absint', $params['include'] );
			$args['limit']    = count( $args['include'] );
			$args['paginate'] = false;
			unset( $args['page'] );
		}

		if ( ! empty( $params['stock_status'] ) && is_array( $params['stock_status'] ) ) {
			$args['stock_status'] = array_map( 'sanitize_key', $params['stock_status'] );
		}

		if ( ! empty( $params['sku'] ) ) {
			$args['sku'] = wc_clean( wp_unslash( (string) $params['sku'] ) );
		}

		if ( ! empty( $params['meta_key'] ) ) {
			$args['meta_key'] = sanitize_key( (string) $params['meta_key'] );
			if ( ! empty( $params['orderby_meta'] ) ) {
				$args['orderby'] = 'meta_value_num' === $params['orderby_meta'] ? 'meta_value_num' : 'meta_value';
			}
		}

		if ( ! empty( $params['visibility'] ) ) {
			$args['visibility'] = sanitize_text_field( (string) $params['visibility'] );
		}

		// Text/SKU search is handled in posts_clauses (avoids AND-only WP search vs SKU OR).
		if ( self::$context['search'] !== '' ) {
			$args['s'] = '';
		}

		try {
			$result = wc_get_products( $args );
		} finally {
			--self::$query_depth;
			if ( self::$query_depth <= 0 ) {
				self::$query_depth = 0;
				self::$context     = array();
			}
		}

		if ( is_object( $result ) && isset( $result->products, $result->total ) ) {
			return array(
				'products'      => is_array( $result->products ) ? $result->products : array(),
				'total'         => (int) $result->total,
				'max_num_pages' => isset( $result->max_num_pages ) ? (int) $result->max_num_pages : 1,
			);
		}

		if ( is_array( $result ) ) {
			return array(
				'products'      => $result,
				'total'         => count( $result ),
				'max_num_pages' => 1,
			);
		}

		return array(
			'products'      => array(),
			'total'         => 0,
			'max_num_pages' => 1,
		);
	}

	/**
	 * Variations for a variable parent, respecting list filters (category, search, stock, private).
	 *
	 * @param int                  $parent_id Parent product ID.
	 * @param array<string, mixed> $shared    Shared list params (exclude pagination keys).
	 * @return WC_Product[]
	 */
	public static function query_variations_for_parent( $parent_id, array $shared ) {
		$parent_id = (int) $parent_id;
		if ( $parent_id <= 0 ) {
			return array();
		}

		$params = array_merge(
			$shared,
			array(
				'parent' => $parent_id,
			)
		);

		$r = self::query_products( $params );

		return isset( $r['products'] ) && is_array( $r['products'] ) ? $r['products'] : array();
	}

	/**
	 * Export all products matching filters (chunked pages).
	 *
	 * @param array<string, mixed> $params Same shape as query_products without pagination keys.
	 * @param int                  $chunk  Page size.
	 * @return \Generator<int, WC_Product>
	 */
	public static function iterate_products_for_export( array $params, $chunk = 200 ) {
		$page = 1;
		while ( true ) {
			$params['paged']     = $page;
			$params['per_page']  = $chunk;
			$batch               = self::query_products( $params );
			if ( empty( $batch['products'] ) ) {
				break;
			}
			foreach ( $batch['products'] as $product ) {
				yield $product;
			}
			if ( $page >= $batch['max_num_pages'] ) {
				break;
			}
			++$page;
		}
	}

	/**
	 * Register SQL filters once.
	 */
	private static function register_hooks() {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		add_filter( 'posts_clauses', array( __CLASS__, 'filter_posts_clauses' ), 999, 2 );
	}

	/**
	 * Category (incl. variations via parent) and SKU-aware search.
	 *
	 * @param string[] $clauses Query clauses.
	 * @param \WP_Query $query  Query.
	 * @return string[]
	 */
	public static function filter_posts_clauses( $clauses, $query ) {
		if ( self::$query_depth < 1 || ! is_admin() ) {
			return $clauses;
		}

		if ( ! $query instanceof WP_Query ) {
			return $clauses;
		}

		$post_type = $query->get( 'post_type' );
		if ( ! is_array( $post_type ) ) {
			$post_type = array( $post_type );
		}
		$post_type = array_filter( array_map( 'strval', $post_type ) );
		if ( ! in_array( 'product', $post_type, true ) && ! in_array( 'product_variation', $post_type, true ) ) {
			return $clauses;
		}

		$tt_id          = isset( self::$context['category_tt_id'] ) ? (int) self::$context['category_tt_id'] : 0;
		$search         = isset( self::$context['search'] ) ? self::$context['search'] : '';
		$exclude_private = ! empty( self::$context['exclude_private'] );

		if ( $tt_id <= 0 && '' === $search && ! $exclude_private ) {
			return $clauses;
		}

		global $wpdb;

		if ( $search !== '' && false === strpos( $clauses['join'], 'wc_product_meta_lookup' ) ) {
			$clauses['join'] .= " LEFT JOIN {$wpdb->wc_product_meta_lookup} wc_product_meta_lookup ON ( {$wpdb->posts}.ID = wc_product_meta_lookup.product_id ) ";
		}

		$extra_where = array();

		if ( $tt_id > 0 ) {
			$extra_where[] = $wpdb->prepare(
				" ( ( {$wpdb->posts}.post_type = 'product' AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr_io_cat WHERE tr_io_cat.object_id = {$wpdb->posts}.ID AND tr_io_cat.term_taxonomy_id = %d ) ) OR ( {$wpdb->posts}.post_type = 'product_variation' AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr_io_cat2 INNER JOIN {$wpdb->posts} parent_io ON tr_io_cat2.object_id = parent_io.ID WHERE parent_io.ID = {$wpdb->posts}.post_parent AND parent_io.post_type = 'product' AND tr_io_cat2.term_taxonomy_id = %d ) ) ) ",
				$tt_id,
				$tt_id
			);
		}

		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$extra_where[] = $wpdb->prepare(
				" ( {$wpdb->posts}.post_title LIKE %s OR {$wpdb->posts}.post_excerpt LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR ( wc_product_meta_lookup.sku LIKE %s ) OR ( wc_product_meta_lookup.global_unique_id LIKE %s ) OR ( {$wpdb->posts}.post_type = 'product_variation' AND {$wpdb->posts}.post_parent > 0 AND EXISTS ( SELECT 1 FROM {$wpdb->wc_product_meta_lookup} pl_parent WHERE pl_parent.product_id = {$wpdb->posts}.post_parent AND ( pl_parent.sku LIKE %s OR pl_parent.global_unique_id LIKE %s ) AND ( wc_product_meta_lookup.sku = '' OR wc_product_meta_lookup.sku IS NULL ) ) ) ) ",
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$like
			);
		}

		if ( $exclude_private ) {
			$extra_where[] = " NOT ( {$wpdb->posts}.post_type = 'product_variation' AND EXISTS ( SELECT 1 FROM {$wpdb->posts} wc_io_par_priv WHERE wc_io_par_priv.ID = {$wpdb->posts}.post_parent AND wc_io_par_priv.post_type = 'product' AND wc_io_par_priv.post_status = 'private' ) ) ";
		}

		if ( $extra_where ) {
			$clauses['where'] .= ' AND ( ' . implode( ' AND ', $extra_where ) . ' ) ';
		}

		return $clauses;
	}
}
