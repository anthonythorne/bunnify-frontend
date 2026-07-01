<?php
/**
 * Unit tests for the pure size-resolution helpers in ImageController.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use BunnifyFrontend\Controller\ImageController;
use ReflectionMethod;

/**
 * @covers \BunnifyFrontend\Controller\ImageController
 */
final class ImageControllerTest extends TestCase {

	/**
	 * Invoke a private ImageController method on a fresh instance.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Positional arguments.
	 * @return mixed
	 */
	private function invoke( string $method, array $args ) {
		$reflection = new ReflectionMethod( ImageController::class, $method );
		$reflection->setAccessible( true );

		return $reflection->invoke( new ImageController(), ...$args );
	}

	/**
	 * @dataProvider provide_custom_sizes
	 *
	 * @param string      $size     Custom size string.
	 * @param array|false $expected Expected width/height (note: height is a float).
	 */
	public function test_parse_custom_size( string $size, $expected ): void {
		$this->assertSame( $expected, $this->invoke( 'parse_custom_size', array( $size ) ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, int|float>|false}>
	 */
	public function provide_custom_sizes(): array {
		// parse_custom_size() casts the target width to int but leaves the
		// computed height as the float returned by round() — locked here.
		return array(
			'16:9'          => array( '16:9-768', array( 'width' => 768, 'height' => 432.0 ) ),
			'4:3'           => array( '4:3-1024', array( 'width' => 1024, 'height' => 768.0 ) ),
			'square'        => array( '1:1-600', array( 'width' => 600, 'height' => 600.0 ) ),
			'named size'    => array( 'medium', false ),
			'missing width' => array( '16:9', false ),
		);
	}

	public function test_resolve_size_dimensions_registered_size(): void {
		$sizes = array( 'medium' => array( 'width' => 300, 'height' => 200, 'crop' => false ) );

		$this->assertSame(
			array( 'width' => 300, 'height' => 200 ),
			$this->invoke( 'resolve_size_dimensions', array( 'medium', $sizes ) )
		);
	}

	public function test_resolve_size_dimensions_custom_size(): void {
		$this->assertSame(
			array( 'width' => 768, 'height' => 432.0 ),
			$this->invoke( 'resolve_size_dimensions', array( '16:9-768', array() ) )
		);
	}

	public function test_resolve_size_dimensions_unknown_size(): void {
		$this->assertSame(
			array( 'width' => false, 'height' => false ),
			$this->invoke( 'resolve_size_dimensions', array( 'does-not-exist', array() ) )
		);
	}

	public function test_resolve_size_dimensions_null_dimensions_coalesce_to_false(): void {
		// The `full` size has null width/height; `?? false` coalesces null to
		// false (so no dimensions are sent to the CDN). This locks that behaviour.
		$sizes = array( 'full' => array( 'width' => null, 'height' => null, 'crop' => false ) );

		$this->assertSame(
			array( 'width' => false, 'height' => false ),
			$this->invoke( 'resolve_size_dimensions', array( 'full', $sizes ) )
		);
	}
}
