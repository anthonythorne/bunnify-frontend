<?php
/**
 * Unit tests for the ImageProcessor library.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Library\ImageProcessor;

/**
 * @covers \BunnifyFrontend\Library\ImageProcessor
 */
final class ImageProcessorTest extends TestCase {

	/**
	 * @dataProvider provide_filenames
	 *
	 * @param string           $filename Candidate filename.
	 * @param array|false      $expected Expected [width, height] or false.
	 */
	public function test_parse_dimensions_from_filename( string $filename, $expected ): void {
		$this->assertSame( $expected, ImageProcessor::parse_dimensions_from_filename( $filename ) );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, int>|false}>
	 */
	public function provide_filenames(): array {
		return array(
			'single dash'           => array( 'photo-1024x684.jpg', array( 1024, 684 ) ),
			'double dash'           => array( 'photo--1024x684.png', array( 1024, 684 ) ),
			'webp'                  => array( 'hero-800x600.webp', array( 800, 600 ) ),
			'no dimensions'         => array( 'photo.jpg', false ),
			'disallowed extension'  => array( 'doc-1024x684.pdf', false ),
			'dimensions not at end' => array( 'photo-1024x684-thumb.jpg', false ),
		);
	}

	public function test_validate_image_url_accepts_supported_extension(): void {
		$this->stub_url_helpers();

		$this->assertTrue(
			ImageProcessor::validate_image_url( 'https://example.com/wp-content/uploads/a.jpg' )
		);
	}

	public function test_validate_image_url_rejects_non_http_scheme(): void {
		$this->stub_url_helpers();

		$this->assertFalse( ImageProcessor::validate_image_url( 'ftp://example.com/a.jpg' ) );
	}

	public function test_validate_image_url_rejects_disallowed_extension(): void {
		$this->stub_url_helpers();

		$this->assertFalse( ImageProcessor::validate_image_url( 'https://example.com/a.txt' ) );
	}

	/**
	 * Stub the WordPress URL helpers used by ImageProcessor::validate_image_url().
	 */
	private function stub_url_helpers(): void {
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_parse_args' )->alias(
			static fn( $args, $defaults ) => array_merge( $defaults, (array) $args )
		);
		// apply_filters( $tag, $value, ... ) — Brain Monkey returnArg() is 1-based,
		// so arg 2 is $value; pass the value through unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}
}
