<?php
/**
 * Simple fragment cache using WordPress transients.
 *
 * File Path: src/php/Library/FragmentCache.php
 *
 * @package BunnifyFrontend
 */

namespace BunnifyFrontend\Base\Library;

/**
 * Build a cache key from the wp query args provided.
 * This ensures that no matter how an array of query args is delivered that it's sorted into the same order if it
 * has the same values in any provided order.
 *
 * see FragmentCacheKeyWPQueryArgs.md for more information.
 *
 * IMPORTANT We know that tax_query, meta_query and date_query cannot be flattened with ease because they
 * can be in any order especially if it's an indexed or combination array. Each of these cases need to be
 * handled differently based in its keys and values.
 *
 * Example:
 *
 * [
 *      'tax_query' => [
 *          'relation' => 'AND',
 *          [
 *              'taxonomy' => 'movie_genre',
 *              'field'    => 'slug',
 *              'terms'    => array( 'comedy', 'action', 'ten' ),
 *          ],
 *          [
 *              'taxonomy' => 'actor',
 *              'field'    => 'term_id',
 *              'terms'    => array( 206, 103, 115 ),
 *              'operator' => 'NOT IN',
 *          ],
 *      ],
 * ]
 *
 * IS THE SAME AS. so they should have the same key created.
 *
 * [
 *      'tax_query' => [
 *          'relation' => 'AND',
 *          [
 *              'taxonomy' => 'actor',
 *              'field'    => 'term_id',
 *              'terms'    => array( 206, 103, 115 ),
 *              'operator' => 'NOT IN',
 *          ],
 *          [
 *              'taxonomy' => 'movie_genre',
 *              'field'    => 'slug',
 *              'terms'    => array( 'comedy', 'action', 'ten' ),
 *          ],
 *      ],
 * ]
 *
 * This is done by ordering sorting all sections except tax_query, meta_query and date_query,
 * which is handled by individual methods.
 */
class FragmentCacheKeyWPQueryArgs {

	/**
	 *
	 * @param array  $query_args Args used for WP_Query.
	 * @param string $extra_key  A extra key if needed, for example on single.php, a index for each post.
	 *
	 * @return string
	 */
	public static function build_frag_cache_key_from_wp_query_args( $query_args, $extra_key = null ) {

		// Completed key to return.
		$cache_key = '';

		// Bail early.
		if ( ! is_array( $query_args ) ) {
			return $cache_key;
		}

		$tax_query  = $query_args['tax_query'] ?? [];
		$meta_query = $query_args['meta_query'] ?? [];
		$date_query = $query_args['date_query'] ?? [];

		// Individually sort tax_query.
		if ( $tax_query ) {
			$tax_query = self::sort_tax_or_meta_query_component( $tax_query, 'taxonomy' );
			unset( $query_args['tax_query'] );
		}

		// Individually sort tax_query.
		if ( $meta_query ) {
			$meta_query = self::sort_tax_or_meta_query_component( $meta_query, 'key' );
			unset( $query_args['meta_query'] );
		}

		// Individually sort tax_query.
		if ( $date_query ) {
			$date_query = self::sort_date_query_component( $date_query );
			unset( $query_args['date_query'] );
		}

		// Sort all remaining pieces of the query.
		$query_args = array_sort_by_keys_then_values( $query_args );

		// Add back tax_query.
		if ( $tax_query ) {
			$query_args['tax_query'] = $tax_query;
		}

		// Add back meta_query.
		if ( $meta_query ) {
			$query_args['meta_query'] = $tax_query;
		}

		// Add back date_query.
		if ( $date_query ) {
			$query_args['date_query'] = $date_query;
		}

		$flattened = flatten_multi_array( $query_args, '__', 10 );

		return $extra_key . $flattened;
	}

	/**
	 * Sort taxonomy or meta query array into specific order.
	 *
	 * @param array  $tax_or_meta_query The array being sorted.
	 * @param string $column_sort       The column used for sorting, typicaly 'taxonomy' for tax_query
	 *                                  or for 'key' for meta_query.
	 *
	 * @return array|mixed
	 */
	protected static function sort_tax_or_meta_query_component( $tax_or_meta_query = [], $column_sort = '' ) {

		if ( ! $tax_or_meta_query ) {
			return $tax_or_meta_query;
		}

		$relation = false;

		if ( isset( $tax_or_meta_query['relation'] ) ) {
			$relation = $tax_or_meta_query['relation'];

			// Unset leaving with index array.
			unset( $tax_or_meta_query['relation'] );
		}

		// Only sort if required. If sorting, sort by taxonomy.
		if ( count( $tax_or_meta_query ) > 1 ) {

			$sub_tax_or_meta_queries = [];

			// Multidimensional tax_query. Very rare, however we must handle this separately,
			// remove and sort, then add back after.
			foreach ( $tax_or_meta_query as $index => $item ) {

				if ( isset( $item['relation'] ) && $item['relation'] ) {
					$sub_tax_or_meta_queries[] = self::sort_tax_or_meta_query_component( $item, $column_sort );
					unset( $tax_or_meta_query[ $index ] );
				}
			}

			// Sort this level by taxonomy. Do not sort the sub level, it gets sorted in recursive call.
			$columns = array_column( $tax_or_meta_query, $column_sort );
			array_multisort( $columns, SORT_ASC, $tax_or_meta_query );

			$tax_or_meta_query = array_sort_by_keys_then_values( $tax_or_meta_query );

			// Append the sub queries.
			if ( $sub_tax_or_meta_queries ) {
				$tax_or_meta_query = array_merge( $tax_or_meta_query, $sub_tax_or_meta_queries );
			}
		}

		// Add back the relation to the array.
		if ( $relation ) {
			$tax_or_meta_query['relation'] = $relation;
		}

		return $tax_or_meta_query;
	}

