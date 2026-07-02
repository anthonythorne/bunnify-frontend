<?php
/**
 * Unit tests for the plugin-root runtime autoloader.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use function BunnifyFrontend\autoload_class_path;

/**
 * @coversNothing
 */
final class AutoloaderTest extends TestCase {

	/**
	 * Absolute path to the shippable plugin directory.
	 */
	private static function plugin_dir(): string {
		return dirname( __DIR__, 2 ) . '/bunnify-frontend';
	}

	public static function setUpBeforeClass(): void {
		// The loader is guarded against direct web access; unit tests run
		// outside WordPress, so satisfy the guard before requiring it.
		defined( 'ABSPATH' ) || define( 'ABSPATH', '/tmp/wordpress/' );

		require_once self::plugin_dir() . '/autoload.php';
	}

	/**
	 * Classes across every src/php/ subtree must map to files that exist —
	 * this is what the former Composer classmap proved implicitly, and what
	 * broke silently when a new class was added without re-dumping it.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function class_map_cases(): array {
		return [
			'controller'   => [ 'BunnifyFrontend\Controller\CDNController', '/src/php/Controller/CDNController.php' ],
			'library'      => [ 'BunnifyFrontend\Library\URLTransformer', '/src/php/Library/URLTransformer.php' ],
			'trait'        => [ 'BunnifyFrontend\Library\CdnClientTrait', '/src/php/Library/CdnClientTrait.php' ],
			'model'        => [ 'BunnifyFrontend\Model\PostType\Attachment', '/src/php/Model/PostType/Attachment.php' ],
			'base'         => [ 'BunnifyFrontend\Base\Main\Application', '/src/php/Base/Main/Application.php' ],
			'base nested'  => [ 'BunnifyFrontend\Base\Library\Config', '/src/php/Base/Library/Config.php' ],
		];
	}

	/**
	 * @dataProvider class_map_cases
	 *
	 * @param string $class_name    Fully qualified class name.
	 * @param string $relative_path Expected path relative to the plugin dir.
	 */
	public function test_maps_plugin_classes_to_existing_files( string $class_name, string $relative_path ): void {
		$path = autoload_class_path( $class_name );

		$this->assertSame( self::plugin_dir() . $relative_path, $path );
		$this->assertFileExists( $path );
	}

	public function test_ignores_foreign_namespaces(): void {
		$this->assertNull( autoload_class_path( 'OtherVendor\SomeClass' ) );
		$this->assertNull( autoload_class_path( 'BunnifyFrontendX\Evil' ) );
	}

	public function test_loads_classes_through_spl(): void {
		// class_exists(true) exercises the registered loader (or confirms the
		// class already resolved through it) without fataling.
		$this->assertTrue( class_exists( 'BunnifyFrontend\Model\PostType\Attachment' ) );
	}

	public function test_every_src_class_file_is_reachable_by_the_loader(): void {
		// The inverse direction: walk src/php/ and prove each PHP file's
		// expected class name maps back to that file. Function/ files are
		// side-effect includes, not classes, and are loaded eagerly.
		$src   = self::plugin_dir() . '/src/php';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $src ) + 1 );
			if ( 0 === strpos( $relative, 'Function/' ) ) {
				continue;
			}

			$class_name = 'BunnifyFrontend\\' . str_replace( '/', '\\', substr( $relative, 0, -4 ) );

			$this->assertSame(
				$file->getPathname(),
				autoload_class_path( $class_name ),
				"Loader cannot reach {$relative}"
			);
		}
	}
}
