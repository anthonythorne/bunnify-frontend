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
			'avif'                  => array( 'shot-640x480.avif', array( 640, 480 ) ),
			'no dimensions'         => array( 'photo.jpg', false ),
			'disallowed extension'  => array( 'doc-1024x684.pdf', false ),
			'dimensions not at end' => array( 'photo-1024x684-thumb.jpg', false ),
		);
	}

	/**
	 * The -scaled fallback must insert the suffix before the extension only,
	 * keeping the host and path intact (the old str_replace('.','-scaled.')
	 * produced "example-scaled.com/...photo-scaled.jpg" and never matched).
	 */
	public function test_scaled_retry_affixes_suffix_before_extension_only(): void {
		$seen = array();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );
		Functions\when( 'get_post' )->justReturn(
			(object) array(
				'ID'        => 77,
				'post_date' => '2020-01-01 00:00:00',
			)
		);
		// Base + dimension-stripped lookups miss; only the -scaled variant hits.
		Functions\when( 'attachment_url_to_postid' )->alias(
			static function ( $url ) use ( &$seen ) {
				$seen[] = $url;
				return false !== strpos( $url, '-scaled.jpg' ) ? 77 : 0;
			}
		);

		$id = ImageProcessor::get_attachment_id_from_url(
			'https://example.com/wp-content/uploads/2026/06/photo-300x200.jpg'
		);

		$this->assertSame( 77, $id );
		$this->assertContains(
			'https://example.com/wp-content/uploads/2026/06/photo-scaled.jpg',
			$seen,
			'retry keeps the host and only affixes -scaled before the extension'
		);
		foreach ( $seen as $url ) {
			$this->assertStringNotContainsString( 'example-scaled.com', $url, 'host must never be mangled' );
		}
	}
}
