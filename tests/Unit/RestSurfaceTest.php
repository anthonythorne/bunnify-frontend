<?php
/**
 * Guards the plugin's REST posture.
 *
 * The no-op RESTController (a stub of Jetpack Photon's disable-rewriting-
 * during-REST guard that never did anything) was removed per the
 * rest-controller-completion blueprint. These tests keep the withdrawn
 * surface from silently returning: the plugin registers no REST-specific
 * hooks, and its rewriting reaches REST responses only through the general
 * content/image filters — a documented, deliberate behaviour.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class RestSurfaceTest extends TestCase {

	private const WITHDRAWN_HOOKS = [
		'rest_request_before_callbacks',
		'rest_after_insert_attachment',
		'rest_request_after_callbacks',
	];

	public function test_rest_controller_class_is_gone(): void {
		$this->assertFalse(
			class_exists( 'BunnifyFrontend\\Controller\\RESTController' ),
			'RESTController was removed as a never-functional no-op; reintroduce REST behaviour only via the rest-controller-completion blueprint escape hatch.'
		);
	}

	public function test_bootstrap_does_not_register_rest_controller(): void {
		$bootstrap = (string) file_get_contents( dirname( __DIR__, 2 ) . '/bunnify-frontend/bunnify-frontend.php' );

		$this->assertStringNotContainsString( 'RESTController', $bootstrap );
	}

	public function test_plugin_source_attaches_nothing_to_the_withdrawn_rest_hooks(): void {
		$src  = dirname( __DIR__, 2 ) . '/bunnify-frontend/src';
		$hits = [];

		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src ) );
		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$contents = (string) file_get_contents( $file->getPathname() );
			foreach ( self::WITHDRAWN_HOOKS as $hook ) {
				if ( false !== strpos( $contents, $hook ) ) {
					$hits[] = $file->getPathname() . ' references ' . $hook;
				}
			}
		}

		$this->assertSame( [], $hits );
	}
}
