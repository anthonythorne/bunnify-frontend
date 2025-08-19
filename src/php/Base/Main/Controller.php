<?php
/**
 * Base controller class which all controllers should extend.
 *
 * File Path: src/php/Base/Main/Controller.php
 *
 * @package BunnifyFrontend\Base\Main
 */

namespace BunnifyFrontend\Base\Main;

use BunnifyFrontend\Base\Library\Config;

/**
 * Base controller class.
 */
abstract class Controller {

	/**
	 * The config helper instance.
	 *
	 * @var Config
	 */
	protected $config = null;

	/**
	 * Called automatically at `plugins_loaded`.
	 * This must be overridden by child controllers.
	 *
	 * @return void
	 */
	abstract public function set_up();

	/**
	 * Get the Config instance.
	 *
	 * @return Config
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Set the Config manager instance for the controller.
	 *
	 * @param Config $config Config manager instance the controller should use.
	 *
	 * @return void
	 */
	public function set_config_instance( Config $config ) {
		$this->config = $config;
	}
}
