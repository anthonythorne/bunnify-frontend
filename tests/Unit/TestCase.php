<?php
/**
 * Base test case wiring Brain Monkey setUp/tearDown.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Shared base for isolated unit tests.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
