<?php
/**
 * URL Transformer Library for Bunnify Frontend plugin.
 *
 * Contains core CDN URL transformation logic and utilities.
 *
 * File Path: src/php/Library/URLTransformer.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides CDN URL transformation and processing utilities.
 */

declare( strict_types=1 );

namespace BunnifyFrontend\Library;

use BunnifyFrontend\Base\Traits\DebugTrait;
use BunnifyFrontend\Base\Traits\CachingTrait;

/**
 * Class URLTransformer
 *
 * Handles core CDN URL transformation logic.
 */
class URLTransformer {
	use DebugTrait;
	use CachingTrait;

	/**
	 * Initialize the CDN library.
	 *
	 * @param string $bunnify_hostname The BunnyCDN hostname.
	 * @throws \InvalidArgumentException If hostname is invalid.
	 */
	public function __construct(
		private string $bunnify_hostname,
	) {
		// Validate hostname format.
		if ( empty( trim( $this->bunnify_hostname ) ) ) {
			throw new \InvalidArgumentException( 'BunnyCDN hostname cannot be empty.' );
		}

		// Basic hostname validation.
		if ( ! filter_var( "https://{$this->bunnify_hostname}", FILTER_VALIDATE_URL ) ) {
			throw new \InvalidArgumentException( 'Invalid BunnyCDN hostname format.' );
		}

		// Sanitize hostname.
		$this->bunnify_hostname = sanitize_text_field( trim( $this->bunnify_hostname ) );
	}

