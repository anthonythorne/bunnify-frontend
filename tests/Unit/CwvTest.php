<?php
/**
 * Unit tests for the Cwv (Core Web Vitals) helper.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Library\Cwv;
use WP_HTML_Tag_Processor;

/**
 * @covers \BunnifyFrontend\Library\Cwv
 */
final class CwvTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Cwv::reset();
	}

	/**
	 * Stub the option that gates a feature (and apply_filters passthrough).
	 *
	 * @param string $option  Option name.
	 * @param bool   $enabled Whether it is on.
	 */
	private function stub_option( string $option, bool $enabled ): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $option, $enabled ) {
				return $name === $option ? $enabled : $default;
			}
		);
	}

	private function img( string $html ): WP_HTML_Tag_Processor {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag( 'img' );
		return $processor;
	}

	public function test_dimensions_added_only_when_missing_and_enabled(): void {
		$this->stub_option( 'bunnify_emit_dimensions', true );

		$p = $this->img( '<img src="x.jpg" width="500">' );
		Cwv::maybe_add_dimensions( $p, 300, 200 );

		// width was author-set (500) → untouched; height was missing → added.
		$this->assertStringContainsString( 'width="500"', $p->get_updated_html() );
		$this->assertStringContainsString( 'height="200"', $p->get_updated_html() );
	}

	public function test_dimensions_not_added_when_disabled(): void {
		$this->stub_option( 'bunnify_emit_dimensions', false );

		$p = $this->img( '<img src="x.jpg">' );
		Cwv::maybe_add_dimensions( $p, 300, 200 );

		$this->assertStringNotContainsString( 'width=', $p->get_updated_html() );
	}

	public function test_lcp_marks_first_image_only(): void {
		$this->stub_option( 'bunnify_lcp_optimize', true );
		Functions\when( 'is_admin' )->justReturn( false );

		$first  = $this->img( '<img src="a.jpg" loading="lazy">' );
		$second = $this->img( '<img src="b.jpg" loading="lazy">' );

		$this->assertTrue( Cwv::maybe_mark_lcp( $first, 'https://cdn/a.jpg' ) );
		$this->assertFalse( Cwv::maybe_mark_lcp( $second, 'https://cdn/b.jpg' ), 'only one LCP per request' );

		$first_html = $first->get_updated_html();
		$this->assertStringContainsString( 'fetchpriority="high"', $first_html );
		$this->assertStringNotContainsString( 'loading=', $first_html, 'lazy removed from the LCP image' );
		$this->assertStringContainsString( 'loading="lazy"', $second->get_updated_html(), 'second image keeps lazy' );
	}

	public function test_lcp_off_by_default_and_in_admin(): void {
		$this->stub_option( 'bunnify_lcp_optimize', false );
		Functions\when( 'is_admin' )->justReturn( false );
		$p = $this->img( '<img src="a.jpg">' );
		$this->assertFalse( Cwv::maybe_mark_lcp( $p, 'https://cdn/a.jpg' ) );

		Cwv::reset();
		$this->stub_option( 'bunnify_lcp_optimize', true );
		Functions\when( 'is_admin' )->justReturn( true );
		$p2 = $this->img( '<img src="a.jpg">' );
		$this->assertFalse( Cwv::maybe_mark_lcp( $p2, 'https://cdn/a.jpg' ), 'never in admin' );
	}
}
