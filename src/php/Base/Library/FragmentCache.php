<?php
/**
 * Fragment Cache
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Library;

/**
 * 
 * Simple fragment cache using WordPress transients.
 *
 * -------- CACHE KEY LENGTH -------- 
 * 
 * Cache Length Key Generation:
 * 
 * Has to be an acceptable length for WordPress transients.
 * 172 characters Transient name.
 * Expected to not be SQL-escaped.
 * Must be 172 characters or fewer in length.
 *
 * String Part: "_transient_" Max 11 chacracters. WordPress Prepend to the standard key.
 * String Part: "_transient_timeout_" Max 19 characters long. WordPress Prepend "_transient_timeout_" to the cache TTL.
 *
 * String Part: ":frag_cache:" Max 12 characters long. Allows us to identify custom transients.
 *
 * String Part: "http:" or "https:" is Max 6 characters long.
 *
 * String Part: "{$language_code}:" Max 7 charaacters long. e.g. "global:" is 7, vs "unset:", "en-us:" is 6 etc.
 *
 * String Part: "{group}:" This is the remainder of max key length.
 *
 * String Part: "{$md5_key}" md5( $key . self::SALT ) is 32 characters long 
 * 
 * Calculation: 172 - ( 19 + 12 + 6 + 7 + 32 ) = self::GROUP_KEY_MAX_LENGTH = 96 characters including the trailing "_", lets round down to 90.
 *
 * -------- Example Usage for DATA -------- 
 * 
 * $cache_key   = 'somestring';
 * $cache_group = 'navigation_header'
 * $cache_ttl   = HOUR_IN_SECONDS;
 * 
 * $frag_cache = new \BunnifyFrontend\Base\Library\FragmentCache( $cache_key, $cache_group, $cache_ttl );
 * 
 * $data = $frag_cache->get_transient();
 * 
 * if ( false === $data ) {
 *     ...build data...
 *     $frag_cache->set_transient( $value )
 * }
 * 
 * return $data;
 * 
 * -------- Example Usage for OUTPUT -------- 
 * 
 * $cache_key   = 'somestring';
 * $cache_group = 'navigation_header'
 * $cache_ttl   = HOUR_IN_SECONDS;
 * 
 * $frag_cache = new \BunnifyFrontend\Base\Library\FragmentCache( $cache_key, $cache_group, $cache_ttl );
 * 
 * // Bail early, output is rendered.
 * if ( $frag_cache->output() ) {
 *     return;
 * }
 * 
 * ...render html...
 * 
 * $frag_cache->store();
 * 
 * -------- Example Usage for OUTPUT as a variable --------
 *
 * This is usefull for html and you want the debug comments wrapped around the html.
 * i.e., for shortcodes or places where HTML is returnd as a value.
 *
 * $cache_key   = "childpage_nav_{$parent_id}"; // Shortcode name.
 * $cache_group = 'legacy_shortcodes';
 * $cache_ttl   = 6 * HOUR_IN_SECONDS; // 1/4 of a day.
 *
 * $frag_cache = new \BunnifyFrontend\Base\Library\FragmentCache( $cache_key, $cache_group, $cache_ttl );
 * 
 * $output = $frag_cache->get_cached_output();
 * // HTML exists, render and bail early.
 * if ( $output ) :
 *     return $output;
 * endif;
 * 
 * ...render html...
 *
 * return $frag_cache->save_and_get_cached_output();
 * 
 * -------- Example fix for duplicate store of html --------
 * Set this in your wp-config file to ONLY when you need to empty the cache on get. 
 * When the page refreshes it will store a fresh cache.
 *
 * This is usefule if somehow durring development you end up with duplicate html being stored in the transient.
 * This occurs from outputing, rendering and storing both output and rendered html.
 *
 * define( 'FRAGMENT_CACHE_BYPASS', 'true );
 */
class FragmentCache {

	/**
	 * Max length of the group key.
	 *
	 * @var int
	 */
	const GROUP_KEY_MAX_LENGTH = 90;

	/**
	 * Cache key seperator.
	 *
	 * @var string
	 */
	const CACHE_KEY_SEPERATOR = ':';

	/**
	 * Cache key seperator.
	 *
	 * @var string
	 */
	const CACHE_KEY_IDENTIFIER = 'frag_cache';

