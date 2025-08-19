<?php
/**
 * WP REST Endpoint builder.
 *
 * File Path: src/php/Base/Library/REST.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Library;

use BunnifyFrontend\Base\Traits\EndpointTrait;

/**
 * Helper class for registering WordPress REST API endpoints.
 * Example:
 * $this->rest->endpoint(
 *     [
 *         'namespace' => $config->app( 'name' ),
 *         'action'    => 'edit/(?P<id>\d+)',
 *         'method'    => \WP_REST_Server::READABLE,
 *         'callback'  => [ $this, 'edit_thing' ],
 *     ]
 * );
 */
class REST {
	use EndpointTrait;

	/**
	 * Singleton instance.
	 *
	 * @var REST|null
	 */
	private static $instance = null;

	/**
	 * All defined REST endpoints.
	 *
	 * @var array
	 */
	protected $endpoints = [];

	/**
	 * Current REST request.
	 *
	 * @var \WP_REST_Request|null
	 */
	protected $current_request = null;

	/**
	 * Get the singleton instance of REST.
	 *
	 * @return REST
	 */
	public static function get_instance(): REST {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_endpoints' ] );
	}

	/**
	 * Register a new REST endpoint.
	 *
	 * @param array $args Arguments for registering the endpoint:
	 *                    'namespace'           => Namespace for the endpoint.
	 *                    'version'             => Version of the REST API (default = 'v1').
	 *                    'action'              => REST action/route.
	 *                    'method'              => HTTP method (default = 'GET').
	 *                    'callback'            => Callback function for the endpoint.
	 *                    'permission_callback' => Permissions callback.
	 *                    'args'                => Additional arguments for the endpoint.
	 *
	 * @return void
	 */
	public function endpoint( array $args ) {
		$default_args = [
			'namespace'           => '',
			'version'             => 'v1',
			'action'              => '',
			'method'              => 'GET',
			'callback'            => null,
			'permission_callback' => '__return_true',
			'args'                => [],
		];

		$args = array_merge( $default_args, $args );

		$route                     = $this->build_route( $args );
		$this->endpoints[ $route ] = $args;
	}

	/**
	 * Registers all configured REST endpoints with WordPress.
	 *
	 * @return void
	 */
	public function register_endpoints() {
		foreach ( $this->endpoints as $endpoint ) {
			register_rest_route(
				"{$endpoint['namespace']}/{$endpoint['version']}",
				$endpoint['action'],
				[
					'methods'             => $endpoint['method'],
					'callback'            => [ $this, 'handle_callback' ],
					'permission_callback' => [ $this, 'handle_perm_callback' ],
					'args'                => $endpoint['args'],
				]
			);
		}
	}

	/**
	 * Handles the permissions callback for an endpoint.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return bool True if the user is allowed to access the endpoint, false otherwise.
	 */
	public function handle_perm_callback( \WP_REST_Request $request ) {
		$this->current_request = $request;
		$this->set_current_endpoint( $request );

		$callback = $this->current_endpoint['permission_callback'] ?? '__return_true';

		return call_user_func( $callback, $request );
	}

	/**
	 * Handles the main callback for an endpoint.
	 *
	 * @param \WP_REST_Request $request The current request.
	 *
	 * @return mixed
	 */
	public function handle_callback( \WP_REST_Request $request ) {
		$this->current_request = $request;
		$this->set_current_endpoint( $request );

		$callback = $this->current_endpoint['callback'] ?? null;

		if ( $callback ) {
			return call_user_func( $callback, $request );
		}

		return null;
	}

	/**
	 * Builds the route for the REST API endpoint.
	 *
	 * @param array $endpoint Endpoint configuration.
	 *
	 * @return string The REST API route.
	 */
	protected function build_route( array $endpoint ) {
		return "/{$endpoint['namespace']}/{$endpoint['version']}/{$endpoint['action']}";
	}

	/**
	 * Sets the current endpoint based on the request route.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return void
	 */
	protected function set_current_endpoint( \WP_REST_Request $request ) {
		$route = $request->get_route();

		foreach ( $this->endpoints as $endpoint ) {
			$expected_route = $this->build_route( $endpoint );

			if ( preg_match( "@^{$expected_route}$@i", $route ) ) {
				$this->current_endpoint = $endpoint;
				break;
			}
		}
	}
}
