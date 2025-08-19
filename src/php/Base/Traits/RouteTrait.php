<?php
/**
 * Trait for handling Routes which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/RouteTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

use BunnifyFrontend\Base\Library\Route;

trait RouteTrait {

	/**
	 * Route helper instance.
	 *
	 * @var Route
	 */
	protected $route;

	/**
	 * Set the Route object instance for the controller.
	 *
	 * @param Route $route Route helper instance the controller should use.
	 *
	 * @return void
	 */
	public function set_route_instance( Route $route ) {
		$this->route = $route;
	}

	/**
	 * Get the Route instance.
	 *
	 * @return Route
	 */
	public function get_route() {
		return $this->route;
	}
}
