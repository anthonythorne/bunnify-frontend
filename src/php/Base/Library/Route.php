<?php
/**
 * Custom route builder.
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Library;

use BunnifyFrontend\Base\Traits\EndpointTrait;

/**
 * Utility to build custom routes within WordPress.
 * Example:
 * $this->route->add( [
 *      'regex'    => '^myroute/([0-9]+)/?$',
 *      'callback' => [ $this, 'my_route_callback' ]
 * ] );
 */
class Route {
	use EndpointTrait;

	/**
	 * Singleton instance.
	 *
	 * @var Route|null
	 */
	private static $instance = null;

	/**
	 * All registered routes.
	 *
	 * @var array
	 */
	protected $routes = [];

	/**
	 * Get the singleton instance of Route.
	 *
	 * @return Route
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_filter( 'do_parse_request', [ $this, 'handle_routes' ], 1, 3 );
	}

	/**
	 * Registers a new route.
	 *
	 * @param array $args Route arguments:
	 *                    'regex'    => Regular expression to match the route.
	 *                    'callback' => Callback function for when the route is triggered.
	 *
	 * @return void
	 */
	public function add( array $args ) {
		$default_args = [
			'regex'    => '',
			'callback' => [],
			'title'    => '',
		];

		$args = array_merge( $default_args, $args );

		if ( ! empty( $args['regex'] ) && ! empty( $args['callback'] ) ) {
			$this->routes[] = $args;
		}
	}

	/**
	 * Handles the custom routes on 'do_parse_request'.
	 *
	 * @param boolean $continue         Whether to continue processing the request.
	 * @param \WP     $wp               Current WordPress environment instance.
	 * @param mixed   $extra_query_vars Extra query variables passed.
	 *
	 * @return boolean Whether to continue processing the request.
	 */
	public function handle_routes( $continue, $wp, $extra_query_vars ) {
		$request_path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' ); // phpcs:ignore

		// Adjust for site subdirectory installations.
		$site_url_parts = wp_parse_url( get_site_url() );
		$site_url_path  = trim( $site_url_parts['path'] ?? '/', '/' );
		$request_path   = preg_replace( '{^/?' . $site_url_path . '/?}', '', $request_path );

		// Check registered routes.
		foreach ( $this->routes as $route ) {
			if ( preg_match( "{^{$route['regex']}$}", $request_path, $matches ) ) {
				// Initialize admin bar if logged in.
				if ( is_user_logged_in() ) {
					_wp_admin_bar_init();
				}

				$this->set_newrelic_transaction( $route );

				// Remove the full match and pass the remaining matches to the callback.
				array_shift( $matches );
				call_user_func_array( $route['callback'], $matches );
				exit;
			}
		}

		// No matching route, continue with WordPress processing.
		return $continue;
	}

	/**
	 * Set the New Relic transaction name for better tracking.
	 *
	 * @param array $route Route configuration.
	 *
	 * @return void
	 */
	protected function set_newrelic_transaction( array $route ) {
		if ( ! extension_loaded( 'newrelic' ) || ! function_exists( 'newrelic_name_transaction' ) ) {
			return;
		}

		$callback = $route['callback'];
		$transaction_name = is_array( $callback )
			? str_replace( '\\', '/', get_class( $callback[0] ) ) . '//' . $callback[1]
			: $callback;

		newrelic_name_transaction( $transaction_name );
	}
}
