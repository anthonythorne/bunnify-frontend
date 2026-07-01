<?php
/**
 * Provides the AdminAjax helper class.
 *
 * File path: src/php/Base/Library/AdminAjax.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Library;

use BunnifyFrontend\Base\Traits\EndpointTrait;

/**
 * Helper class for registering WordPress admin ajax endpoints.
 * Example usage:
 * $ajax = AdminAjax::get_instance();
 * $ajax->endpoint( 'my_ajax_action', [ $this, 'my_callback' ] );
 */
class AdminAjax {
	use EndpointTrait;

	/**
	 * Singleton instance.
	 *
	 * @var AdminAjax|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance of AdminAjax.
	 *
	 * @return AdminAjax
	 */
	public static function get_instance(): AdminAjax {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a new AJAX endpoint.
	 *
	 * @param string   $action   The ajax action name.
	 * @param callable $callback The callback function for the ajax hook.
	 *
	 * @return void
	 */
	public function endpoint( $action, $callback ) {
		add_action( "wp_ajax_{$action}", $callback );
		add_action( "wp_ajax_nopriv_{$action}", $callback );
	}

	/**
	 * Retrieve a parameter from the AJAX request.
	 *
	 * @param string $param         Parameter to retrieve.
	 * @param mixed  $default_value Default value if parameter is not found (default = '').
	 *
	 * @return mixed|string The value for the parameter or the default.
	 */
	public static function get_param( $param, $default_value = '' ) {
		return $_REQUEST[ $param ] ?? $default_value; // phpcs:ignore
	}
}