	/**
	 * Sort date query array into specific order.
	 *
	 * @param array $date_query The date query component.
	 *
	 * @return array
	 */
	protected static function sort_date_query_component( $date_query = [] ) {

		if ( ! $date_query ) {
			return $date_query;
		}

		$inclusive = false;
		$compare   = false;
		$column    = false;
		$relation  = false;

		// Grab and unset each of the following, we add back in specific order at the end.
		if ( isset( $date_query['inclusive'] ) ) {
			$inclusive = $date_query['inclusive'];
			unset( $date_query['inclusive'] );
		}
		if ( isset( $date_query['compare'] ) ) {
			$compare = $date_query['compare'];
			unset( $date_query['compare'] );
		}
		if ( isset( $date_query['column'] ) ) {
			$column = $date_query['column'];
			unset( $date_query['column'] );
		}
		if ( isset( $date_query['relation'] ) ) {
			$relation = $date_query['relation'];
			unset( $date_query['relation'] );
		}

		// Only sort if the array holds more than one date_query.
		if ( count( $date_query ) > 1 ) {

			usort(
				$date_query,
				function ( $date_query_a, $date_query_b ): int {

					$outcome         = 0;
					$multiply_weight = 1;

					/**
					 * Sorting dates by reverse order of the code below.
					 * It uses a multiple weight to allow each item in order to carry more weight than the previous item.
					 *
					 * This sorting mechanism ignores
					 * inclusive
					 * compare
					 * column
					 * relation
					 * after (array only)
					 * before (array only)
					 *
					 * Note that
					 * 'after' and 'before' can be in the form of an array or strtotime.
					 * We do not sort by those details at this stage.
					 *
					 * See: https://developer.wordpress.org/reference/classes/wp_query/#date-parameters
					 */
					if ( isset( $date_query_b['before'] ) && is_string( $date_query_b['before'] ) &&
					     isset( $date_query_a['before'] ) && is_string( $date_query_a['before'] ) ) {
						$outcome += ( $date_query_b['before'] <=> $date_query_a['before'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['after'] ) && is_string( $date_query_b['after'] ) &&
					     isset( $date_query_a['after'] ) && is_string( $date_query_a['after'] ) ) {
						$outcome += ( $date_query_b['after'] <=> $date_query_a['after'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['second'] ) && isset( $date_query_a['second'] ) ) {
						$outcome += ( $date_query_b['second'] <=> $date_query_a['second'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['minute'] ) && isset( $date_query_a['minute'] ) ) {
						$outcome += ( $date_query_b['minute'] <=> $date_query_a['minute'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['hour'] ) && isset( $date_query_a['hour'] ) ) {
						$outcome += ( $date_query_b['hour'] <=> $date_query_a['hour'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['day'] ) && isset( $date_query_a['day'] ) ) {
						$outcome += ( $date_query_b['day'] <=> $date_query_a['day'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['month'] ) && isset( $date_query_a['month'] ) ) {
						$outcome += ( $date_query_b['month'] <=> $date_query_a['month'] ) * $multiply_weight;
					}
					$multiply_weight *= 10;

					if ( isset( $date_query_b['year'] ) && isset( $date_query_a['year'] ) ) {
						$outcome += ( $date_query_b['year'] <=> $date_query_a['year'] ) * $multiply_weight;
					}

					return $outcome;
				}
			);

			$date_query = array_sort_by_keys_then_values( $date_query );
		}

		// Add back all items removed.
		if ( $inclusive ) {
			$date_query['inclusive'] = $inclusive;
		}
		if ( $compare ) {
			$date_query['compare'] = $compare;
		}
		if ( $column ) {
			$date_query['column'] = $column;
		}
		if ( $relation ) {
			$date_query['relation'] = $relation;
		}

		return $date_query;
	}

	/**
	 * Given the tax_query array from a WP query sort it by taxonomy and taxonomy term ids/slug. Return as a string.
	 *
	 * IMPORTANT: We do not know that the array is always provided in the same order, thus we manualy itterate over
	 * each piece, rather than flattening the array into a string.
	 *
	 * Show relation first then alphabetically order taxonomies by slug, with tax terms ordered by id.
	 *
	 * @param array $query_args The query args to check for a tax query used by WP_Query.
	 *
	 * @return string
	 */
	protected static function build_tax_query_cache_key_component( $query_args = [] ) {


		if ( isset( $query_args['tax_query'] ) ) {
			return '';
		}

		$tax_query_component = '';

		if ( isset( $query_args['tax_query'] ) ) {

			if ( ! empty( $tax_query ) && is_array( $tax_query ) ) {
				$tax_query_to_sort = [];

				/**
				 * Sort the tax query into two levels, first the taxonomy, second all tax terms for that taxonomy.
				 */
				foreach ( $tax_query as $key => $value ) {
					// Add relation at the begging of the tax query component.
					if ( 'relation' === $key ) {
						$tax_query_component .= 'tax_query__' . $key . '_' . $value . '__';
					} else {
						// Sorting component.
						if ( ! empty( $value['taxonomy'] ) && ! empty( $value['field'] ) ) {

							if ( ! empty( $value['terms'] ) && ( is_string( $value['terms'] ) || is_int( $value['terms'] ) ) ) {
								// If is string or int.
								$taxonomy_term = get_term_by( $value['field'], $value['terms'], $value['taxonomy'] );

								if ( $taxonomy_term ) {
									$tax_query_to_sort[ $value['taxonomy'] ][ $taxonomy_term->term_id ] = $taxonomy_term->slug;
								}
							} elseif ( ! empty( $value['terms'] ) && ( is_array( $value['terms'] ) ) ) {
								// If is an array of terms.
								foreach ( $value['terms'] as $term_id ) {
									$taxonomy_term = get_term_by( $value['field'], $term_id, $value['taxonomy'] );
									if ( $taxonomy_term ) {
										$tax_query_to_sort[ $value['taxonomy'] ][ $taxonomy_term->term_id ] = $taxonomy_term->slug;
									}
								}
							}
						}
					}
				}

				// If the sorted tax query has been built, add it to the tax query component for the cache key.
				if ( ! empty( $tax_query_to_sort ) && is_array( $tax_query_to_sort ) ) {

					// Even though its a associative array, only need first level sorted as second level uses term ids.
					$sorted = ksort( $tax_query_to_sort );
					if ( $sorted ) {
						// The array has two levels, first is the taxonomy, second is each term in that taxonomy.
						foreach ( $tax_query_to_sort as $taxonomy => $tax_terms ) {
							if ( ! empty( $tax_terms ) && is_array( $tax_terms ) ) {
								$tax_query_component .= $taxonomy . '_';
								foreach ( $tax_terms as $term_id => $term_slug ) {
									$tax_query_component .= $term_slug . '_';
								}
							}
							$tax_query_component = trim( $tax_query_component, '_-' ) . '__';
						}
					}
				}
			}
		}

		return $tax_query_component;
	}

	/**
	 * Cater for all other args that are not post type and tax query
	 *
	 * @param array $query Array of args used in WP_Query.
	 *
	 * @return string
	 */
	protected static function build_args_cache_key_component( $query ) {

		$arg_query_component = '';

		if ( ! empty( $query ) && is_array( $query ) ) {

			$args_to_sort = [];

			// Build out all args ready to sort.
			foreach ( $query as $arg => $value ) {
				if ( 'post_type' !== $arg && 'tax_query' !== $arg ) {
					if ( ! empty( $value ) ) {
						// Flatten array function by default goes 3 levels.
						$args_to_sort[ $arg ] = self::flatten_multi_array( $value );
					}
				}
			}

			// If the sorted tax query has been built, add it to the tax query component for the cache key.
			if ( ! empty( $args_to_sort ) && is_array( $args_to_sort ) ) {

				// Even though its a associative array, only need first level sorted as second level uses term ids.
				$sorted = ksort( $args_to_sort );

				if ( $sorted ) {
					// The array has two levels, first is the taxonomy, second is each term in that taxonomy.
					foreach ( $args_to_sort as $arg => $value ) {
						$arg_query_component .= $arg . '_' . $value . '__';
					}
				}
			}
		}

		return $arg_query_component;
	}

	/**
	 * Flatten a multidimensional array with possibilities of keys.
	 *
	 * @param array|string $value     Value being passed in.
	 * @param string       $glue      What to join each piece together with.
	 * @param int          $max_limit Default is 3 dimensions.
	 *
	 * @return string|mixed
	 */
	protected static function flatten_multi_array( $value, $glue = '_', $max_limit = 3 ) {
		$return = '';

		if ( 0 !== $max_limit ) {
			if ( ! empty( $value ) && is_array( $value ) ) {
				foreach ( $value as $key => $sub_value ) {
					$return .= $key . $glue . self::flatten_multi_array( $sub_value, $glue, ( $max_limit - 1 ) );
				}
			} else {
				$return = $value;
			}
		}

		return $return;
	}

}
