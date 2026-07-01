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
