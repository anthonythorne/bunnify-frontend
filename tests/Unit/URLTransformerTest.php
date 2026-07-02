<?php
/**
 * Unit tests for the URLTransformer library.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Library\URLTransformer;
use InvalidArgumentException;
use ReflectionMethod;

/**
 * @covers \BunnifyFrontend\Library\URLTransformer
 */
final class URLTransformerTest extends TestCase {

	/**
	 * Build a transformer with a valid hostname (sanitize_text_field stubbed).
	 */
	private function make( string $hostname = 'cdn.example.com' ): URLTransformer {
		Functions\when( 'sanitize_text_field' )->returnArg();

		return new URLTransformer( $hostname );
	}

	/**
	 * Invoke the private build_query_string() method.
	 *
	 * @param array|string $args Arguments to build.
	 */
	private function build_query_string( URLTransformer $transformer, $args ): string {
		$method = new ReflectionMethod( $transformer, 'build_query_string' );
		$method->setAccessible( true );

		return $method->invoke( $transformer, $args );
	}

	public function test_constructor_rejects_empty_hostname(): void {
		$this->expectException( InvalidArgumentException::class );

		new URLTransformer( '   ' );
	}

	public function test_constructor_rejects_malformed_hostname(): void {
		$this->expectException( InvalidArgumentException::class );

		new URLTransformer( 'not a host' );
	}

	public function test_build_query_string_maps_core_size_args(): void {
		$this->assertSame(
			'width=300&height=200',
			$this->build_query_string( $this->make(), array( 'width' => 300, 'height' => 200 ) )
		);
	}

	public function test_build_query_string_returns_string_args_verbatim(): void {
		$this->assertSame( 'w=1&h=2', $this->build_query_string( $this->make(), 'w=1&h=2' ) );
	}

	public function test_build_query_string_passes_through_extra_scalar_args(): void {
		$this->assertSame(
			'width=300&quality=82&format=webp',
			$this->build_query_string(
				$this->make(),
				array(
					'width'   => 300,
					'quality' => 82,
					'format'  => 'webp',
				)
			)
		);
	}

	/**
	 * A truthy `crop` maps to the `c=1` shorthand only. v1.0.0 also leaked a
	 * raw `crop=1` through the generic passthrough (the known issue flagged in
	 * the enterprise-restructure blueprint); the passthrough now skips the
	 * mapped core keys.
	 */
	public function test_build_query_string_truthy_crop_emits_only_c(): void {
		$this->assertSame(
			'width=300&height=200&c=1',
			$this->build_query_string(
				$this->make(),
				array(
					'width'  => 300,
					'height' => 200,
					'crop'   => true,
				)
			)
		);
	}

	public function test_build_query_string_falsy_crop_emits_no_crop_param(): void {
		$this->assertSame(
			'width=300',
			$this->build_query_string(
				$this->make(),
				array(
					'width' => 300,
					'crop'  => false,
				)
			)
		);
	}

	public function test_is_cdn_url_matches_configured_hostname(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );

		$this->assertTrue( URLTransformer::is_cdn_url( 'https://cdn.example.com/wp-content/uploads/a.jpg' ) );
	}

	public function test_is_cdn_url_detects_transform_query_params(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );

		$this->assertTrue( URLTransformer::is_cdn_url( 'https://origin.example.com/a.jpg?width=300' ) );
	}

	public function test_is_cdn_url_false_for_plain_origin_url(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );

		$this->assertFalse( URLTransformer::is_cdn_url( 'https://origin.example.com/wp-content/uploads/a.jpg' ) );
	}

	public function test_validate_image_url_true_for_local_upload_image(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertTrue( URLTransformer::validate_image_url( 'https://example.com/wp-content/uploads/a.jpg' ) );
	}

	public function test_validate_image_url_false_for_disallowed_extension(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->assertFalse( URLTransformer::validate_image_url( 'https://example.com/a.txt' ) );
	}
}
