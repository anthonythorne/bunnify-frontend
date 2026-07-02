<?php
/**
 * Runtime autoloader for Bunnify Frontend.
 *
 * A hand-written, zero-Composer-footprint PSR-4 loader: maps the
 * `BunnifyFrontend\` namespace onto `src/php/` and loads the
 * `src/php/Function/*.php` side-effect files, replicating exactly what the
 * former `build-tools/vendor` Composer autoloader was configured to do
 * (psr-4 + files). No manifests, classmaps, or vendor directory ship with
 * the plugin.
 *
 * File Path: autoload.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BunnifyFrontend;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve a class name in the plugin namespace to its src/php/ file path.
 *
 * Pure path mapping — no filesystem checks — so it stays cheap on the hot
 * path and unit-testable in isolation.
 *
 * @param string $class_name Fully qualified class name being autoloaded.
 * @return string|null Absolute file path candidate, or null for classes
 *                     outside the `BunnifyFrontend\` namespace.
 */
function autoload_class_path( string $class_name ): ?string {
	$prefix = __NAMESPACE__ . '\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return null;
	}

	$relative = substr( $class_name, strlen( $prefix ) );

	return __DIR__ . '/src/php/' . str_replace( '\\', '/', $relative ) . '.php';
}

spl_autoload_register(
	static function ( string $class_name ): void {
		$path = autoload_class_path( $class_name );

		if ( null !== $path && is_file( $path ) ) {
			require_once $path;
		}
	}
);

// Side-effect function files (the former Composer `files` autoload entry).
require_once __DIR__ . '/src/php/Function/AutoLoad.php';
