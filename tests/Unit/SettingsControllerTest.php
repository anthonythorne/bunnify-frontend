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

	/**
	 * Set up the local-dev-mode inputs: the override filter, the environment
	 * type, and the manual option.
	 *
	 * @param mixed  $filter      Value the bunnify_local_dev_mode_check filter returns.
	 * @param string $environment wp_get_environment_type() result.
	 * @param mixed  $option      Stored bunnify_local_dev_mode value.
	 */
	private function stub_local_dev( $filter, string $environment, $option ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value = null ) use ( $filter ) {
				return 'bunnify_local_dev_mode_check' === $hook ? $filter : $value;
			}
		);
		Functions\when( 'wp_get_environment_type' )->justReturn( $environment );
		Functions\when( 'get_option' )->alias(
			static function ( string $name, $default = false ) use ( $option ) {
				return 'bunnify_local_dev_mode' === $name ? $option : $default;
			}
		);
	}

	public function test_local_dev_auto_on_for_development_environments(): void {
		// No filter, no option — enabled purely by the environment type.
		foreach ( array( 'local', 'development' ) as $environment ) {
			$this->stub_local_dev( null, $environment, false );

			$this->assertTrue(
				SettingsController::is_local_dev_mode_enabled(),
				"local-dev should auto-enable on '{$environment}'"
			);
		}
	}

	public function test_local_dev_not_auto_on_for_staging(): void {
		// Staging is deliberately excluded so it still exercises the CDN
		// before a production promotion; it falls through to the option.
		$this->stub_local_dev( null, 'staging', false );
		$this->assertFalse( SettingsController::is_local_dev_mode_enabled() );

		// ...but the option can force it on for a staging box that needs it.
		$this->stub_local_dev( null, 'staging', '1' );
		$this->assertTrue( SettingsController::is_local_dev_mode_enabled() );
	}

	public function test_local_dev_off_on_production_by_default(): void {
		$this->stub_local_dev( null, 'production', false );

		$this->assertFalse( SettingsController::is_local_dev_mode_enabled() );
	}

	public function test_local_dev_option_forces_on_in_production(): void {
		$this->stub_local_dev( null, 'production', '1' );

		$this->assertTrue( SettingsController::is_local_dev_mode_enabled() );
	}

	public function test_local_dev_filter_overrides_auto_on(): void {
		// A local box with every upload synced can force local-dev off.
		$this->stub_local_dev( false, 'local', false );

		$this->assertFalse( SettingsController::is_local_dev_mode_enabled() );
	}

	public function test_local_dev_filter_forces_on_in_production(): void {
		$this->stub_local_dev( true, 'production', false );

		$this->assertTrue( SettingsController::is_local_dev_mode_enabled() );
	}
}