	/**
	 * Salt for the cache key, so no one can build the cache key from the frontend comments.
	 *
	 * @var string
	 */
	const SALT = 'cache-fragments';

	/**
	 * Cache key.
	 *
	 * @var string
	 */
	protected string $key;

	/**
	 * Cache group, limited to 111 characters in length.
	 *
	 * @var string
	 */
	protected string $group;

	/**
	 * Time to loss.
	 *
	 * @var int
	 */
	protected int $ttl = 0;

	/**
	 * Autoload.
	 *
	 * @var bool
	 */
	protected bool $autoload = false;

	/**
	 * Language code.
	 *
	 * @var string
	 */
	protected string $language_code = 'unset';

	/**
	 * Setup the cache data.
	 *
	 * @param string $key                    Cache key. No limit, is wrapped in md5 hash.
	 * @param string $group                  Optional. Cache group, limited to 123 characters in length, anything over is wrapped in md5 hash.
	 * @param int    $ttl                    Optional. Default 0. Time to loss, how long the item will remain cached.
	 * @param bool   $cache_by_language_code Optional. Default is true. Cache by language code.
	 * @param bool   $autoload               Optional. Default is false. Autoload the cache is set to 'no' regardless if TTL is set to 0.
	 */
	public function __construct( string $key, string $group = '', ?int $ttl = null, bool $cache_by_language_code = true, bool $autoload = false ) {

		$this->key      = $key;
		$this->group    = $group ? $group : 'unknown';
		$this->autoload = $autoload;

		// Get the laguage code regardless of WPML mbeing activated.
		if ( $cache_by_language_code ) {

			/**
			 * Check for potential custom language code filtering before WPML language code.
			 *
			 * @var null|string $language_code The language code to use for the cache key.
			 */
			$language_code = apply_filters( 'fragment_cache_wpml_icl_language_code', null ) ?? apply_filters( 'wpml_current_language', null );

			// Set the found language code.
			if ( $language_code ) {
				$this->language_code = $language_code;
			}
		}

		/**
		 * Filter the TTL for fragment cache.
		 *
		 * @var null|int $ttl How long the item will remain cached.
		 * @var string   $key Cache key.
		 * @var string   $group Cache group.
		 * @var string   $language_code Language code.
		 *
		 * @return null|int
		 */
		$ttl = apply_filters( 'fragment_cache_default_ttl', $ttl, $this->key, $this->group, $this->language_code );

		if ( ! is_numeric( $ttl ) ) {
			$ttl = 0; // Default to 0.
		}

		$this->ttl = $ttl;
	}

	/**
	 * Expands on the WordPress set transient function to use the generated key from this class.
	 *
	 * @param mixed $value Fragment cached value to store in cache.
	 */
	public function set_transient( mixed $value ) {
		set_transient( $this->key(), $value, $this->ttl );

		// Set the transient to auto load 'no'.
		if ( ! $this->autoload ) {
			$this->set_transient_autoload_no();
		}
	}

	/**
	 * Sets the autoload for the transient option to 'no' if it is stored in the options table.
	 */
	private function set_transient_autoload_no() {

		global $wpdb;

		// Prepare the option names for transient and its timeout as stored in WP options.
		$option_name  = '_transient_' . $this->key();
		$timeout_name = '_transient_timeout_' . $this->key();

		// SQL to update the autoload field for both the transient and its timeout.
		$sql = $wpdb->prepare(
			"UPDATE {$wpdb->options}
         SET autoload = 'no'
         WHERE option_name IN (%s, %s)",
			$option_name,
			$timeout_name,
		);

		// Execute the update query.
		$wpdb->query( $sql ); // phpcs:ignore
	}

	/**
	 * Expands on the WordPress get transient function to use the generated key from this class.
	 *
	 * @return mixed Value of transient.
	 */
	public function get_transient(): mixed {

		/**
		 * Set this in your wp-config file to ONLY when you need to invalidate all caches by not
		 * returning the cached data.
		 *
		 * When the page refreshes it will store a fresh cache.
		 *
		 * This is useful if somehow durring development you end up with duplicate html being stored in the transient.
		 * This occurs from outputing, rendering and storing both output and rendered html.
		 */
		if ( defined( 'FRAGMENT_CACHE_BYPASS' ) && FRAGMENT_CACHE_BYPASS ) {
			// Invalidate the cache by not returning the cached data.
			return false;
		}

		// Check if a specific query variable is set to clear the cache and if the current user is an administrator.
		if ( isset( $_GET['cache-clear'] ) && current_user_can( 'manage_options' ) ) { // phpcs:ignore
			// Invalidate the cache by not returning the cached data.
			return false;
		}

		return get_transient( $this->key() );
	}

