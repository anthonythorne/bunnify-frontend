<?php
/**
 * Framework application manager.
 *
 * File Path: src/php/Base/Main/Application.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Main;

use BunnifyFrontend\Base\Library\Config;
use BunnifyFrontend\Base\Library\Route;
use BunnifyFrontend\Base\Library\REST;
use BunnifyFrontend\Base\Library\AdminAjax;
use BunnifyFrontend\Base\Traits\RouteTrait;
use BunnifyFrontend\Base\Traits\RESTTrait;
use BunnifyFrontend\Base\Traits\AdminAjaxTrait;

/**
 * An application.
 * This is the core utility which manages an application using this framework.
 */
class Application {

	/**
	 * The slug/name of the application.
	 *
	 * @var string
	 */
	protected $name = '';

	/**
	 * The root directory of the application.
	 *
	 * @var string
	 */
	protected $directory = '';

	/**
	 * The root directory URI of the application.
	 *
	 * @var string
	 */
	protected $directory_uri = '';

	/**
	 * All of the controllers defined in the application.
	 *
	 * @var array
	 */
	protected $controllers = [];

	/**
	 * The config helper instance.
	 *
	 * @var \BunnifyFrontend\Base\Library\Config
	 */
	protected $config = null;

	/**
	 * Initialized services to prevent multiple initializations.
	 *
	 * @var array
	 */
	protected $services = [
		'route'      => null,
		'rest'       => null,
		'admin_ajax' => null,
	];

	/**
	 * Constructor.
	 *
	 * @param string $name        The name/slug of the application.
	 * @param string $directory   The root directory of the application.
	 * @param array  $controllers All of the application controllers.
	 */
	public function __construct( $name, $directory, $controllers ) {
		$this->name          = $name;
		$this->directory     = $directory;
		$this->directory_uri = plugins_url( basename( $directory ), $directory );
		$this->controllers   = $controllers;

		$this->load_config();
		add_action( 'plugins_loaded', [ $this, 'setup_controllers' ] );
	}

	/**
	 * Set up the application controllers.
	 * This should be called on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function setup_controllers() {
		foreach ( $this->controllers as $controller ) {

			// Apply filters before setting instances.
			$controller = apply_filters( 'base_pre_controller_set_instances', $controller );

			// Dynamically set required service instances based on traits used in the controller.
			$this->set_services_for_controller( $controller );

			// Apply filters after setting instances.
			$controller = apply_filters( 'base_post_controller_set_instances', $controller );

			// Set config for the controller.
			$controller->set_config_instance( $this->config );

			// Set up the controller.
			$controller = apply_filters( 'base_pre_controller_set_up', $controller );
			$controller->set_up();
			$controller = apply_filters( 'base_post_controller_set_up', $controller );
		}
	}

	/**
	 * Dynamically set services (Route, REST, AdminAjax) for a controller based on the traits it uses.
	 *
	 * @param object $controller The controller instance.
	 *
	 * @return void
	 */
	protected function set_services_for_controller( $controller ) {
		$used_traits = $this->get_traits_recursive( $controller );

		// Set Route instance if controller uses RouteTrait.
		if ( isset( $used_traits[ RouteTrait::class ] ) ) {
			$this->services['route'] ??= new Route();
			$controller->set_route_instance( $this->services['route'] );
		}

		// Set REST instance if controller uses RESTTrait.
		if ( isset( $used_traits[ RESTTrait::class ] ) ) {
			$this->services['rest'] ??= new REST();
			$controller->set_rest_instance( $this->services['rest'] );
		}

		// Set AdminAjax instance if controller uses AdminAjaxTrait.
		if ( isset( $used_traits[ AdminAjaxTrait::class ] ) ) {
			$this->services['admin_ajax'] ??= new AdminAjax();
			$controller->set_admin_ajax_instance( $this->services['admin_ajax'] );
		}
	}

	/**
	 * Collect every trait used by an object: its class, all parent classes,
	 * and traits composed by other traits.
	 *
	 * class_uses() alone is non-recursive — it misses traits pulled in by a
	 * parent class or by another trait, so service detection would skip them.
	 *
	 * @param object $instance The object to inspect.
	 *
	 * @return array Trait names, keyed by trait name (class_uses() shape).
	 */
	protected function get_traits_recursive( $instance ) {
		$traits = [];

		// Traits used directly by the class and each of its parents.
		$class = get_class( $instance );
		do {
			$traits += class_uses( $class ) ?: [];
			$class   = get_parent_class( $class );
		} while ( false !== $class );

		// Traits used by those traits, transitively.
		$queue = $traits;
		while ( ! empty( $queue ) ) {
			$nested = [];
			foreach ( $queue as $trait ) {
				$nested += class_uses( $trait ) ?: [];
			}
			$queue   = array_diff_key( $nested, $traits );
			$traits += $queue;
		}

		return $traits;
	}

	/**
	 * Get the application configuration object.
	 *
	 * @return Config
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Returns the root directory path of the application.
	 *
	 * @return string
	 */
	public function get_directory() {
		return $this->directory;
	}

	/**
	 * Returns the root directory URI of the application.
	 *
	 * @return string
	 */
	public function get_directory_uri() {
		return $this->directory_uri;
	}

	/**
	 * Returns the name/slug of the application.
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Load the application config from file.
	 *
	 * @return void
	 */
	protected function load_config() {
		$this->config = new Config( $this );
		$this->config->autoload();
	}
}
