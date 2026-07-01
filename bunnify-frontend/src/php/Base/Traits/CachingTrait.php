<?php
/**
 * Caching functionality trait for Bunnify Frontend plugin.
 *
 * Centralizes all caching operations to improve performance and eliminate duplication.
 *
 * File Path: src/php/Base/Traits/CachingTrait.php
 *
 * @package BunnifyFrontend\Base\Traits
 * @since   1.0.0
 */

namespace BunnifyFrontend\Base\Traits;

/**
 * CachingTrait class.
 *
 * Provides centralized caching functionality for all controllers and libraries.
 */
trait CachingTrait {

	/**
	 * Standard cache group for Bunnify Frontend.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'bunnify_frontend';

	/**
	 * Cache TTL constants.
	 *
	 * @var int
	 */
	private const CACHE_TTL_SHORT = MINUTE_IN_SECONDS * 5;
	private const CACHE_TTL_MEDIUM = HOUR_IN_SECONDS;
	private const CACHE_TTL_LONG = DAY_IN_SECONDS;
	private const CACHE_TTL_VERY_LONG = WEEK_IN_SECONDS;

	/**
	 * Get cached value with standardized group.
	 *
	 * @param string $key The cache key.
	 * @param string $group The cache group (defaults to bunnify_frontend).
	 * @return mixed The cached value or false if not found.
	 */
	protected function get_cached_value( string $key, string $group = self::CACHE_GROUP ) {
		return wp_cache_get( $key, $group );
	}

	/**
	 * Set cached value with standardized group.
	 *
	 * @param string $key The cache key.
	 * @param mixed  $value The value to cache.
	 * @param int    $ttl The time to live in seconds.
	 * @param string $group The cache group (defaults to bunnify_frontend).
	 * @return bool True on success, false on failure.
	 */
	protected function set_cached_value( string $key, $value, int $ttl, string $group = self::CACHE_GROUP ): bool {
		return wp_cache_set( $key, $value, $group, $ttl );
	}

	/**
	 * Delete cached value.
	 *
	 * @param string $key The cache key.
	 * @param string $group The cache group (defaults to bunnify_frontend).
	 * @return bool True on success, false on failure.
	 */
	protected function delete_cached_value( string $key, string $group = self::CACHE_GROUP ): bool {
		return wp_cache_delete( $key, $group );
	}

	/**
	 * Get cached attachment metadata with smart TTL.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array|false The attachment metadata or false if not found.
	 */
	protected function get_cached_attachment_metadata( int $attachment_id ) {
		$cache_key = "attachment_meta_{$attachment_id}";
		$metadata = $this->get_cached_value( $cache_key );

		if ( false === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
			$ttl = $this->get_attachment_cache_ttl( $attachment_id );
			$this->set_cached_value( $cache_key, $metadata ?: 'not_found', $ttl );
		}

		if ( 'not_found' === $metadata ) {
			return false;
		}

		return $metadata;
	}

	/**
	 * Get cached filesystem check result.
	 *
	 * @param string $file_path The file path to check.
	 * @param string $check_type The type of check ('exists', 'readable').
	 * @return bool The cached result.
	 */
	protected function get_cached_filesystem_check( string $file_path, string $check_type = 'exists' ): bool {
		$cache_key = "fs_check_{$check_type}_" . md5( $file_path );
		$result = $this->get_cached_value( $cache_key );

		if ( false === $result ) {
			switch ( $check_type ) {
				case 'exists':
					$result = file_exists( $file_path );
					break;
				case 'readable':
					$result = is_readable( $file_path );
					break;
				default:
					$result = false;
			}
			
			// Cache filesystem checks for a short time to avoid repeated I/O.
			$this->set_cached_value( $cache_key, $result, self::CACHE_TTL_SHORT );
		}

		return (bool) $result;
	}

	/**
	 * Check if file exists with caching.
	 *
	 * @param string $file_path The file path to check.
	 * @return bool True if file exists, false otherwise.
	 */
	protected function cached_file_exists( string $file_path ): bool {
		return $this->get_cached_filesystem_check( $file_path, 'exists' );
	}

	/**
	 * Check if file is readable with caching.
	 *
	 * @param string $file_path The file path to check.
	 * @return bool True if file is readable, false otherwise.
	 */
	protected function cached_is_readable( string $file_path ): bool {
		return $this->get_cached_filesystem_check( $file_path, 'readable' );
	}

