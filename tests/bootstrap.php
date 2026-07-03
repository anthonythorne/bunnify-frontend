<?php
/**
 * PHPUnit bootstrap for the Bunnify Frontend unit suite.
 *
 * These are isolated unit tests: WordPress is not loaded. Brain Monkey mocks
 * WordPress functions per-test. A handful of WordPress time constants are
 * required at class-load time (CachingTrait declares TTL constants in terms of
 * them), so they are defined here before the autoloader runs.
 *
 * @package BunnifyFrontend\Tests
 */

declare( strict_types=1 );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );
defined( 'WEEK_IN_SECONDS' ) || define( 'WEEK_IN_SECONDS', 604800 );
defined( 'MONTH_IN_SECONDS' ) || define( 'MONTH_IN_SECONDS', 2592000 );
defined( 'YEAR_IN_SECONDS' ) || define( 'YEAR_IN_SECONDS', 31536000 );

require dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Minimal WP_HTML_Tag_Processor test double.
 *
 * WordPress is not loaded in the unit suite, but a few helpers accept a
 * WP_HTML_Tag_Processor. This double tracks the first <img> tag's attributes
 * (enough for attribute-level assertions); it does not fully parse HTML and is
 * only defined when the real class is absent, so integration runs are unaffected.
 */
if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
	// phpcs:ignore
	class WP_HTML_Tag_Processor {

		/** @var array<string,string> */
		private array $attributes = array();

		/** @var string */
		private string $tag_name = '';

		/**
		 * @param string $html Markup containing (at most) one img to inspect.
		 */
		public function __construct( string $html ) {
			if ( preg_match( '/<(\w+)\b([^>]*)>/', $html, $m ) ) {
				$this->tag_name = strtolower( $m[1] );
				if ( preg_match_all( '/([\w-]+)\s*=\s*"([^"]*)"/', $m[2], $pairs, PREG_SET_ORDER ) ) {
					foreach ( $pairs as $pair ) {
						$this->attributes[ strtolower( $pair[1] ) ] = $pair[2];
					}
				}
			}
		}

		/**
		 * @param string|null $tag Tag name to match (case-insensitive), or null for any.
		 */
		public function next_tag( $tag = null ): bool {
			return '' !== $this->tag_name && ( null === $tag || strtolower( (string) $tag ) === $this->tag_name );
		}

		/**
		 * @param string $name Attribute name.
		 * @return string|null
		 */
		public function get_attribute( $name ) {
			return $this->attributes[ strtolower( $name ) ] ?? null;
		}

		/**
		 * @param string $name  Attribute name.
		 * @param mixed  $value Attribute value.
		 */
		public function set_attribute( $name, $value ): bool {
			$this->attributes[ strtolower( $name ) ] = (string) $value;
			return true;
		}

		/**
		 * @param string $name Attribute name.
		 */
		public function remove_attribute( $name ): bool {
			unset( $this->attributes[ strtolower( $name ) ] );
			return true;
		}

		public function get_updated_html(): string {
			$parts = array();
			foreach ( $this->attributes as $key => $value ) {
				$parts[] = $key . '="' . $value . '"';
			}
			return '<' . $this->tag_name . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . '>';
		}
	}
}
