<?php
/**
 * Trait for handling Admin Ajax which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/AdminAjaxTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

use BunnifyFrontend\Base\Library\AdminAjax;

trait AdminAjaxTrait {

	/**
	 * Admin AJAX helper instance.
	 *
	 * @var AdminAjax
	 */
	protected $admin_ajax;

	/**
	 * Set the Admin Ajax object instance for the controller.
	 *
	 * @param AdminAjax $admin_ajax AdminAjax helper instance the controller should use.
	 *
	 * @return void
	 */
	public function set_admin_ajax_instance( AdminAjax $admin_ajax ) {
		$this->admin_ajax = $admin_ajax;
	}

	/**
	 * Get the Admin Ajax instance.
	 *
	 * @return AdminAjax
	 */
	public function get_admin_ajax() {
		return $this->admin_ajax;
	}
}