	/**
	 * Get cached attachment URL.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The attachment URL or false if not found.
	 */
	protected function get_cached_attachment_url( int $attachment_id ) {
		$cache_key = "attachment_url_{$attachment_id}";
		$url = $this->get_cached_value( $cache_key );

		if ( false === $url ) {
			$url = wp_get_attachment_url( $attachment_id );
			$ttl = $this->get_attachment_cache_ttl( $attachment_id );
			$this->set_cached_value( $cache_key, $url ?: 'not_found', $ttl );
		}

		if ( 'not_found' === $url ) {
			return false;
		}

		return $url;
	}

	/**
	 * Get cached true original URL.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The true original URL or false if not found.
	 */
	protected function get_cached_true_original_url( int $attachment_id ) {
		$cache_key = "true_original_url_{$attachment_id}";
		$url = $this->get_cached_value( $cache_key );

		if ( false === $url ) {
			$url = $this->calculate_true_original_url( $attachment_id );
			$ttl = $this->get_attachment_cache_ttl( $attachment_id );
			$this->set_cached_value( $cache_key, $url ?: 'not_found', $ttl );
		}

		if ( 'not_found' === $url ) {
			return false;
		}

		return $url;
	}

	/**
	 * Calculate the true original URL for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The true original URL or false if not found.
	 */
	private function calculate_true_original_url( int $attachment_id ) {
		$metadata = $this->get_cached_attachment_metadata( $attachment_id );
		if ( ! $metadata || empty( $metadata['file'] ) ) {
			return false;
		}

		$upload_dir = wp_get_upload_dir();
		$file_path = $metadata['file'];

		// Check if the metadata file contains '-scaled' and look for non-scaled version.
		if ( strpos( $file_path, '-scaled.' ) !== false ) {
			$non_scaled_path = str_replace( '-scaled.', '.', $file_path );
			$full_non_scaled_path = $upload_dir['basedir'] . '/' . $non_scaled_path;

			// In local development mode, prefer metadata version without filesystem check.
			if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
				// Use metadata version in local dev mode.
				$original_url = $upload_dir['baseurl'] . '/' . $file_path;
			} else {
				// In production, check filesystem for non-scaled version.
				if ( $this->cached_file_exists( $full_non_scaled_path ) ) {
					$original_url = $upload_dir['baseurl'] . '/' . $non_scaled_path;
				} else {
					$original_url = $upload_dir['baseurl'] . '/' . $file_path;
				}
			}
		} else {
			$original_url = $upload_dir['baseurl'] . '/' . $file_path;
		}

		return $original_url;
	}

	/**
	 * Get smart cache TTL based on attachment age.
	 *
	 * @param int|false $attachment_id The attachment ID.
	 * @return int The cache TTL in seconds.
	 */
	protected function get_attachment_cache_ttl( $attachment_id ): int {
		if ( ! $attachment_id ) {
			return self::CACHE_TTL_SHORT;
		}

		// Get attachment post to determine age.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return self::CACHE_TTL_SHORT;
		}

		$attachment_age = time() - strtotime( $attachment->post_date );

		// Older attachments get longer cache times.
		if ( $attachment_age > YEAR_IN_SECONDS ) {
			return self::CACHE_TTL_VERY_LONG;
		} elseif ( $attachment_age > MONTH_IN_SECONDS ) {
			return self::CACHE_TTL_LONG;
		} elseif ( $attachment_age > WEEK_IN_SECONDS ) {
			return self::CACHE_TTL_MEDIUM;
		} else {
			return self::CACHE_TTL_SHORT;
		}
	}

	/**
	 * Clear all cached data for an attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return void
	 */
	protected function clear_attachment_cache( int $attachment_id ): void {
		$cache_keys = [
			"attachment_meta_{$attachment_id}",
			"attachment_url_{$attachment_id}",
			"true_original_url_{$attachment_id}",
		];

		foreach ( $cache_keys as $key ) {
			$this->delete_cached_value( $key );
		}
	}

	/**
	 * Clear all Bunnify Frontend caches.
	 *
	 * @return void
	 */
	protected function clear_all_bunnify_caches(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}
}
