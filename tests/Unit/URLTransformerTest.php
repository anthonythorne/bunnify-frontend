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
		// Format negotiation reads these in build_query_string(); default to
		// "off" (the option's own default) unless a test overrides them.
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

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

	public function test_build_query_string_numeric_crop_emits_only_c(): void {
		// '1'/'0' carry no geometry — they are the checkbox form of the flag.
		$this->assertSame(
			'width=300&c=1',
			$this->build_query_string(
				$this->make(),
				array(
					'width' => 300,
					'crop'  => '1',
				)
			)
		);
		$this->assertSame(
			'width=300',
			$this->build_query_string(
				$this->make(),
				array(
					'width' => 300,
					'crop'  => '0',
				)
			)
		);
	}

	/**
	 * Bunny's native crop form is a geometry string (`crop=w,h[,x,y]`) that the
	 * `c=1` shorthand cannot express; it must still reach the CDN raw, exactly
	 * as it did in v1.0.0 (alongside the mapped `c=1`).
	 */
	public function test_build_query_string_geometry_crop_passes_through(): void {
		$this->assertSame(
			'width=300&c=1&crop=300%2C200',
			$this->build_query_string(
				$this->make(),
				array(
					'width' => 300,
					'crop'  => '300,200',
				)
			)
		);
	}

	/**
	 * Stub get_option + apply_filters so format-negotiation reads specific
	 * quality/format option values (and no filter override).
	 *
	 * @param array $options Option map.
	 */
	private function stub_format_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_format_negotiation_off_by_default_is_byte_parity(): void {
		// No options set → no quality/format params, output identical to before.
		$transformer = $this->make();
		$this->stub_format_options( array() );

		$this->assertSame(
			'width=300&height=200',
			$this->build_query_string( $transformer, array( 'width' => 300, 'height' => 200 ) )
		);
	}

	public function test_format_negotiation_emits_quality_and_format_when_configured(): void {
		// Build the transformer first: make() stubs get_option, so the option
		// overrides must be applied after construction.
		$transformer = $this->make();
		$this->stub_format_options(
			array(
				'bunnify_default_quality' => '75',
				'bunnify_format'          => 'webp',
			)
		);

		$this->assertSame(
			'width=300&quality=75&format=webp',
			$this->build_query_string( $transformer, array( 'width' => 300 ) )
		);
	}

	public function test_format_negotiation_explicit_args_win_over_defaults(): void {
		$transformer = $this->make();
		$this->stub_format_options(
			array(
				'bunnify_default_quality' => '75',
				'bunnify_format'          => 'webp',
			)
		);

		// A caller passing its own quality/format must not be overridden.
		$this->assertSame(
			'width=300&quality=40&format=avif',
			$this->build_query_string(
				$transformer,
				array(
					'width'   => 300,
					'quality' => 40,
					'format'  => 'avif',
				)
			)
		);
	}

	public function test_format_negotiation_ignores_out_of_range_quality_and_bad_format(): void {
		$transformer = $this->make();
		$this->stub_format_options(
			array(
				'bunnify_default_quality' => '0',
				'bunnify_format'          => 'gif',
			)
		);

		$this->assertSame(
			'width=300',
			$this->build_query_string( $transformer, array( 'width' => 300 ) )
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

	/**
	 * Reset the static CDN state get_cdn_url_by_id() caches per request.
	 */
	private function reset_static_cdn(): void {
		foreach ( array( 'static_instance', 'static_hostname' ) as $prop ) {
			$property = new \ReflectionProperty( URLTransformer::class, $prop );
			$property->setAccessible( true );
			$property->setValue( null, null );
		}
	}

	/**
	 * Stub get_option() from a map, honouring the caller's default for
	 * options absent from the map.
	 *
	 * @param array $options Option values keyed by option name.
	 */
	private function stub_options( array $options ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $options ) {
				return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
			}
		);
	}

	/**
	 * When the master switch is off, get_cdn_url_by_id() must return null so
	 * callers leave their input untouched. Returning the origin URL (the old
	 * behaviour) short-circuited image_downsize with the FULL-SIZE original,
	 * collapsing every intermediate size on disabled installs.
	 */
	public function test_get_cdn_url_by_id_null_when_disabled(): void {
		$this->reset_static_cdn();
		$this->stub_options(
			array(
				'bunnify_enabled'  => '0',
				'bunnify_hostname' => 'cdn.example.com',
			)
		);
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://origin.example.com/wp-content/uploads/a.jpg' );

		$this->assertNull( URLTransformer::get_cdn_url_by_id( 42, array( 'width' => 300 ) ) );
	}

	public function test_get_cdn_url_by_id_null_when_hostname_unconfigured(): void {
		$this->reset_static_cdn();
		$this->stub_options( array() );
		Functions\when( 'wp_get_attachment_url' )->justReturn( 'https://origin.example.com/wp-content/uploads/a.jpg' );

		$this->assertNull( URLTransformer::get_cdn_url_by_id( 42, array( 'width' => 300 ) ) );
	}
}
