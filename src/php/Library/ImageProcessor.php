<?php
/**
 * ImageProcessor logic for Bunnify Frontend plugin.
 *
 * Contains static helpers for parsing image URLs, stripping dimensions, and other image-related utilities.
 *
 * Provides image object and helper methods.
 *
 * File Path: src/php/Library/ImageProcessor.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 */

namespace BunnifyFrontend\Library;

use BunnifyFrontend\Base\Traits\CachingTrait;

/**
 * Class ImageProcessor
 *
 * Contains static helper methods for image processing.
 */
class ImageProcessor {
	use CachingTrait;

	/**
	 * Allowed image extensions.
	 */
	private static array $allowed_extensions = [ 
		'gif',
		'jpg',
		'jpeg',
		'png',
		'webp',
		'heic',
	];

	/**
	 * Parse dimensions from filename.
	 *
	 * @param string $filename The filename to parse.
	 * @return array|false Array with width and height, or false if not found.
	 */
	public static function parse_dimensions_from_filename( string $filename ): array|false {
		// Extract dimensions like -1024x684 or --1024x684 from filename.
		if ( preg_match( '#(-+\d+x\d+)\.(' . implode( '|', self::$allowed_extensions ) . '){1}$#i', $filename, $matches ) ) {
			$dimensions = trim( $matches[1], '-' );
			$parts      = explode( 'x', $dimensions );
			if ( count( $parts ) === 2 ) {
				return [ (int) $parts[0], (int) $parts[1] ];
			}
		}
		return false;
	}

	/**
	 * Get available image sizes and their dimensions.
	 *
	 * This method retrieves all registered WordPress image sizes from the database
	 * and caches them for performance. It includes both core WordPress sizes
	 * and custom sizes registered by themes and plugins.
	 *
	 * @return array Array of image sizes with width, height, and crop information.
	 */
	public static function get_image_sizes(): array {
		// Use static caching for performance.
		static $sizes = null;

		if ( null !== $sizes ) {
			return $sizes;
		}

		global $_wp_additional_image_sizes;

		$sizes = [];

		// Get intermediate image sizes.
		$intermediate_image_sizes = get_intermediate_image_sizes();

		// Create the full array with sizes.
		foreach ( $intermediate_image_sizes as $_size ) {
			if ( in_array( $_size, [ 'thumbnail', 'medium', 'medium_large', 'large' ], true ) ) {
				$sizes[ $_size ]['width']  = get_option( $_size . '_size_w' );
				$sizes[ $_size ]['height'] = get_option( $_size . '_size_h' );
				$sizes[ $_size ]['crop']   = ( 'thumbnail' === $_size ) ? (bool) get_option( 'thumbnail_crop' ) : false;
			} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
				$sizes[ $_size ] = [ 
					'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
					'height' => $_wp_additional_image_sizes[ $_size ]['height'],
					'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
				];
			}
		}

		// Add full size.
		$sizes['full'] = [ 
			'width'  => null,
			'height' => null,
			'crop'   => false,
		];

		// Also include all additional image sizes that might not be in intermediate_image_sizes.
		if ( is_array( $_wp_additional_image_sizes ) ) {
			foreach ( $_wp_additional_image_sizes as $size_name => $size_data ) {
				if ( ! isset( $sizes[ $size_name ] ) ) {
					$sizes[ $size_name ] = [ 
						'width'  => $size_data['width'],
						'height' => $size_data['height'],
						'crop'   => $size_data['crop'],
					];
				}
			}
		}

