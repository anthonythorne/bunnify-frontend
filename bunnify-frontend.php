<?php
/**
 * Plugin Name: Bunnify Frontend
 * Plugin URI: N/A
 * Description: Simplified image CDN functionality that handles only essential WordPress image functions without content filtering.
 * Author: Anthony Thorne
 * Version: 1.0.0
 * Author URI: N/A
 * License: GPL2+
 * Text Domain: bunnify-frontend
 * Requires at least: 6.3
 * Requires PHP: 8.2
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
$autoload_file = __DIR__ . '/build-tools/vendor/autoload.php';

// Bail early if the file does not exist.
if ( ! file_exists( $autoload_file ) ) {
			return;
		}

require_once $autoload_file;

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
		new \BunnifyFrontend\Controller\ContentController(),
		new \BunnifyFrontend\Controller\ImageController(),
		new \BunnifyFrontend\Controller\RESTController(),
		new \BunnifyFrontend\Controller\SettingsController(),
	]
);

global $bunnify_app_config;

/**
 * Variable Type Definition.
 *
 * @param \BunnifyFrontend\Base\Library\Config $bunnify_app_config The config for this app.
 */
$bunnify_app_config = $bunnify_app->get_config();
