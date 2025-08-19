<?php
/**
 * Trait for handling REST endpoints which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/RESTTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

use BunnifyFrontend\Base\Library\REST;

trait RESTTrait {

	/**
	 * REST helper instance.
	 *
	 * @var REST
	 */
	protected $rest;

	/**
	 * Set the REST object instance for the controller.
	 *
	 * @param REST $rest REST helper instance the controller should use.
	 *
	 * @return void
	 */
	public function set_rest_instance( REST $rest ) {
		$this->rest = $rest;
	}

	/**
	 * Get the REST instance.
	 *
	 * @return REST
	 */
	public function get_rest() {
		return $this->rest;
	}
}
