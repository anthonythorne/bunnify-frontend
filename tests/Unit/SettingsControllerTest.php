<?php
/**
 * Unit tests for the SettingsController static helpers.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Controller\SettingsController;

/**
 * @covers \BunnifyFrontend\Controller\SettingsController
 */
final class SettingsControllerTest extends TestCase {

	/**
	 * Stub get_option() to return $value for bunnify_enabled, or the
	 * caller's default when $value is "missing" (option never saved).
	 *
	 * @param mixed $value Stored option value, or the string 'missing'.
	 */
	private function stub_enabled_option( $value ): void {
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $value ) {
				if ( 'bunnify_enabled' === $name && 'missing' !== $value ) {
					return $value;
				}
				return $default;
			}
		);
	}

	public function test_is_enabled_true_when_option_never_saved(): void {
		$this->stub_enabled_option( 'missing' );

		$this->assertTrue( SettingsController::is_enabled() );
	}

	public function test_is_enabled_true_when_checkbox_checked(): void {
		$this->stub_enabled_option( '1' );

		$this->assertTrue( SettingsController::is_enabled() );
	}

	public function test_is_enabled_true_for_legacy_empty_string(): void {
		// options.php stores '' for a whitelisted checkbox absent from the
		// POST, so every settings save made while the checkbox was inert
		// (pre-master-switch) left '' behind. That is a legacy artefact, not
		// a decision to disable — it must NOT turn rewriting off on upgrade.
		$this->stub_enabled_option( '' );

		$this->assertTrue( SettingsController::is_enabled() );
	}

	public function test_is_enabled_false_for_stored_zero(): void {
		// A deliberate disable: the hidden field on the settings screen
		// stores an explicit '0' when the checkbox is unticked.
		$this->stub_enabled_option( '0' );

		$this->assertFalse( SettingsController::is_enabled() );
	}
}
