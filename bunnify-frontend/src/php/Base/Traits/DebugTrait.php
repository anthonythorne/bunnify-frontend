<?php
/**
 * Debug functionality trait for Bunnify Frontend plugin.
 *
 * Consolidates all debug logging, local development mode checks, and log file management.
 *
 * File Path: src/php/Base/Traits/DebugTrait.php
 *
 * @package BunnifyFrontend\Base\Traits
 * @since   1.0.0
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * DebugTrait class.
 *
 * Provides centralized debug functionality for all controllers and libraries.
 */
trait DebugTrait {

	/**
	 * Debug log file path.
	 *
	 * @var string|null
	 */
	private static ?string $debug_log_file = null;

	/**
	 * Debug log file URL.
	 *
	 * @var string|null
	 */
	private static ?string $debug_log_file_url = null;

	/**
	 * Log a debug message with context and category.
	 *
	 * @param string $message The debug message.
	 * @param string $context The context (e.g., method name).
	 * @param string $category The debug category (default: 'general').
	 * @return void
	 */
	protected function debug_log( string $message, string $context = '', string $category = 'general' ): void {
		if ( ! $this->is_debug_enabled_for_category( $category ) ) {
			return;
		}

		$log_file = $this->get_debug_log_file();
		if ( ! $log_file ) {
			return;
		}

		$timestamp = current_time( 'Y-m-d H:i:s' );
		$context_str = ! empty( $context ) ? "[{$context}]" : '';
		$category_str = ! empty( $category ) ? "[{$category}]" : '';
		$log_entry = "[{$timestamp}] {$category_str} {$context_str} {$message}" . PHP_EOL;

		// Write to log file.
		file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );

		// Trim log file if needed.
		$this->trim_log_file_by_refreshes( $log_file );
	}

	/**
	 * Check if debug is enabled for a specific category.
	 *
	 * @param string $category The debug category.
	 * @return bool True if debug is enabled for the category.
	 */
	protected function is_debug_enabled_for_category( string $category ): bool {
		return \BunnifyFrontend\Controller\SettingsController::is_debug_enabled_for_category( $category );
	}

	/**
	 * Check if debug is enabled globally.
	 *
	 * @return bool True if debug is enabled.
	 */
	protected function is_debug_enabled(): bool {
		return (bool) get_option( 'bunnify_debug_enabled', false );
	}

	/**
	 * Check if local development mode is enabled.
	 *
	 * @return bool True if local development mode is enabled.
	 */
	protected function is_local_dev_mode_enabled(): bool {
		return \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled();
	}

	/**
	 * Get the debug log file path, creating a hardened log directory.
	 *
	 * The directory gets an index.php and a Require-all-denied .htaccess so the
	 * log (which can contain absolute server paths) is not browsable on hosts
	 * that honour them.
	 *
	 * @return string|false The log file path or false if not available.
	 */
	protected function get_debug_log_file() {
		if ( null === self::$debug_log_file ) {
			$upload_dir = wp_upload_dir();
			$log_dir    = $upload_dir['basedir'] . '/bunnify-logs';

			// Create and harden the log directory if it doesn't exist.
			if ( ! is_dir( $log_dir ) ) {
				wp_mkdir_p( $log_dir );

				if ( ! file_exists( $log_dir . '/index.php' ) ) {
					file_put_contents( $log_dir . '/index.php', "<?php\n// Silence is golden.\n" );
				}
				if ( ! file_exists( $log_dir . '/.htaccess' ) ) {
					file_put_contents( $log_dir . '/.htaccess', "Require all denied\n" );
				}
			}

			self::$debug_log_file = $log_dir . '/debug.log';
		}

		return self::$debug_log_file;
	}

	/**
	 * Get the debug log file URL.
	 *
	 * @return string|false The log file URL or false if not available.
	 */
	protected function get_debug_log_file_url() {
		if ( null === self::$debug_log_file_url ) {
			$upload_dir = wp_upload_dir();
			$log_dir = $upload_dir['baseurl'] . '/bunnify-logs';
			self::$debug_log_file_url = $log_dir . '/debug.log';
		}

		return self::$debug_log_file_url;
	}

	/**
	 * Trim the log file to keep only the most recent entries.
	 *
	 * @param string $log_file The log file path.
	 * @return void
	 */
	protected function trim_log_file_by_refreshes( string $log_file ): void {
		if ( ! file_exists( $log_file ) ) {
			return;
		}

		$max_refreshes = get_option( 'bunnify_debug_refreshes', 10 );
		$content = file_get_contents( $log_file );

		if ( false === $content ) {
			return;
		}

		$lines = explode( PHP_EOL, $content );
		$lines = array_filter( $lines ); // Remove empty lines.

		// Keep only the most recent entries.
		if ( count( $lines ) > $max_refreshes ) {
			$lines = array_slice( $lines, -$max_refreshes );
			file_put_contents( $log_file, implode( PHP_EOL, $lines ) . PHP_EOL );
		}
	}

	/**
	 * Calculate aspect ratio from original image dimensions.
	 *
	 * @param int|false $attachment_id The attachment ID.
	 * @param int       $width The target width.
	 * @param int       $height The target height.
	 * @return array Array with calculated width and height maintaining aspect ratio.
	 */
	protected function calculate_aspect_ratio_dimensions( $attachment_id, int $width, int $height ): array {
		// Get original image metadata.
		$image_meta = wp_get_attachment_metadata( $attachment_id );
		$original_width = $image_meta['width'] ?? null;
		$original_height = $image_meta['height'] ?? null;

		$result = [ 'width' => $width, 'height' => $height ];

		if ( $original_width && $original_height ) {
			$aspect_ratio = $original_height / $original_width;

			if ( $width ) {
				$result['height'] = round( $width * $aspect_ratio );
			} elseif ( $height ) {
				$result['width'] = round( $height / $aspect_ratio );
			}
		}

		return $result;
	}
}
