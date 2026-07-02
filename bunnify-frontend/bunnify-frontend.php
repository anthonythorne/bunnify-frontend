<?php
/**
 * Plugin Name:       Bunnify Frontend
 * Plugin URI:        https://github.com/anthonythorne/bunnify-frontend
 * Description:       Lightweight, frontend-only BunnyCDN image delivery for WordPress — rewrites media URLs to your Bunny pull zone with on-the-fly resizing.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:            Anthony Thorne
 * Author URI:        https://github.com/anthonythorne
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bunnify-frontend
 * Domain Path:       /languages
 *
 * @package BunnifyFrontend
 */

namespace BunnifyFrontend;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load package, first check if this is standalone, and then check if this is a dependency.
 */
$bunnify_autoload_file = __DIR__ . '/autoload.php';

// Bail early if the file does not exist.
if ( ! file_exists( $bunnify_autoload_file ) ) {
	return;
}

require_once $bunnify_autoload_file;

// Bail early if the constant is already defined.
if ( defined( 'BunnifyFrontend\\APP_NAME' ) ) {
	return;
}

// Define the APP_NAME constant to prevent further duplicate loads. Based of the folder name.
define( 'BunnifyFrontend\\APP_NAME', basename( __DIR__ ) );

// Initialize the app.
$bunnify_app = new \BunnifyFrontend\Base\Main\Application(
	APP_NAME,
	__DIR__,
	[
		new \BunnifyFrontend\Controller\CDNController(),
		new \BunnifyFrontend\Controller\WPResourceHintsController(),
		new \BunnifyFrontend\Controller\ContentController(),
		new \BunnifyFrontend\Controller\ImageController(),
		new \BunnifyFrontend\Controller\SettingsController(),
	],
);

global $bunnify_app_config;

/**
 * Variable Type Definition.
 *
 * @param \BunnifyFrontend\Base\Library\Config $bunnify_app_config The config for this app.
 */
$bunnify_app_config = $bunnify_app->get_config();
