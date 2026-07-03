<?php
/**
 * Unit tests for the AttachmentUrl origin accessor + suspend guard.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey\Functions;
use BunnifyFrontend\Library\AttachmentUrl;

/**
 * @covers \BunnifyFrontend\Library\AttachmentUrl
 */
final class AttachmentUrlTest extends TestCase {

	public function test_not_suspended_by_default(): void {
		$this->assertFalse( AttachmentUrl::is_suspended() );
	}

	public function test_origin_suspends_during_the_core_call_and_restores_after(): void {
		$suspended_during = null;
		Functions\when( 'wp_get_attachment_url' )->alias(
			static function () use ( &$suspended_during ) {
				$suspended_during = AttachmentUrl::is_suspended();
				return 'https://origin.example.com/x.jpg';
			}
		);

		$this->assertFalse( AttachmentUrl::is_suspended(), 'not suspended before' );
		$url = AttachmentUrl::origin( 5 );

		$this->assertSame( 'https://origin.example.com/x.jpg', $url );
		$this->assertTrue( $suspended_during, 'suspended during the inner wp_get_attachment_url() call' );
		$this->assertFalse( AttachmentUrl::is_suspended(), 'flag restored after' );
	}

	public function test_origin_restores_the_flag_even_when_core_returns_false(): void {
		Functions\when( 'wp_get_attachment_url' )->justReturn( false );

		$this->assertFalse( AttachmentUrl::origin( 999 ) );
		$this->assertFalse( AttachmentUrl::is_suspended(), 'flag restored after a false result' );
	}

	public function test_origin_is_nested_safe(): void {
		// A nested origin() call (the real code path: get_cdn_url_by_id() calls
		// origin() from inside a wp_get_attachment_url filter) must restore the
		// flag to its PREVIOUS value (true), not blindly to false.
		$flag_after_nested = null;
		Functions\when( 'wp_get_attachment_url' )->alias(
			static function ( $id ) use ( &$flag_after_nested ) {
				if ( 1 === $id ) {
					// We are already suspended here; make a nested origin() call.
					AttachmentUrl::origin( 2 );
					$flag_after_nested = AttachmentUrl::is_suspended();
				}
				return 'https://origin.example.com/' . $id . '.jpg';
			}
		);

		AttachmentUrl::origin( 1 );

		$this->assertTrue( $flag_after_nested, 'nested origin() restored to the previous true, not false' );
		$this->assertFalse( AttachmentUrl::is_suspended(), 'outermost call restored to false' );
	}
}
