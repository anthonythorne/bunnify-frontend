<?php
/**
 * Unit tests for the Base Application trait-driven service detection.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Tests\Unit;

use BunnifyFrontend\Base\Main\Application;
use ReflectionClass;
use ReflectionMethod;

// Fixture hierarchy: a trait reachable only via the parent class, and a
// trait reachable only via another trait — both invisible to a plain
// class_uses() call on the child.
trait FixtureLeafTrait {}
trait FixtureComposedTrait {
	use FixtureLeafTrait;
}
trait FixtureParentTrait {}

// phpcs:disable Generic.Files.OneObjectStructurePerFile
class FixtureParent {
	use FixtureParentTrait;
}
class FixtureChild extends FixtureParent {
	use FixtureComposedTrait;
}
// phpcs:enable

/**
 * @covers \BunnifyFrontend\Base\Main\Application
 */
final class ApplicationTest extends TestCase {

	/**
	 * Invoke the protected get_traits_recursive() without running the
	 * constructor (which needs a live WordPress).
	 *
	 * @param object $instance Object to inspect.
	 */
	private function get_traits_recursive( object $instance ): array {
		$application = ( new ReflectionClass( Application::class ) )->newInstanceWithoutConstructor();
		$method      = new ReflectionMethod( $application, 'get_traits_recursive' );
		$method->setAccessible( true );

		return $method->invoke( $application, $instance );
	}

	public function test_finds_directly_used_trait(): void {
		$traits = $this->get_traits_recursive( new FixtureChild() );

		$this->assertArrayHasKey( FixtureComposedTrait::class, $traits );
	}

	public function test_finds_trait_used_by_parent_class(): void {
		$traits = $this->get_traits_recursive( new FixtureChild() );

		$this->assertArrayHasKey( FixtureParentTrait::class, $traits );
	}

	public function test_finds_trait_composed_by_another_trait(): void {
		$traits = $this->get_traits_recursive( new FixtureChild() );

		$this->assertArrayHasKey( FixtureLeafTrait::class, $traits );
	}

	public function test_plain_class_uses_misses_the_inherited_traits(): void {
		// Guards the reason get_traits_recursive() exists: if PHP ever makes
		// class_uses() recursive, this flags the helper as removable.
		$direct = class_uses( FixtureChild::class );

		$this->assertArrayNotHasKey( FixtureParentTrait::class, $direct );
		$this->assertArrayNotHasKey( FixtureLeafTrait::class, $direct );
	}
}
