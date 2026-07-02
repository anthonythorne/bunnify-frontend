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

	/**
	 * Stub get_option() from a map, honouring the caller's default for
	 * options absent from the map (mirrors a never-saved option).
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

	public function test_init_cdn_false_when_hostname_option_is_unset(): void {
		// get_option() returns false for a missing option; the trait must coerce
		// that to '' and bail (no strict_types TypeError).
		$this->stub_options( [] );

		$this->assertFalse( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_false_when_hostname_is_empty_string(): void {
		$this->stub_options( [ 'bunnify_hostname' => '' ] );

		$this->assertFalse( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_true_when_hostname_configured_and_is_idempotent(): void {
		$this->stub_options( [ 'bunnify_hostname' => 'cdn.example.com' ] );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$controller = new CDNController();
		$this->assertTrue( $this->init_cdn( $controller ) );
		// Second call short-circuits on the cached transformer.
		$this->assertTrue( $this->init_cdn( $controller ) );
	}

	public function test_init_cdn_false_when_plugin_explicitly_disabled(): void {
		// Unticking "Enable BunnyCDN" stores an explicit '0' (via the hidden
		// field); the master switch must win even with a hostname configured.
		$this->stub_options(
			[
				'bunnify_enabled'  => '0',
				'bunnify_hostname' => 'cdn.example.com',
			]
		);

		$this->assertFalse( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_true_for_legacy_empty_enabled_value(): void {
		// A stored '' predates the working master switch (options.php writes
		// '' for a checkbox absent from the POST) — it must stay enabled.
		$this->stub_options(
			[
				'bunnify_enabled'  => '',
				'bunnify_hostname' => 'cdn.example.com',
			]
		);
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->assertTrue( $this->init_cdn( new CDNController() ) );
	}

	public function test_init_cdn_true_when_enabled_option_never_saved(): void {
		// Legacy installs predate the master switch: a missing option must
		// keep rewriting enabled.
		$this->stub_options( [ 'bunnify_hostname' => 'cdn.example.com' ] );
		Functions\when( 'sanitize_text_field' )->returnArg();

		$this->assertTrue( $this->init_cdn( new CDNController() ) );
	}
}