	/**
	 * Output the cache content, if it is available.
	 *
	 * @return boolean
	 */
	public function output() {

		$did_output = false;

		$output = $this->get_transient();

		if ( ! empty( $output ) ) { // It was in the cache.

			echo $this->comments_pre() . $output . $this->comments_post(); // phpcs:ignore

			$did_output = true;

		} else {

			// phpcs:disable

			echo $this->comments_pre( 'initial' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			// Buffer the output from now onwards until store() is called.
			ob_start();
		}

		return $did_output;
	}

	/**
	 * Store/cache the output.
	 */
	public function store() {

		// Get the buffered output to be cached.
		$output = ob_get_flush(); // Also flushes the buffers.
		$this->set_transient( $output );

		// Output the cached output.
		echo $this->comments_post( 'initial' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Retrieves the cached output with appended and prepended HTML comments.
	 *
	 * @return string The cached content wrapped in HTML comments if available, otherwise an empty string.
	 */
	public function get_cached_output() {

		$output = $this->get_transient();

		// When there's no cache available, return an empty string.
		if ( false === $output ) {
			// Start output buffering to capture the output for return, when we close the buffer in save_and_get_cached_output.
			ob_start();
			return '';
		}

		ob_start(); // Start output buffering.
		echo $this->comments_pre() . $output . $this->comments_post(); // phpcs:ignore
		return ob_get_clean(); // Get the contents of the buffer and end buffering.
	}
	/**
	 * Saves the newly generated output to the cache and returns it with appended and prepended HTML comments.
	 *
	 * @param bool $render_comments Whether to render HTML comments around the content.
	 *
	 * @return string The content wrapped in HTML comments.
	 */
	public function save_and_get_cached_output( $render_comments = true ) {

		$output = ob_get_clean(); // Get the contents of the buffer and end buffering.

		// Save the new value to the cache
		$this->set_transient( $output );

		if ( $render_comments ) {
			$output = $this->comments_pre( 'initial' ) . $output . $this->comments_post( 'initial' ); // phpcs:ignore
		}

		return $output;
	}

	/**
	 * Returns the HTML comments to be prepended to the cached output.
	 *
	 * @return string
	 */
	public function comments_pre( $type = 'cached' ) {

		return sprintf(
			'<!-- fragment (%s output) (language_code=%s, group=%s, key=%s, ttl=%ss, full_key=%s) -->',
			esc_attr( $type ),
			esc_attr( $this->language_code ),
			esc_attr( $this->group ),
			esc_attr( $this->key ),
			esc_attr( $this->ttl ),
			esc_attr( $this->key() ),
		);
	}

	/**
	 * Returns the HTML comments to be appended to the cached output.
	 *
	 * @return string
	 */
	public function comments_post( $type = 'cached' ) {

		return sprintf(
			'<!-- /fragment (%s output) (language_code=%s, group=%s, key=%s, ttl=%ss, full_key=%s) -->',
			esc_attr( $type ),
			esc_attr( $this->language_code ),
			esc_attr( $this->group ),
			esc_attr( $this->key ),
			esc_attr( $this->ttl ),
			esc_attr( $this->key() ),
		);
	}

	/**
	 * Returns the encoded cache key.
	 *
	 * @return string
	 */
	public function key() {

		$key           = $this->key;
		$group         = $this->group;
		$language_code = $this->language_code;

		/**
		 * Key generation, see class comments for more details.
		 *
		 * Start with the cache key prefix.
		 */
		$full_key = self::get_cache_key_prefix();

		// Make unique if is HTTPS v HTTP if we are on a secure connection.
		if ( ( isset( $_SERVER['HTTPS'] ) && ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] ) ||
			( isset( $_SERVER['SERVER_PORT'] ) && 443 === $_SERVER['SERVER_PORT'] ) ) {
			$full_key .= 'https' . self::CACHE_KEY_SEPERATOR;
		} else {
			$full_key .= 'http' . self::CACHE_KEY_SEPERATOR;
		}

		if ( $language_code ) {
			$full_key .= $language_code . self::CACHE_KEY_SEPERATOR;
		}

		if ( $group ) {

			if ( strlen( $group ) > self::GROUP_KEY_MAX_LENGTH ) {
				$group = md5( $group );
			}

			$full_key .= $group . self::CACHE_KEY_SEPERATOR;
		}

		$md5_key = md5( $key . self::SALT );

		return $full_key . $md5_key;
	}

	/**
	 * Generates a cache key from query arguments.
	 * 
	 * @param array|null $args Query arguments.
	 * @return string Cache key.
	 */
	public static function generate_cache_key_from_query_args( ?array $args ) {
		// Bail early if not an array.
		if ( ! is_array( $args ) ) {
			return '';
		}

		// Remove elements that shouldn't affect cache key uniqueness.
		unset(
			$args['cache_results'],
			$args['fields'],
			$args['lazy_load_term_meta'],
			$args['update_post_meta_cache'],
			$args['update_post_term_cache'],
			$args['update_menu_item_cache'],
			$args['suppress_filters']
		);

		// Normalize and sort query args for consistent ordering.
		$normalized_query = self::normalize_and_sort_args( $args );

		// Serialize the normalized array to ensure consistent length and format.
		$serialized_query = serialize( $normalized_query );

		return 'args' . self::CACHE_KEY_SEPERATOR . $serialized_query;
	}

	/**
	 * Recursively normalizes and sorts an array by keys.
	 *
	 * @param array $args The array to normalize and sort.
	 * @return array The normalized and sorted array.
	 */
	protected static function normalize_and_sort_args( array $args ) {
		// Sort the array by key first
		ksort( $args );

		// Iterate over each element to sort sub-arrays recursively
		foreach ( $args as $key => &$value ) {
			if ( is_array( $value ) ) {
				// Sort sub-arrays recursively
				$value = self::normalize_and_sort_args( $value );
			}
			if ( is_object( $value ) ) {
				// Convert object to array and normalize
				$value = self::normalize_and_sort_args( (array) $value );
			}
		}

		// Sort sub-arrays by values if they are associative and represent conditions or queries
		if ( self::is_assoc( $args ) ) {
			usort( $args, function ($a, $b) {
				return strcmp( serialize( $a ), serialize( $b ) );
			} );
		}

		return $args;
	}

	/**
	 * Checks if an array is associative (i.e., has string keys).
	 *
	 * @param array $arr The array to check.
	 * @return bool True if associative, false otherwise.
	 */
	protected static function is_assoc( array $arr ) {
		if ( [] === $arr )
			return false;
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Retrieves the prefix used for cache keys in the fragment cache.
	 *
	 * This prefix includes the separator and the cache identifier, and it is used
	 * as the starting segment of any cache key generated within this class.
	 *
	 * @return string The cache key prefix.
	 */
	public static function get_cache_key_prefix() {
		return self::CACHE_KEY_SEPERATOR . self::CACHE_KEY_IDENTIFIER . self::CACHE_KEY_SEPERATOR;
	}

	/**
     * Purge cache entries related to a specific cache group.
     *
     * This method deletes all transient data and expiration entries related
     * to a specific cache group from the WordPress options table.
     *
     * @param string $cache_group The cache group to purge.
	 *
     * @return void
     */
    public static function purge_cache_group( string $cache_group ) {

		// Bail early if object cache is enabled. There are no transients to purge.
		if ( wp_using_ext_object_cache() ) {
			return;
		}

        global $wpdb;

        // Get the cache key prefix from the current class.
        $cache_prefix = self::get_cache_key_prefix();

        // Prepare SQL which will delete both transient data and expiration entries (_transient_ and _transient_timeout_).
        $sql = $wpdb->prepare(
            "DELETE FROM {$wpdb->options}
            WHERE option_name LIKE %s
            AND option_name LIKE %s
            AND option_name LIKE %s",
            '_transient_%',
            '%' . $wpdb->esc_like( $cache_prefix ) . '%',
            '%' . $wpdb->esc_like( $cache_group ) . '%'
        );

        // Execute the SQL query to remove the transients from the options table.
        $wpdb->query( $sql ); // phpcs:ignore
    }
}
