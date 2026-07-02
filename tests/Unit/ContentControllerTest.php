<?php
/**
 * Unit tests for the ContentController.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use BunnifyFrontend\Controller\ContentController;
use ReflectionMethod;

/**
 * @covers \BunnifyFrontend\Controller\ContentController
 */
final class ContentControllerTest extends TestCase {

	/**
	 * Invoke the private is_attachment_image() heuristic.
	 *
	 * @param array $attributes Img tag attributes keyed by name.
	 */
	private function is_attachment_image( array $attributes ): bool {
		// The heuristic only reads get_attribute(), and the parameter is
		// untyped, so a minimal stub stands in for WP_HTML_Tag_Processor.
		$processor = new class( $attributes ) {
			/**
			 * @param array $attributes Attributes keyed by name.
			 */
			public function __construct( private array $attributes ) {}

			/**
			 * @param string $name Attribute name.
			 * @return string|null Attribute value, null when absent.
			 */
			public function get_attribute( string $name ) {
				return $this->attributes[ $name ] ?? null;
			}
		};

		$method = new ReflectionMethod( ContentController::class, 'is_attachment_image' );
		$method->setAccessible( true );

		return $method->invoke( new ContentController(), $processor );
	}

	/**
	 * Truth table for the ImageController/ContentController hand-off heuristic
	 * (named explicitly by the full-test-coverage blueprint, Phase 1).
	 *
	 * The only attribute combination that skips content processing is a
	 * `wp-post-image` class (featured images, already rewritten by
	 * ImageController's attachment filters). Everything else — including
	 * `wp-image-*`/`attachment-*`/`size-*` classes and srcset/sizes
	 * attributes — is treated as a content image and processed.
	 *
	 * @return array<string, array{array<string, string>, bool}>
	 */
	public static function attachment_image_cases(): array {
		return [
			'featured image (wp-post-image) is skipped'     => [
				[ 'class' => 'attachment-post-thumbnail size-post-thumbnail wp-post-image' ],
				true,
			],
			'wp-post-image with srcset is skipped'          => [
				[
					'class'  => 'wp-post-image',
					'srcset' => 'a-300.jpg 300w, a-600.jpg 600w',
				],
				true,
			],
			'content image with wp-image class processes'   => [
				[ 'class' => 'wp-image-462' ],
				false,
			],
			'attachment-/size- classes alone still process' => [
				[ 'class' => 'attachment-large size-large' ],
				false,
			],
			'srcset without thumbnail class processes'      => [
				[
					'class'  => 'wp-image-462 size-medium',
					'srcset' => 'a-300.jpg 300w, a-600.jpg 600w',
				],
				false,
			],
			'sizes without thumbnail class processes'       => [
				[ 'sizes' => '(max-width: 300px) 100vw, 300px' ],
				false,
			],
			'bare img with only src processes'              => [
				[ 'src' => 'https://example.com/wp-content/uploads/a.jpg' ],
				false,
			],
			'no attributes at all processes'                => [
				[],
				false,
			],
			'width/height alone do not cause a skip'        => [
				[
					'width'  => '300',
					'height' => '200',
				],
				false,
			],
		];
	}

	/**
	 * @dataProvider attachment_image_cases
	 *
	 * @param array $attributes Img tag attributes.
	 * @param bool  $expected   Expected heuristic result.
	 */
	public function test_is_attachment_image_truth_table( array $attributes, bool $expected ): void {
		$this->assertSame( $expected, $this->is_attachment_image( $attributes ) );
	}
}
