<?php
/**
 * Trait for logging functionality which is used by Controllers.
 *
 * File Path: src/php/Base/Traits/LoggingTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * Trait for logging functionality.
 */
trait LoggingTrait {

	/**
	 * Log level "info" for logging informational messages.
	 * Used for general informational events, such as process completion, user actions, etc.
	 *
	 * @var string
	 */
	const LOG_LEVEL_INFO = 'info';

	/**
	 * Log level "debug" for logging debugging messages.
	 * Typically used for internal system events that are not visible to end-users.
	 *
	 * @var string
	 */
	const LOG_LEVEL_DEBUG = 'debug';

	/**
	 * Log level "warning" for logging warning messages.
	 * Indicates potential issues or unexpected events that do not disrupt normal operations.
	 *
	 * @var string
	 */
	const LOG_LEVEL_WARNING = 'warning';

	/**
	 * Log level "error" for logging error messages.
	 * Used when an error occurs that requires attention but does not stop the application from running.
	 *
	 * @var string
	 */
	const LOG_LEVEL_ERROR = 'error';

	/**
	 * Log level "critical" for logging critical error messages.
	 * Indicates serious problems that may cause the application to stop functioning or require immediate attention.
	 *
	 * @var string
	 */
	const LOG_LEVEL_CRITICAL = 'critical';

	/**
	 * Default log level used when none is specified.
	 * Defaults to "info" level for general logging purposes.
	 *
	 * @var string
	 */
	const LOG_LEVEL_DEFAULT = self::LOG_LEVEL_INFO;

	/**
	 * Log a message to Simple History or Stream plugin.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Log data.
	 *
	 * @return void
	 */
	protected function log_message( $level = self::LOG_LEVEL_DEFAULT, $message = 'N/A', $context = [] ): void {

		if ( function_exists( 'SimpleLogger' ) ) {
			\SimpleLogger()->$level( $message, $context );
		}
	}

	/**
	 * Print a message to the console.
	 *
	 * @param string $message       Sprintf format string.
	 * @param array  $message_args  Sprintf args.
	 * @param array  $data          The data.
	 */
	protected function print( $message, $message_args = [], $data = [] ) {
		if ( ! $this->is_log_on ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::line( vsprintf( $message, $message_args ) );
			if ( $data ) {
				$this->print_data( $data );
			}
		}
	}

	/**
	 * Print an error message to the console.
	 *
	 * @param string $message      Sprintf format string.
	 * @param array  $message_args Sprintf args.
	 * @param array  $data         The data.
	 */
	protected function print_error( $message, $message_args = [], $data = [] ) {
		if ( ! $this->is_log_on ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			if ( $data ) {
				$this->print_data( $data );
			}
			\WP_CLI::error( vsprintf( $message, $message_args ) );
		}
	}

	/**
	 * Print an array of data for displaying in the console.
	 *
	 * @param array|object|string $data An array of data to be displayed.
	 */
	public function print_data( $data = [] ) {
		if ( ! $this->is_log_on || empty( $data ) ) {
			return;
		}

		if ( is_array( $data ) && defined( 'WP_CLI' ) && WP_CLI ) {
			$data = self::pre_format_items( $data );
			if ( $data ) {
				self::format_items( 'table', $data, [ 'key', 'value' ] );
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// Debug-only, never echoed to the page.
			error_log( print_r( $data, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
		}
	}

	/**
	 * Render a collection of items as an ASCII table, JSON, CSV, YAML, list of ids, or count.
	 *
	 * @param string       $format Format to use: 'table', 'json', 'csv', 'yaml', 'ids', 'count'.
	 * @param array        $items  An array of items to output.
	 * @param array|string $fields Named fields for each item of data.
	 */
	public static function format_items( $format, $items, $fields ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI\Utils\format_items( $format, $items, $fields );
		}
	}

	/**
	 * Format a collection of items into a consistent data structure.
	 *
	 * @param array $items An array of items to format.
	 *
	 * @return array
	 */
	protected static function pre_format_items( $items ) {
		$formatted_items = [];

		if ( ! empty( $items ) && is_array( $items ) ) {
			foreach ( $items as $key => $item ) {
				if ( isset( $item['value'] ) ) {
					$key               = $item['key'] ?? $key;
					$value             = is_array( $item['value'] ) ? http_build_query( $item['value'], '', ', ' ) : $item['value'];
					$formatted_items[] = [
						'key'   => $key,
						'value' => $value,
					];
				} else {
					$value             = is_array( $item ) ? http_build_query( $item, '', ', ' ) : $item;
					$formatted_items[] = [
						'key'   => $key,
						'value' => $value,
					];
				}
			}
		}

		return $formatted_items;
	}
}