		return $sizes;
	}

	/**
	 * Validate image URL for Bunnify processing.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	public static function validate_image_url( string $url ): bool {
		$parsed_url = wp_parse_url( $url );

		if ( ! $parsed_url ) {
			return false;
		}

		// Parse URL and ensure needed keys exist.
		$url_info = wp_parse_args(
			$parsed_url,
			[ 
				'scheme' => null,
				'host'   => null,
				'port'   => null,
				'path'   => null,
			],
		);

		// Bail if scheme isn't http/https or port is set that isn't port 80.
		if (
			( 'http' !== $url_info['scheme'] && 'https' !== $url_info['scheme'] ) ||
			( ! in_array( $url_info['port'], [ 80, 443, null ], true ) )
		) {
			return false;
		}

		// Bail if no host is found.
		if ( null === $url_info['host'] ) {
			return false;
		}

		// Bail if no path is found.
		if ( null === $url_info['path'] ) {
			return false;
		}

		// Ensure image extension is acceptable.
		if ( ! in_array( strtolower( pathinfo( $url_info['path'], PATHINFO_EXTENSION ) ), self::$allowed_extensions, true ) ) {
			return false;
		}

		// Allow filtering of validation results.
		return apply_filters( 'bunnify_validate_image_url', true, $url, $parsed_url );
	}

	/**
	 * Get attachment ID from URL with caching.
	 *
	 * @param string $url The image URL.
	 * @return int|false The attachment ID or false if not found.
	 */
	public static function get_attachment_id_from_url( string $url ): int|false {
		// Remove query parameters for caching key.
		$url_without_query = strtok( $url, '?' );
		$cache_key         = 'bunnify_attachment_id_' . md5( $url_without_query );

		// Try to get from cache first.
		$attachment_id = wp_cache_get( $cache_key, 'bunnify_frontend' );

		if ( false === $attachment_id ) {
			// Not in cache, query the database using our consolidated function.
			$attachment_id = self::attachment_url_to_postid( $url_without_query );

			// If not found and the URL contains dimensions, try stripping them and looking up again.
			if ( ! $attachment_id && preg_match( '#(-+\d+x\d+)\.#', $url_without_query ) ) {
				// Strip dimensions from the URL and try again.
				$stripped_url = preg_replace( '#(-+\d+x\d+)\.#', '.', $url_without_query );
				$attachment_id = self::attachment_url_to_postid( $stripped_url );

				// If still not found, try adding -scaled suffix (common WordPress pattern).
				if ( ! $attachment_id && ! strpos( $stripped_url, '-scaled.' ) ) {
					$scaled_url = str_replace( '.', '-scaled.', $stripped_url );
					$attachment_id = self::attachment_url_to_postid( $scaled_url );
				}
			}

			// Cache the result with appropriate TTL.
			$cache_ttl = self::get_attachment_cache_ttl( $attachment_id );
			wp_cache_set( $cache_key, $attachment_id ?: 'not_found', 'bunnify_frontend', $cache_ttl );
		} elseif ( 'not_found' === $attachment_id ) {
			$attachment_id = false;
		}

		return $attachment_id;
	}

	/**
	 * Get cached original URL for an attachment ID.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The original URL or false if not found.
	 */
	public static function get_cached_original_url( int $attachment_id ): string|false {
		$cache_key = 'bunnify_original_url_' . $attachment_id;

		// Try to get from cache first.
		$original_url = wp_cache_get( $cache_key, 'bunnify_frontend' );

		if ( false === $original_url ) {
			// Not in cache, get from WordPress.
			$original_url = wp_get_attachment_url( $attachment_id );

			// Cache the result with appropriate TTL.
			$cache_ttl = self::get_attachment_cache_ttl( $attachment_id );
			wp_cache_set( $cache_key, $original_url ?: 'not_found', 'bunnify_frontend', $cache_ttl );
		} elseif ( 'not_found' === $original_url ) {
			$original_url = false;
		}

		return $original_url;
	}

	/**
	 * Get cache TTL for attachment based on its age.
	 *
	 * @param int|false $attachment_id The attachment ID or false.
	 * @return int Cache TTL in seconds.
	 */
	public static function get_attachment_cache_ttl( int|false $attachment_id ): int {
		if ( ! $attachment_id ) {
			// 120 day cache for attachments not found.
			return 120 * DAY_IN_SECONDS;
		}

		$attachment = get_post( $attachment_id );

		// Bail early if attachment doesn't exist.
		if ( ! $attachment || ! $attachment->ID ) {
			return 7 * DAY_IN_SECONDS;
		}

		// Alter the cache based on published time of the attachment. The older it is, the less likely it will change.
		$attachment_published_date_str = strtotime( $attachment->post_date );

		if ( $attachment_published_date_str < strtotime( '-1 year' ) ) {
			// 120 day cache for very old attachments.
			return 120 * DAY_IN_SECONDS;
		} elseif ( $attachment_published_date_str < strtotime( '-4 month' ) ) {
			// 60 day cache for old attachments.
			return 60 * DAY_IN_SECONDS;
		} elseif ( $attachment_published_date_str < strtotime( '-1 month' ) ) {
			// 30 day cache for moderately old attachments.
			return 30 * DAY_IN_SECONDS;
		} else {
			// 7 day cache for recent attachments.
			return 7 * DAY_IN_SECONDS;
		}
	}

	/**
	 * Cached version of attachment_url_to_postid with smart TTL.
	 * This has been extended via a filter to allow for different cache times.
	 *
	 * @param string $url The image URL.
	 * @return false|int The attachment ID or false if not found.
	 */
	public static function attachment_url_to_postid( string $url ) {
		$cache_key = 'bunny_attachment_url_post_id_' . md5( $url );
		$id        = wp_cache_get( $cache_key, 'bunnify_frontend' );

		if ( false === $id ) {
			$id = \attachment_url_to_postid( $url ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.attachment_url_to_postid_attachment_url_to_postid

			// Use our smart TTL logic.
			$cache_ttl = self::get_attachment_cache_ttl( $id );
			$cache_ttl = $cache_ttl > MINUTE_IN_SECONDS * 5 ? $cache_ttl : MINUTE_IN_SECONDS * 5; // Minimum 5 minutes.

			if ( empty( $id ) ) {
				wp_cache_set( $cache_key, 'not_found', 'bunnify_frontend', $cache_ttl ); // phpcs:ignore
			} else {
				wp_cache_set( $cache_key, $id, 'bunnify_frontend', $cache_ttl ); // phpcs:ignore
			}
		} elseif ( 'not_found' === $id ) {
			return false;
		}

		return $id;
	}
}