	/**
	 * Generates a Bunnify URL.
	 *
	 * @param string       $image_url URL to the publicly accessible image you want to manipulate.
	 * @param array|string $args An array of arguments, e.g. array( 'width' => '300', 'height' => '200' ), or in string form (w=123&h=456).
	 * @param string|null  $scheme URL protocol.
	 * @return string The raw final URL. You should run this through esc_url() before displaying it.
	 */
	public function transform_url(
		string $image_url,
		array|string $args = [],
		?string $scheme = null,
	): string {
		// Debug logging for CDN URL generation.
		$this->debug_log( 'transform_url called with: ' . $image_url . ' | args: ' . print_r( $args, true ) . ' | scheme: ' . $scheme, 'transform_url' );

		$image_url = trim( $image_url );

		// Bail early if disabled.
		if ( defined( 'BUNNIFY_DISABLE' ) && BUNNIFY_DISABLE ) {
			return $image_url;
		}

		// Allow specific image URLs to avoid going through Bunnify.
		if ( false !== apply_filters( 'bunnify_skip_for_url', false, $image_url, $args, $scheme ) ) {
			return $image_url;
		}

		// Filter the original image URL before processing. Cast to preserve the
		// string return contract under strict_types if a filter returns non-string.
		$image_url = (string) apply_filters( 'bunnify_pre_image_url', $image_url, $args, $scheme );

		// Filter the arguments before processing.
		$args = apply_filters( 'bunnify_pre_args', $args, $image_url, $scheme );

		// Check local development mode.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			if ( self::image_exists_locally( $image_url ) ) {
				$this->debug_log( 'Local development mode: image exists locally, bypassing all image processing', 'transform_url' );
				return $image_url;
			}
		}

		if ( empty( $image_url ) ) {
			return $image_url;
		}

		$image_url_parts = wp_parse_url( $image_url );

		// Unable to parse.
		if ( ! is_array( $image_url_parts ) || empty( $image_url_parts['host'] ) || empty( $image_url_parts['path'] ) ) {
			return $image_url;
		}

		// Check if this is a local WordPress upload.
		$upload_dir = wp_upload_dir();
		if ( ! $this->is_local_upload_url( $image_url_parts, $upload_dir ) ) {
			return $image_url;
		}

		// Build the CDN URL.
		$cdn_url = $this->build_cdn_url( $image_url_parts, $args, $scheme );

		// Filter the final CDN URL.
		$cdn_url = (string) apply_filters( 'bunnify_post_image_url', $cdn_url, $image_url, $args, $scheme );

		$this->debug_log( 'CDN URL generated: ' . $cdn_url, 'transform_url' );

		return $cdn_url;
	}

	/**
	 * Check if the URL is a local WordPress upload.
	 *
	 * @param array $url_parts Parsed URL parts.
	 * @param array $upload_dir WordPress upload directory info.
	 * @return bool True if local upload, false otherwise.
	 */
	private function is_local_upload_url( array $url_parts, array $upload_dir ): bool {
		// Check if the path is within the uploads directory.
		$upload_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
		if ( ! empty( $upload_path ) && strpos( $url_parts['path'], $upload_path ) === 0 ) {
			return true;
		}

		// Also check if the path contains wp-content/uploads (for cases where URLs are stored with different hosts).
		if ( strpos( $url_parts['path'], '/wp-content/uploads/' ) !== false ) {
			return true;
		}

		/**
		 * Allow opt-in processing of non-upload local asset URLs.
		 *
		 * Default behavior remains uploads-only unless a project explicitly
		 * enables additional paths via this filter.
		 *
		 * @param bool  $allow      Whether to allow this non-upload URL.
		 * @param array $url_parts  Parsed URL parts for the candidate URL.
		 * @param array $upload_dir WordPress upload directory data.
		 */
		if ( true === apply_filters( 'bunnify_allow_non_upload_url', false, $url_parts, $upload_dir ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build the CDN URL from parsed URL parts.
	 *
	 * @param array       $url_parts Parsed URL parts.
	 * @param array       $args CDN arguments.
	 * @param string|null $scheme URL scheme.
	 * @return string The CDN URL.
	 */
	private function build_cdn_url( array $url_parts, array $args, ?string $scheme ): string {
		// Build the CDN URL using the full path (like the original plugin).
		// This ensures BunnyCDN can pull from the correct path structure.
		$cdn_url = $this->cdn_url_scheme( $this->bunnify_hostname, $scheme ) . $url_parts['path'];

		// Add query parameters if provided.
		if ( ! empty( $args ) ) {
			$query_string = $this->build_query_string( $args );
			if ( ! empty( $query_string ) ) {
				$cdn_url .= '?' . $query_string;
			}
		}

		return $cdn_url;
	}

	/**
	 * Build query string from arguments.
	 *
	 * @param array|string $args Arguments array or string.
	 * @return string Query string.
	 */
	private function build_query_string( array|string $args ): string {
		if ( is_string( $args ) ) {
			return $args;
		}

		if ( ! is_array( $args ) ) {
			return '';
		}

		$query_parts = [];

		// Map core WordPress image size arguments first.
		if ( isset( $args['width'] ) ) {
			$query_parts['width'] = (int) $args['width'];
		}
		if ( isset( $args['height'] ) ) {
			$query_parts['height'] = (int) $args['height'];
		}
		if ( isset( $args['crop'] ) && $args['crop'] ) {
			$query_parts['c'] = 1;
		}

		/**
		 * Pass through additional Bunny transform arguments.
		 *
		 * This keeps backwards compatibility for width/height/crop while allowing
		 * quality/format and any other known scalar transform args to reach the CDN.
		 * The core keys are handled by the mapping above and never passed through
		 * raw — `crop` in particular maps to `c`, so re-adding it here would emit
		 * both `c=1` and `crop=1`.
		 */
		$mapped_core_keys = [ 'width', 'height', 'crop' ];
		foreach ( $args as $key => $value ) {
			$key = is_string( $key ) ? trim( $key ) : '';
			if ( '' === $key || in_array( $key, $mapped_core_keys, true ) || isset( $query_parts[ $key ] ) ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$query_parts[ $key ] = $value ? '1' : '0';
				continue;
			}

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$query_parts[ $key ] = (string) $value;
			}
		}

		return http_build_query( $query_parts );
	}

	/**
	 * Apply URL scheme to hostname.
	 *
	 * @param string      $url The URL to apply scheme to.
	 * @param string|null $scheme The scheme to apply.
	 * @return string URL with scheme applied.
	 */
	private function cdn_url_scheme( string $url, ?string $scheme ): string {
		if ( null === $scheme ) {
			$scheme = is_ssl() ? 'https' : 'http';
		}

		// Ensure the URL has the correct scheme.
		$url_parts = wp_parse_url( $url );
		if ( ! isset( $url_parts['scheme'] ) ) {
			$url = $scheme . '://' . $url;
		} else {
			$url = str_replace( $url_parts['scheme'], $scheme, $url );
		}

		return $url;
	}

	/**
	 * Get attachment ID from URL by delegating to ImageProcessor.
	 *
	 * @param string $url The image URL.
	 * @return int|false The attachment ID or false if not found.
	 */
	public static function get_attachment_id_from_url( string $url ): int|false {
		return \BunnifyFrontend\Library\ImageProcessor::get_attachment_id_from_url( $url );
	}

	/**
	 * Get cached original URL for an attachment ID by delegating to ImageProcessor.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return string|false The original URL or false if not found.
	 */
	public static function get_cached_original_url( int $attachment_id ): string|false {
		return \BunnifyFrontend\Library\ImageProcessor::get_cached_original_url( $attachment_id );
	}

	/**
	 * Get cache TTL for attachment by delegating to ImageProcessor.
	 *
	 * @param int|false $attachment_id The attachment ID or false.
	 * @return int Cache TTL in seconds.
	 */
	private static function get_attachment_cache_ttl( int|false $attachment_id ): int {
		return \BunnifyFrontend\Library\ImageProcessor::get_attachment_cache_ttl( $attachment_id );
	}

	/**
	 * Check if an image exists locally on the filesystem.
	 *
	 * @param string $url The image URL to check.
	 * @return bool True if the image exists locally, false otherwise.
	 */
	public static function image_exists_locally( string $url ): bool {
		// Parse the URL to get the local file path.
		$upload_dir  = wp_upload_dir();
		$upload_url  = $upload_dir['baseurl'];
		$upload_path = $upload_dir['basedir'];

		// Parse the URL to extract the path.
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['path'] ) ) {
			return false;
		}

		$url_path = $url_parts['path'];

		// Check if the path contains wp-content/uploads (most reliable method).
		$uploads_pos = strpos( $url_path, '/wp-content/uploads/' );
		if ( false === $uploads_pos ) {
			return false;
		}

		// Extract the relative path from wp-content/uploads onwards.
		$relative_path = substr( $url_path, $uploads_pos + strlen( '/wp-content/uploads/' ) );
		$local_path    = $upload_path . '/' . $relative_path;

		// Check if the file exists and is readable.
		$file_exists = file_exists( $local_path ) && is_readable( $local_path );

		// Debug logging if enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'Bunnify Local Dev Mode Debug - URL: %s, Local Path: %s, Exists: %s',
					$url,
					$local_path,
					$file_exists ? 'true' : 'false'
				)
			);
		}

		return $file_exists;
	}

	/**
	 * Validates if a URL is a valid image URL that should be processed.
	 *
	 * @param string $url The URL to validate.
	 * @return bool True if the URL should be processed, false otherwise.
	 */
	public static function validate_image_url( string $url ): bool {
		// Check if URL is empty.
		if ( empty( $url ) ) {
			return false;
		}

		// Parse the URL.
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || empty( $url_parts['path'] ) ) {
			return false;
		}

		// Check if it's already a CDN URL.
		if ( self::is_cdn_url( $url ) ) {
			return false;
		}

		// Check file extension.
		$extension          = pathinfo( $url_parts['path'], PATHINFO_EXTENSION );
		$allowed_extensions = [ 'gif', 'jpg', 'jpeg', 'png', 'webp', 'heic' ];

		if ( empty( $extension ) || ! in_array( strtolower( $extension ), $allowed_extensions, true ) ) {
			// Allow specific extensions to be processed if filter allows it.
			if ( ! apply_filters( 'bunnify_any_extension_for_domain', false, $url_parts['host'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks if a URL is already a CDN URL.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL is already a CDN URL, false otherwise.
	 */
	public static function is_cdn_url( string $url ): bool {
		// Check if URL is empty.
		if ( empty( $url ) ) {
			return false;
		}

		// Parse the URL.
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) ) {
			return false;
		}

		// Get the configured CDN hostname.
		$bunnify_hostname = get_option( 'bunnify_hostname' );
		if ( empty( $bunnify_hostname ) ) {
			return false;
		}

		// Check for exact hostname match.
		if ( $url_parts['host'] === $bunnify_hostname ) {
			return true;
		}

		// Check for CDN query parameters (width, height, etc.).
		// This indicates the URL has already been processed by the CDN.
		if ( ! empty( $url_parts['query'] ) ) {
			parse_str( $url_parts['query'], $query_params );
			$cdn_params = [ 'width', 'height', 'quality', 'format', 'crop' ];

			foreach ( $cdn_params as $param ) {
				if ( isset( $query_params[ $param ] ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Static CDN Library instance for static methods.
	 *
	 * @var URLTransformer|null
	 */
	private static ?URLTransformer $static_instance = null;

	/**
	 * Static BunnyCDN hostname for static methods.
	 *
	 * @var string|null
	 */
	private static ?string $static_hostname = null;

	/**
	 * Initialize static CDN functionality.
	 *
	 * @return bool True if CDN is properly initialized, false otherwise.
	 */
	private static function init_static_cdn(): bool {
		if ( null !== self::$static_instance ) {
			return true;
		}

		// Master switch — an explicitly disabled bunnify_enabled stops all rewriting.
		if ( ! \BunnifyFrontend\Controller\SettingsController::is_enabled() ) {
			return false;
		}

		self::$static_hostname = get_option( 'bunnify_hostname' );

		// Only proceed if hostname is configured.
		if ( empty( self::$static_hostname ) ) {
			return false;
		}

		self::$static_instance = new self( self::$static_hostname );
		return true;
	}

	/**
	 * Get CDN URL by attachment ID without dimension stripping.
	 *
	 * @param int          $attachment_id The attachment ID.
	 * @param array|string $args CDN arguments.
	 * @param string|null  $scheme URL scheme.
	 * @return string|null The CDN URL or null if not found.
	 */
	public static function get_cdn_url_by_id( int $attachment_id, array|string $args = [], ?string $scheme = null ): ?string {
		$original_url = wp_get_attachment_url( $attachment_id );
		if ( empty( $original_url ) ) {
			return null;
		}

		if ( ! self::init_static_cdn() ) {
			return $original_url;
		}

		// Get the true original image URL by stripping any suffixes.
		$true_original_url = self::get_true_original_url( $attachment_id, $original_url );

		// Build CDN URL directly without dimension stripping to preserve original filenames.
		return self::$static_instance->build_cdn_url_from_attachment( $true_original_url, $args, $scheme ) ?? $original_url;
	}

	/**
	 * Get the true original URL from attachment metadata with caching.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $original_url The original URL from WordPress.
	 * @return string The true original URL.
	 */
	private static function get_true_original_url( int $attachment_id, string $original_url ): string {
		// Cache key for this specific attachment's true original URL.
		$cache_key = "bunnify_true_original_url_{$attachment_id}";

		// Try to get from cache first.
		$cached_url = wp_cache_get( $cache_key, 'bunnify_frontend' );
		if ( false !== $cached_url ) {
			if ( 'not_found' === $cached_url ) {
				return $original_url;
			}
			return $cached_url;
		}

		// Get the attachment metadata to find the true original file.
		$attachment_meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $attachment_meta['file'] ) ) {
			$upload_dir        = wp_get_upload_dir();
			$true_original_url = $upload_dir['baseurl'] . '/' . $attachment_meta['file'];

			// Check if the metadata file contains "-scaled" and if a non-scaled version exists.
			if ( strpos( $attachment_meta['file'], '-scaled.' ) !== false ) {
				// For local development, check if we're in local dev mode first.
				$is_local_dev = \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled();

				if ( $is_local_dev ) {
					// In local dev, prefer the metadata version (which might be -scaled)
					// since the original might not be available locally.
					$true_original_url = $upload_dir['baseurl'] . '/' . $attachment_meta['file'];
				} else {
					// In production, check if non-scaled version exists on filesystem.
					$non_scaled_file = str_replace( '-scaled.', '.', $attachment_meta['file'] );
					$non_scaled_path = $upload_dir['basedir'] . '/' . $non_scaled_file;

					// If the non-scaled version exists, use that instead.
					if ( file_exists( $non_scaled_path ) ) {
						$true_original_url = $upload_dir['baseurl'] . '/' . $non_scaled_file;
					}
				}
			}

			// Cache the result with appropriate TTL.
			$cache_ttl = \BunnifyFrontend\Library\ImageProcessor::get_attachment_cache_ttl( $attachment_id );
			wp_cache_set( $cache_key, $true_original_url, 'bunnify_frontend', $cache_ttl );

			return $true_original_url;
		}

		// Cache negative result.
		$cache_ttl = \BunnifyFrontend\Library\ImageProcessor::get_attachment_cache_ttl( false );
		wp_cache_set( $cache_key, 'not_found', 'bunnify_frontend', $cache_ttl );

		// Fallback to the original URL if metadata is not available.
		return $original_url;
	}

	/**
	 * Build CDN URL from attachment URL without dimension stripping.
	 *
	 * @param string       $original_url The original attachment URL.
	 * @param array|string $args CDN arguments.
	 * @param string|null  $scheme URL scheme.
	 * @return string|null The CDN URL or null on failure.
	 */
	private function build_cdn_url_from_attachment( string $original_url, array|string $args = [], ?string $scheme = null ): ?string {
		// Parse the original URL to get the path.
		$url_parts = wp_parse_url( $original_url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || empty( $url_parts['path'] ) ) {
			return null;
		}

		// Check if this is a local WordPress upload.
		$upload_dir  = wp_upload_dir();
		$upload_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

		// Only transform if it's a local upload.
		if ( ! empty( $upload_path ) && strpos( $url_parts['path'], $upload_path ) === 0 ) {
			// Build CDN URL using the original path (preserving -scaled, -full, etc.).
			$cdn_url = $this->cdn_url_scheme( $this->bunnify_hostname, $scheme ) . $url_parts['path'];

			// Add query parameters if provided.
			if ( ! empty( $args ) ) {
				$query_string = $this->build_query_string( $args );
				if ( ! empty( $query_string ) ) {
					$cdn_url .= '?' . $query_string;
				}
			}

			return $cdn_url;
		}

		return null;
	}
}
