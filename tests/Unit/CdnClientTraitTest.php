<?php
/**
 * Unit tests for the CdnClientTrait (exercised via CDNController).
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Controller\CDNController;
use ReflectionMethod;

/**
 * @covers \BunnifyFrontend\Library\CdnClientTrait
 */
final class CdnClientTraitTest extends TestCase {

	private function init_cdn( CDNController $controller ): bool {
		$method = new ReflectionMethod( $controller, 'init_cdn' );
		$method->setAccessible( true );

		return $method->invoke( $controller );
	}

	public function test_init_cdn_false_when_hostname_option_is_unset(): void {
		// get_option() returns false for a missing option; the trait must coerce
		// that to '' and bail (no strict_types TypeError).
		Functions\when( 'get_option' )->justReturn( false );

		$this->assertFalse( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_false_when_hostname_is_empty_string(): void {
		Functions\when( 'get_option' )->justReturn( '' );

		$this->assertFalse( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_true_when_hostname_configured_and_is_idempotent(): void {
		Functions\when( 'get_option' )->justReturn( 'cdn.example.com' );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$controller = new CDNController();
		$this->assertTrue( $this->init_cdn( $controller ) );
		// Second call short-circuits on the cached transformer.
		$this->assertTrue( $this->init_cdn( $controller ) );
	}
}
