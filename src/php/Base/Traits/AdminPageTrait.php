<?php
/**
 * Trait for registering and rendering admin pages within Controllers.
 *
 * File Path: src/php/Base/Traits/AdminPageTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * Trait for registering and rendering admin pages within Controllers.
 *
 * Note: Any class using this trait must call `initialize_admin_page()` in the `set_up()` method to
 * ensure the admin page functionality is hooked into the WordPress lifecycle.
 */
trait AdminPageTrait {

	/**
	 * Initialize the admin page by hooking it into WordPress lifecycle.
	 *
	 * @return void
	 */
	public function initialize_admin_page(): void {
		// Register admin page and ensure it's only done for enabled pages.
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
	}

	/**
	 * Register the admin page.
	 *
	 * This checks if the controller allows the admin page (via `show_admin_page()`),
	 * and if it does, hooks into the WordPress admin system.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		// Bail if the page shouldn't be shown or if the controller isn't enabled.
		if ( ! $this->is_enabled() || ! $this->show_admin_page() ) {
			return;
		}

		// Register the admin submenu.
		add_action( 'admin_menu', [ $this, 'register_admin_sub_page' ] );
	}

	/**
	 * Registers the admin sub-page under a parent page.
	 *
	 * @return void
	 */
	public function register_admin_sub_page(): void {
		add_submenu_page(
			$this->get_admin_parent_slug(),
			$this->get_admin_page_title(),
			$this->get_admin_menu_title(),
			$this->get_access_cap(),
			$this->get_page_slug(),
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Abstract method to define whether the admin page should be shown.
	 *
	 * @return bool
	 */
	abstract public function show_admin_page(): bool;

	/**
	 * Abstract method to define the content rendering logic for the admin page.
	 *
	 * @return void
	 */
	abstract public function render_admin_page(): void;

	/**
	 * Abstract method to return the parent slug of the admin menu.
	 *
	 * @return string
	 */
	abstract public function get_admin_parent_slug(): string;

	/**
	 * Abstract method to return the title of the admin page.
	 *
	 * @return string
	 */
	abstract public function get_admin_page_title(): string;

	/**
	 * Abstract method to return the title of the menu item.
	 *
	 * @return string
	 */
	abstract public function get_admin_menu_title(): string;

	/**
	 * Abstract method to return the required capability to access this page.
	 *
	 * @return string
	 */
	abstract public function get_access_cap(): string;

	/**
	 * Abstract method to return the slug for the page.
	 *
	 * @return string
	 */
	abstract public function get_page_slug(): string;
}
