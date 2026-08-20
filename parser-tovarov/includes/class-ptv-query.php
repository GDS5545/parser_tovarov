<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * All product lookups for the catalog table go through this class so the
 * heavy parts (which product IDs belong to which categories, which
 * attribute terms are actually in use) are cached and reused between the
 * initial page render and every subsequent AJAX filter/page request.
 */
class PTV_Query {

	/**
	 * Run a paginated, filtered product query.
	 *
	 * @param array $args {
	 *   @type string[] $categories   Product category slugs (OR'ed together).
	 *   @type array    $attributes   [ taxonomy => string[] term slugs ] (AND between taxonomies, OR within).
	 *   @type string   $search       Free-text search across title/SKU.
	 *   @type string   $orderby      title|price|date|attribute:pa_xxx
	 *   @type string   $order        ASC|DESC
	 *   @type int      $page
	 *   @type int      $per_page
	 * }
	 * @return array{products: WC_Product[], total: int, max_pages: int, page: int}
	 */
	public static function query( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'categories' => array(),
				'attributes' => array(),
				'search'     => '',
				'orderby'    => 'title',
				'order'      => 'ASC',
				'page'       => 1,
				'per_page'   => 20,
			)
		);

		$query_args = array(
			'status'   => 'publish',
			'limit'    => max( 1, min( 100, (int) $args['per_page'] ) ),
			'page'     => max( 1, (int) $args['page'] ),
			'paginate' => true,
			'return'   => 'objects',
		);

		if ( ! empty( $args['categories'] ) ) {
			$query_args['category'] = array_map( 'sanitize_title', $args['categories'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		$tax_query = array();
		if ( ! empty( $args['attributes'] ) && is_array( $args['attributes'] ) ) {
			foreach ( $args['attributes'] as $taxonomy => $terms ) {
				$terms = array_filter( array_map( 'sanitize_title', (array) $terms ) );
				if ( empty( $terms ) || ! taxonomy_exists( $taxonomy ) ) {
					continue;
				}
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'slug',
					'terms'    => $terms,
					'operator' => 'IN',
				);
			}
		}
		if ( ! empty( $tax_query ) ) {
			if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
			}
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$sort_taxonomy = self::apply_orderby( $query_args, $args['orderby'], $args['order'] );

		// Simple products keep their attribute values as taxonomy terms
		// (there is no 'attribute_pa_xxx' postmeta to sort by, that only
		// exists on variations), so ordering by an attribute column needs a
		// manual JOIN + ORDER BY on the term name, scoped to this one query.
		if ( $sort_taxonomy ) {
			$sort_callback = self::make_attribute_sort_clause( $sort_taxonomy, $query_args['order'] );
			add_filter( 'posts_clauses', $sort_callback, 10, 1 );
		}

		$results = wc_get_products( $query_args );

		if ( $sort_taxonomy ) {
			remove_filter( 'posts_clauses', $sort_callback, 10 );
		}

		if ( is_a( $results, 'WC_Product_Query' ) || ! is_object( $results ) ) {
			return array(
				'products'  => array(),
				'total'     => 0,
				'max_pages' => 0,
				'page'      => (int) $args['page'],
			);
		}

		return array(
			'products'  => $results->products,
			'total'     => (int) $results->total,
			'max_pages' => (int) $results->max_num_pages,
			'page'      => max( 1, (int) $args['page'] ),
		);
	}

	/**
	 * @return string|null The attribute taxonomy to sort by, if any; sets
	 *                      $query_args['orderby']/['order'] either way.
	 */
	private static function apply_orderby( &$query_args, $orderby, $order ) {
		$order = ( 'DESC' === strtoupper( $order ) ) ? 'DESC' : 'ASC';
		$query_args['order'] = $order;

		if ( 0 === strpos( (string) $orderby, 'attribute:' ) ) {
			$taxonomy = substr( $orderby, strlen( 'attribute:' ) );
			if ( taxonomy_exists( $taxonomy ) ) {
				// Actual ORDER BY is added via the posts_clauses filter in
				// make_attribute_sort_clause(); 'orderby' here is left as
				// the WP_Query default so it doesn't fight with our clause.
				$query_args['orderby'] = 'menu_order';
				return $taxonomy;
			}
		}

		$map = array(
			'title' => 'title',
			'price' => 'price',
			'date'  => 'date',
			'sku'   => 'sku',
		);

		$query_args['orderby'] = isset( $map[ $orderby ] ) ? $map[ $orderby ] : 'title';
		return null;
	}

	/**
	 * Builds a posts_clauses callback that LEFT JOINs the given attribute
	 * taxonomy and orders by its term name, grouping so a product with
	 * several terms in that taxonomy still yields one row.
	 */
	private static function make_attribute_sort_clause( $taxonomy, $order ) {
		return static function ( $clauses ) use ( $taxonomy, $order ) {
			global $wpdb;

			$clauses['join'] .= $wpdb->prepare(
				" LEFT JOIN {$wpdb->term_relationships} AS ptv_sort_tr ON ( {$wpdb->posts}.ID = ptv_sort_tr.object_id )
				  LEFT JOIN {$wpdb->term_taxonomy} AS ptv_sort_tt ON ( ptv_sort_tr.term_taxonomy_id = ptv_sort_tt.term_taxonomy_id AND ptv_sort_tt.taxonomy = %s )
				  LEFT JOIN {$wpdb->terms} AS ptv_sort_t ON ( ptv_sort_tt.term_id = ptv_sort_t.term_id )",
				$taxonomy
			);

			$clauses['orderby'] = 'ptv_sort_t.name ' . $order . ', ' . $wpdb->posts . '.post_title ASC';
			$clauses['groupby'] = $wpdb->posts . '.ID';

			return $clauses;
		};
	}

	/**
	 * Product IDs (published, in stock or not) that belong to any of the
	 * given category slugs. Used only to build the filter-term lists below,
	 * capped to keep the query cheap on very large catalogs.
	 */
	public static function get_category_product_ids( array $categories ) {
		$cache = PTV_Cache::instance();
		$key   = 'cat_ids_' . implode( ',', $categories );

		$cached = $cache->get( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 5000,
			'no_found_rows'  => true,
		);

		if ( ! empty( $categories ) ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => array_map( 'sanitize_title', $categories ),
				),
			);
		}

		$ids = get_posts( $query_args );

		$cache->set( $key, $ids, 60 );

		return $ids;
	}

	/**
	 * Attribute terms actually used by products in the given categories,
	 * each with a usage count, so the filter dropdowns and quick-filter
	 * chips only ever show options that return results.
	 *
	 * @return array<int, array{slug:string, name:string, count:int}>
	 */
	public static function get_attribute_terms( array $categories, $taxonomy ) {
		global $wpdb;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$cache = PTV_Cache::instance();
		$key   = 'terms_' . $taxonomy . '_' . implode( ',', $categories );

		$cached = $cache->get( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$product_ids = self::get_category_product_ids( $categories );
		if ( empty( $product_ids ) ) {
			$cache->set( $key, array(), 60 );
			return array();
		}

		$ids_placeholder = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sql = "
			SELECT t.slug, t.name, COUNT( DISTINCT tr.object_id ) AS cnt
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tt.taxonomy = %s
			AND tr.object_id IN ({$ids_placeholder})
			GROUP BY t.term_id
			ORDER BY t.name ASC
		";

		$params = array_merge( array( $taxonomy ), $product_ids );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$terms = array();
		foreach ( (array) $rows as $row ) {
			$terms[] = array(
				'slug'  => $row->slug,
				'name'  => $row->name,
				'count' => (int) $row->cnt,
			);
		}

		usort(
			$terms,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		$cache->set( $key, $terms, PTV_Settings::instance()->get( 'cache_minutes' ) );

		return $terms;
	}

	/**
	 * Top N terms of an attribute by product count, used for the quick
	 * filter chips row above the table.
	 */
	public static function get_quick_filter_terms( array $categories, $taxonomy, $limit = 14 ) {
		$terms = self::get_attribute_terms( $categories, $taxonomy );

		usort(
			$terms,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return array_slice( $terms, 0, $limit );
	}
}
