<?php
/**
 * Image processing controller for Bunnify Frontend plugin.
 *
 * Handles image-specific filters including downsize, attachment processing, and srcset generation.
 *
 * File Path: src/php/Controller/ImageController.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides image processing and transformation logic.
 */

namespace BunnifyFrontend\Controller;

use WP_HTML_Tag_Processor;
use BunnifyFrontend\Base\Main\Controller;
use BunnifyFrontend\Base\Traits\DebugTrait;
use BunnifyFrontend\Base\Traits\CachingTrait;
use BunnifyFrontend\Library\URLTransformer;

/**
 * ImageController class to handle CDN transformation of WordPress images.
 *
 * File Path: wp-content/plugins/bunnify-frontend/src/php/Controller/ImageController.php
 *
 * @package BunnifyFrontend\Controller
 * @since   1.0.0
 *
 * Handles the transformation of WordPress image URLs to CDN URLs, ensuring proper
 * srcset and sizes attributes for responsive images. Supports both registered
 * WordPress image sizes and extensible custom size patterns.
 *
 * ## Image Processing Flow
 *
 * ### 1. WordPress Image Generation
 * When `wp_get_attachment_image()` is called, WordPress follows this flow:
 * ```
 * wp_get_attachment_image()
 *   ↓
 * wp_get_attachment_image_src() → filter_image_downsize() → CDN URL
 *   ↓
 * wp_calculate_image_srcset() → filter_srcset_array() → CDN srcset URLs
 *   ↓
 * wp_calculate_image_sizes() → filter_sizes() → sizes attribute
 *   ↓
 * wp_calculate_image_srcset_meta() → filter_srcset_meta() → metadata
 *   ↓
 * Final HTML generation
 * ```
 *
 * ### 2. HTML Post-Processing
 * After WordPress generates the HTML, our filters intercept:
 * ```
 * filter_attachment_image() intercepts final HTML
 *   ↓
 * Check: Does HTML contain srcset attribute?
 *   ├─ YES → Transform existing srcset URLs to CDN
 *   └─ NO  → generate_srcset_and_sizes() creates new CDN srcset
 *   ↓
 * Return modified HTML with CDN URLs
 * ```
 *
 * ## Filters and Their Purposes
 *
 * ### Core Image Filters
 * - **`image_downsize`** → `filter_image_downsize()`
 *   - Transforms single image URLs to CDN URLs
 *   - Handles custom size parsing with extensible patterns
 *   - Returns CDN URL with proper dimensions
 *
 * - **`wp_get_attachment_image_src`** → `filter_attachment_img_srcs()`
 *   - Alternative entry point for image URL transformation
 *   - Ensures consistency across different WordPress functions
 *
 * - **`wp_get_attachment_image`** → `filter_attachment_image()`
 *   - Post-processes final HTML output
 *   - Transforms existing srcset URLs or generates new ones
 *   - Ensures all image attributes use CDN URLs
 *
 * ### Srcset and Responsive Image Filters
 * - **`wp_calculate_image_srcset`** → `filter_srcset_array()`
 *   - Transforms WordPress-generated srcset sources to CDN URLs
 *   - Handles registered image sizes (thumbnail, medium, large, etc.)
 *   - Maintains proper width/height descriptors
 *
 * - **`wp_calculate_image_sizes`** → `filter_sizes()`
 *   - Processes the sizes attribute for responsive behavior
 *   - Currently returns original sizes (can be enhanced for CDN optimization)
 *
 * - **`wp_calculate_image_srcset_meta`** → `filter_srcset_meta()`
 *   - Ensures image metadata is available for srcset generation
 *   - Critical for custom sizes that WordPress doesn't handle natively
 *
 * ## Supported Image Size Types
 *
 * ### 1. Registered WordPress Sizes
 * - 'thumbnail', 'medium', 'large', 'full'
 * - WordPress generates srcset automatically
 * - `filter_srcset_array()` transforms URLs to CDN
 *
 * ### 2. Registered WordPress Image Sizes
 *
 * **Examples**: 'thumbnail', 'medium', 'large', 'single-featured', 'hero-image'
 *
 * **Processing Flow**:
 * - WordPress generates srcset automatically
 * - `filter_srcset_array()` transforms URLs to CDN
 * - Result: Perfect CDN srcset with responsive sources
 *
 * ### 3. Array Dimensions
 * - [768, 432], [1024, 768]
 * - Handled by `filter_image_downsize()`
 * - CDN URLs generated with exact dimensions
 *
 * ## CDN URL Transformation
 *
 * ### URL Structure
 * ```
 * Original: https://site.com/wp-content/uploads/image.jpg
 * CDN:      https://cdn.site.com/wp-content/uploads/image.jpg?width=768&height=432
 * ```
 *
 * ### Dimension Calculation
 * - **Width/Height provided**: Use exact dimensions
 * - **Aspect ratio only**: Calculate height from original image metadata
 * - **Registered sizes**: Look up dimensions from WordPress registered image sizes
 *
 * ## Debug and Logging
 *
 * Comprehensive debug logging is available via `uploads/bunnify-logs/debug.log`:
 * - Image processing steps and decisions
 * - URL transformations and CDN generation
 * - Srcset generation and modification
 * - Error conditions and fallbacks
 *
 * ## Local Development Mode
 *
 * When local development mode is enabled:
 * - CDN transformation is bypassed for local images
 * - Original WordPress URLs are preserved
 * - Debug logging shows local file detection
 *
 * ## Security and Validation
 *
 * - All URLs are validated before transformation
 * - Input sanitization prevents XSS vulnerabilities
 * - Error handling ensures graceful fallbacks
 * - Nonce validation for admin operations
 */
class ImageController extends Controller {
	use DebugTrait;
	use CachingTrait;

	/**
	 * Initialize WordPress hooks for image processing.
	 */
	public function set_up() {
		// Core image retrieval.
		add_filter( 'image_downsize', [ $this, 'filter_image_downsize' ], 10, 3 );

		/**
		 * Allow Bunnify to replace all attachment image srcs.
		 *
		 * @param bool false Should Bunnify enable attachment src replacement. Default to false.
		 */
		if ( apply_filters( 'bunnify_replace_attachment_srcs', true ) ) {
			add_filter( 'wp_get_attachment_image_src', [ $this, 'filter_attachment_img_srcs' ], 10, 4 );
		}

		// Filter wp_get_attachment_image to ensure all img tags use original images.
		add_filter( 'wp_get_attachment_image', [ $this, 'filter_attachment_image' ], 10, 5 );

		// Media library / editor picker data (the wp.media modal builds its size
		// URLs from wp_get_attachment_url() + the stored filenames, not through
		// image_downsize, so the other filters never see it).
		add_filter( 'wp_prepare_attachment_for_js', [ $this, 'filter_attachment_for_js' ], 10, 3 );

		// Bare attachment URLs (ACF URL fields, theme templates, REST source_url,
		// block bindings) are emitted by wp_get_attachment_url() — outside the
		// <img>/resize pipeline the other filters cover. Guarded against
		// re-entrancy because the plugin calls wp_get_attachment_url() internally
		// (through AttachmentUrl::origin()) to obtain the origin URL.
		add_filter( 'wp_get_attachment_url', [ $this, 'filter_attachment_url' ], 10, 2 );

		// Classic-theme custom header image: get_header_image() / the
		// theme_mod_header_image theme mod return a stored origin URL, not routed
		// through any image function. Block themes use core/site-logo (covered).
		add_filter( 'theme_mod_header_image', [ $this, 'filter_header_image_url' ], 10 );
		add_filter( 'get_header_image', [ $this, 'filter_header_image_url' ], 10 );

		// Responsive image srcset substitution with proper order.
		add_filter( 'wp_calculate_image_srcset_meta', [ $this, 'filter_srcset_meta' ], 5, 4 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'filter_srcset_array' ], 10, 5 );
		add_filter( 'wp_calculate_image_sizes', [ $this, 'filter_sizes' ], 15, 2 );
	}

	/**
	 * Whether the admin guard should block processing for the current request.
	 *
	 * Admin surfaces (the media library, editor previews) normally keep origin
	 * URLs. Local-dev mode is the deliberate exception: it means "serve local
	 * files when present, else fall back to the CDN", and the per-image
	 * {@see URLTransformer::image_exists_locally()} check downstream applies
	 * that just as usefully in admin — a local install without synced uploads
	 * otherwise shows blank media-library thumbnails (the origin files 404).
	 * So in local-dev mode we do not block admin wholesale; the exists-locally
	 * check decides per image.
	 *
	 * Kept separate from the `bunnify_admin_allow_*` filter so callers can still
	 * short-circuit that filter (it must only fire in admin context).
	 *
	 * @return bool True when the request is in admin and local-dev mode is off.
	 */
	private function is_admin_without_local_dev(): bool {
		return is_admin() && ! \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled();
	}

	/**
	 * Filter image downsize to process custom sizes and transform to CDN.
	 *
	 * This filter processes image size requests and transforms URLs to CDN
	 * while preserving proper dimensions for custom sizes.
	 *
	 * @param array|false  $image Array of image data, or boolean false if no image.
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|array $size Requested size.
	 * @return array|false Modified image data or false.
	 */
	public function filter_image_downsize( array|false $image, int $attachment_id, string|array $size ): array|false {
		// Debug logging for image downsize processing.
		$this->debug_log( "filter_image_downsize called with attachment_id: {$attachment_id}, size: " . ( is_array( $size ) ? '[' . implode( ',', $size ) . ']' : $size ), 'filter_image_downsize' );

		// Don't process in admin unless specifically allowed. The media library
		// / editor picker is handled separately by filter_attachment_for_js
		// (on wp_prepare_attachment_for_js) — rewriting here instead breaks it,
		// because wp_prepare_attachment_for_js drops any size whose image_downsize
		// result is not an intermediate, and this filter always returns one.
		if ( is_admin() && false === apply_filters( 'bunnify_admin_allow_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) ) ) {
			return $image;
		}

		// Allow plugins to prevent Bunnify processing.
		if ( apply_filters( 'bunnify_override_image_downsize', false, compact( 'image', 'attachment_id', 'size' ) ) ) {
			return $image;
		}

		// Get the original image URL.
		$original_url = \BunnifyFrontend\Library\AttachmentUrl::origin( $attachment_id );
		if ( empty( $original_url ) ) {
			return $image;
		}

		// Check local development mode.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			if ( URLTransformer::image_exists_locally( $original_url ) ) {
				// Return original image array without any modifications.
				return $image;
			}
		}

		// Validate the image URL.
		if ( ! URLTransformer::validate_image_url( $original_url ) ) {
			return $image;
		}

		// Get image sizes for dimension calculation.
		$image_sizes = \BunnifyFrontend\Library\ImageProcessor::get_image_sizes();

		// Handle string sizes (e.g., 'thumbnail', 'medium', '16:9-768').
		if ( is_string( $size ) || is_int( $size ) ) {
			// Resolve width/height from a registered size or a custom size string.
			$dimensions = $this->resolve_size_dimensions( $size, $image_sizes );
			$width      = $dimensions['width'];
			$height     = $dimensions['height'];

			// Build CDN arguments.
			$cdn_args = [];

			if ( $width ) {
				$cdn_args['width'] = $width;
			}

			if ( $height ) {
				$cdn_args['height'] = $height;
			} else {
				// Calculate height based on aspect ratio if we have image metadata.
				$image_meta = wp_get_attachment_metadata( $attachment_id );
				if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] ) && $width ) {
					$aspect_ratio       = $image_meta['height'] / $image_meta['width'];
					$calculated_height  = round( $width * $aspect_ratio );
					$cdn_args['height'] = $calculated_height;
					$height             = $calculated_height; // Update height for the return array
				}
			}

			// Filter arguments before processing.
			$cdn_args = apply_filters( 'bunnify_image_downsize_string', $cdn_args, compact( 'original_url', 'attachment_id', 'size' ) );

			// Generate CDN URL using original image with dimensions.
			$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
			if ( empty( $cdn_url ) ) {
				return $image;
			}

			// Return new image array with CDN URL and dimensions.
			return [
				$cdn_url,
				$width,
				$height,
				false, // Always false since we're using original image
			];
		} else {
			// Handle array sizes (e.g., [300, 200]).
			$width  = is_array( $size ) ? $size[0] : false;
			$height = is_array( $size ) ? $size[1] : false;

			$cdn_args = [];

			if ( $width ) {
				$cdn_args['width'] = $width;
			}

			if ( $height ) {
				$cdn_args['height'] = $height;
			} else {
				// Calculate height based on aspect ratio if we have image metadata.
				$image_meta = wp_get_attachment_metadata( $attachment_id );
				if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] ) && $width ) {
					$aspect_ratio       = $image_meta['height'] / $image_meta['width'];
					$calculated_height  = round( $width * $aspect_ratio );
					$cdn_args['height'] = $calculated_height;
					$height             = $calculated_height; // Update height for the return array
				}
			}

			// Filter arguments before processing.
			$cdn_args = apply_filters( 'bunnify_image_downsize_array', $cdn_args, compact( 'original_url', 'attachment_id', 'size' ) );

			// Generate CDN URL using original image with dimensions.
			$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
			if ( empty( $cdn_url ) ) {
				return $image;
			}

			// Return new image array with CDN URL and dimensions.
			return [
				$cdn_url,
				$width,
				$height,
				false, // Always false since we're using original image
			];
		}
	}

	/**
	 * Filter attachment image srcs to process custom sizes and transform to CDN.
	 *
	 * This filter processes attachment image sources and transforms URLs to CDN
	 * while preserving proper dimensions for custom sizes.
	 *
	 * @param array|false  $image Array of image data, or boolean false if no image.
	 * @param int          $attachment_id Image attachment ID.
	 * @param string|array $size Requested size.
	 * @param bool         $icon Whether the image should be treated as an icon.
	 * @return array|false Modified image data or false.
	 */
	public function filter_attachment_img_srcs( array|false $image, int $attachment_id, string|array $size, bool $icon ): array|false {
		// Don't process in admin unless specifically allowed. (Admin picker data
		// is rewritten by filter_attachment_for_js — see filter_image_downsize.)
		if ( is_admin() && false === apply_filters( 'bunnify_admin_allow_attachment_srcs', false, compact( 'image', 'attachment_id', 'size', 'icon' ) ) ) {
			return $image;
		}

		// Allow plugins to prevent Bunnify processing.
		if ( apply_filters( 'bunnify_override_attachment_srcs', false, compact( 'image', 'attachment_id', 'size', 'icon' ) ) ) {
			return $image;
		}

		if ( false === $image ) {
			return $image;
		}

		// Get the original image URL.
		$original_url = \BunnifyFrontend\Library\AttachmentUrl::origin( $attachment_id );
		if ( empty( $original_url ) ) {
			return $image;
		}

		// Check local development mode.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			if ( URLTransformer::image_exists_locally( $original_url ) ) {
				// Return original image array without any modifications.
				return $image;
			}
		}

		// Validate the image URL.
		if ( ! URLTransformer::validate_image_url( $original_url ) ) {
			return $image;
		}

		// Get image sizes for dimension calculation.
		$image_sizes = \BunnifyFrontend\Library\ImageProcessor::get_image_sizes();

		// Handle string sizes (e.g., 'thumbnail', 'medium', '16:9-768').
		if ( is_string( $size ) || is_int( $size ) ) {
			// Resolve width/height from a registered size or a custom size string.
			$dimensions = $this->resolve_size_dimensions( $size, $image_sizes );
			$width      = $dimensions['width'];
			$height     = $dimensions['height'];

			// Build CDN arguments.
			$cdn_args = [];

			if ( $width ) {
				$cdn_args['width'] = $width;
			}

			if ( $height ) {
				$cdn_args['height'] = $height;
			} else {
				// Calculate height based on aspect ratio if we have image metadata.
				$image_meta = $this->get_cached_attachment_metadata( $attachment_id );
				if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] ) && $width ) {
					$aspect_ratio       = $image_meta['height'] / $image_meta['width'];
					$calculated_height  = round( $width * $aspect_ratio );
					$cdn_args['height'] = $calculated_height;
					$height             = $calculated_height; // Update height for the return array
				}
			}

			// Generate CDN URL using original image with dimensions.
			$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
			if ( empty( $cdn_url ) ) {
				return $image;
			}

			// Update the image array with CDN URL and ensure dimensions are set.
			$image[0] = $cdn_url;

			// Always set the dimensions from our calculated values, not from the original image.
			// This ensures the img tag gets the correct width/height attributes.
			$image[1] = $width ?: ( isset( $image[1] ) ? $image[1] : false );
			$image[2] = $height ?: ( isset( $image[2] ) ? $image[2] : false );

			return $image;
		} else {
			// Handle array sizes (e.g., [300, 200]).
			$width  = is_array( $size ) ? $size[0] : false;
			$height = is_array( $size ) ? $size[1] : false;

			$cdn_args = [];

			if ( $width ) {
				$cdn_args['width'] = $width;
			}

			if ( $height ) {
				$cdn_args['height'] = $height;
			} else {
				// Calculate height based on aspect ratio if we have image metadata.
				$image_meta = $this->get_cached_attachment_metadata( $attachment_id );
				if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] ) && $width ) {
					$aspect_ratio       = $image_meta['height'] / $image_meta['width'];
					$calculated_height  = round( $width * $aspect_ratio );
					$cdn_args['height'] = $calculated_height;
					$height             = $calculated_height; // Update height for the return array
				}
			}

			// Generate CDN URL using original image with dimensions.
			$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
			if ( empty( $cdn_url ) ) {
				return $image;
			}

			// Update the image array with CDN URL and ensure dimensions are set.
			$image[0] = $cdn_url;

			// Always set the dimensions from our calculated values, not from the original image.
			// This ensures the img tag gets the correct width/height attributes.
			$image[1] = $width ?: ( isset( $image[1] ) ? $image[1] : false );
			$image[2] = $height ?: ( isset( $image[2] ) ? $image[2] : false );

			return $image;
		}
	}

	/**
	 * Filter attachment image HTML to transform URLs to CDN while preserving dimensions.
	 *
	 * This is the final stage where we transform URLs to CDN while maintaining
	 * all the proper dimensions and responsive image attributes that WordPress
	 * has generated.
	 *
	 * @param string       $html The image HTML.
	 * @param int          $attachment_id The attachment ID.
	 * @param string|array $size The requested size.
	 * @param bool         $icon Whether the image should be treated as an icon.
	 * @param array        $attr Array of attributes.
	 * @return string Modified HTML.
	 */
	public function filter_attachment_image( string $html, int $attachment_id, string|array $size, bool $icon, array $attr ): string {
		// Signal that we're processing an attachment image to prevent double processing.
		do_action( 'bunnify_processing_attachment_image', $attachment_id, $size, $html );

		// Get the original image URL (not the resized one).
		$original_url = \BunnifyFrontend\Library\AttachmentUrl::origin( $attachment_id );
		if ( empty( $original_url ) ) {
			return $html;
		}

		// Check local development mode.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			if ( URLTransformer::image_exists_locally( $original_url ) ) {
				// Return original HTML without any modifications.
				return $html;
			}
		}

		// Get the WordPress image data to preserve dimensions.
		$image_data = wp_get_attachment_image_src( $attachment_id, $size );
		if ( false === $image_data ) {
			return $html;
		}

		// Extract dimensions from the WordPress image data.
		$width  = $image_data[1];
		$height = $image_data[2];

		// Get original image metadata to calculate proper aspect ratio for content images.
		$image_meta      = $this->get_cached_attachment_metadata( $attachment_id );
		$original_width  = $image_meta['width'] ?? null;
		$original_height = $image_meta['height'] ?? null;

		// Build CDN arguments based on the WordPress image dimensions.
		$cdn_args = [];
		if ( $width ) {
			$cdn_args['width'] = $width;

			// For content images, calculate proper height to maintain aspect ratio.
			$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, $width, $height );
			$cdn_args['height'] = $dimensions['height'];
		} elseif ( $height ) {
			// If only height is provided, calculate width from aspect ratio.
			$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, 0, $height );
			$cdn_args['width']  = $dimensions['width'];
			$cdn_args['height'] = $height;
		}

		// Generate CDN URL using original image with proper dimensions.
		$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
		if ( empty( $cdn_url ) ) {
			return $html;
		}

		// Use WP_HTML_Tag_Processor to safely modify the HTML.
		$processor = new WP_HTML_Tag_Processor( $html );

		// Find the img tag and modify its attributes.
		if ( $processor->next_tag( 'img' ) ) {
			// Update the src attribute with CDN URL.
			$processor->set_attribute( 'src', $cdn_url );

			// Ensure width and height attributes are set correctly.
			if ( $width ) {
				$processor->set_attribute( 'width', $width );
			}
			if ( $height ) {
				$processor->set_attribute( 'height', $height );
			}

			// Handle srcset attribute if it exists.
			$srcset_value = $processor->get_attribute( 'srcset' );
			if ( $srcset_value ) {
				// Transform srcset URLs to CDN while preserving dimensions.
				$srcset_parts     = explode( ',', $srcset_value );
				$new_srcset_parts = [];

				foreach ( $srcset_parts as $part ) {
					$part = trim( $part );
					if ( empty( $part ) ) {
						continue;
					}

					// Split the srcset part into URL and descriptor (e.g., "url.jpg 300w").
					if ( preg_match( '/^([^\s]+)\s*(.*)$/', $part, $part_matches ) ) {
						$url        = trim( $part_matches[1] );
						$descriptor = trim( $part_matches[2] );

						// Skip if already a CDN URL to prevent double-processing.
						if ( $this->is_cdn_url( $url ) ) {
							$new_srcset_parts[] = $part;
							continue;
						}

						// Check if this is a local URL that should be transformed.
						if ( URLTransformer::validate_image_url( $url ) ) {
							// Get the attachment ID from the URL.
							$url_attachment_id = \BunnifyFrontend\Library\ImageProcessor::get_attachment_id_from_url( $url );

							if ( $url_attachment_id === $attachment_id ) {
								// Extract dimensions from descriptor for this specific srcset entry.
								$srcset_width = null;
								if ( preg_match( '/(\d+)w/', $descriptor, $width_matches ) ) {
									$srcset_width = (int) $width_matches[1];
								}

								// Build CDN arguments for this specific srcset entry.
								$srcset_cdn_args = [];
								if ( $srcset_width ) {
									$srcset_cdn_args['width'] = $srcset_width;

									// Calculate height based on aspect ratio.
									$dimensions                = $this->calculate_aspect_ratio_dimensions( $attachment_id, $srcset_width, 0 );
									$srcset_cdn_args['height'] = $dimensions['height'];
								}

								// Generate CDN URL for this srcset entry using original image.
								$srcset_cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $srcset_cdn_args );
								$new_url        = $srcset_cdn_url ?: $url;
							} else {
								// Different attachment, get its CDN URL.
								$new_url = URLTransformer::get_cdn_url_by_id( $url_attachment_id ) ?: $url;
							}

							$new_srcset_parts[] = $new_url . ( ! empty( $descriptor ) ? " {$descriptor}" : '' );
						} else {
							// Keep the original part if it's not a local URL.
							$new_srcset_parts[] = $part;
						}
					} else {
						// Keep the original part if it doesn't match the expected format.
						$new_srcset_parts[] = $part;
					}
				}

				// Update the srcset attribute with the new value.
				$new_srcset_value = implode( ', ', $new_srcset_parts );
				$processor->set_attribute( 'srcset', $new_srcset_value );
			} else {
				// Generate srcset and sizes if they don't exist.
				$this->debug_log( "No srcset found for attachment {$attachment_id}, generating srcset and sizes", 'filter_attachment_image' );
				$this->generate_srcset_and_sizes( $processor, $attachment_id, $size, $cdn_url );
			}

			// Get the modified HTML.
			$html = $processor->get_updated_html();
		}

		return $html;
	}

	/**
	 * Rewrite the media library / editor picker data to CDN URLs.
	 *
	 * The wp.media modal (and the Media Library grid) render from the JS payload
	 * `wp_prepare_attachment_for_js()` builds — and it composes each size URL by
	 * concatenating `wp_get_attachment_url()` with the stored size filename, so
	 * it never passes through `image_downsize`/`wp_get_attachment_image_src` and
	 * none of the other filters touch it. Without this, an install whose uploads
	 * are not on the local filesystem shows blank thumbnails in the picker even
	 * though the rendered front end and post thumbnails resolve fine.
	 *
	 * Gating matches the image filters: skipped in admin unless local-dev mode
	 * is on (auto on non-production), and in local-dev mode a locally-present
	 * original is left on origin — only missing files fall back to the CDN.
	 *
	 * @param array       $response   Prepared attachment data for JS.
	 * @param \WP_Post    $attachment The attachment post object.
	 * @param array|false $meta    Attachment metadata (unused).
	 * @return array Modified response.
	 */
	public function filter_attachment_for_js( $response, $attachment, $meta ) {
		unset( $meta );

		if ( ! is_array( $response ) || empty( $response['id'] ) ) {
			return $response;
		}

		$attachment_id = (int) $response['id'];

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $response;
		}

		// Keep origin URLs in admin unless local-dev mode is on (or explicitly
		// allowed) — the same posture as the image-rewriting filters.
		if ( $this->is_admin_without_local_dev()
			&& false === apply_filters( 'bunnify_admin_allow_attachment_for_js', false, $response, $attachment ) ) {
			return $response;
		}

		$original_url = \BunnifyFrontend\Library\AttachmentUrl::origin( $attachment_id );
		if ( empty( $original_url ) || ! URLTransformer::validate_image_url( $original_url ) ) {
			return $response;
		}

		// Local-dev mode: a locally-present original stays on origin (its
		// intermediates are alongside it); only missing files use the CDN.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled()
			&& URLTransformer::image_exists_locally( $original_url ) ) {
			return $response;
		}

		// Full-size URL: no dimensions, preserving the original filename.
		$full_cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id );
		if ( $full_cdn_url ) {
			$response['url'] = $full_cdn_url;
		}

		// Each intermediate size, rewritten with that size's dimensions.
		if ( ! empty( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
			foreach ( $response['sizes'] as $size_name => $size_data ) {
				$cdn_args = [];
				if ( ! empty( $size_data['width'] ) ) {
					$cdn_args['width'] = (int) $size_data['width'];
				}
				if ( ! empty( $size_data['height'] ) ) {
					$cdn_args['height'] = (int) $size_data['height'];
				}

				// 'full' carries the original dimensions; send it unresized.
				if ( 'full' === $size_name ) {
					$cdn_args = [];
				}

				$size_cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
				if ( $size_cdn_url ) {
					$response['sizes'][ $size_name ]['url'] = $size_cdn_url;
				}
			}
		}

		return $response;
	}

	/**
	 * Rewrite a bare attachment URL to the CDN.
	 *
	 * `wp_get_attachment_url()` is the canonical "give me this attachment's URL"
	 * call — used by themes, page builders (ACF URL fields), block bindings,
	 * feeds, and the REST media endpoint's `source_url`. None of those go through
	 * the `<img>`/resize pipeline the other filters cover, so their URLs stay on
	 * the origin host (404 on an install without synced uploads; CDN-bypass in
	 * production) unless rewritten here.
	 *
	 * Re-entrancy: the plugin calls `wp_get_attachment_url()` internally through
	 * {@see \BunnifyFrontend\Library\AttachmentUrl::origin()} to obtain the
	 * origin URL; that sets a suspend flag, so this callback returns the input
	 * untouched during an internal lookup — which both prevents recursion and
	 * keeps every internal origin lookup returning origin.
	 *
	 * @param string $url     The attachment URL.
	 * @param int    $post_id The attachment ID.
	 * @return string The (possibly CDN-rewritten) URL.
	 */
	public function filter_attachment_url( $url, $post_id ) {
		// (a) Internal origin lookup in progress — never rewrite it.
		if ( \BunnifyFrontend\Library\AttachmentUrl::is_suspended() ) {
			return $url;
		}

		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		// (b) Same admin posture as the other image filters.
		if ( $this->is_admin_without_local_dev()
			&& false === apply_filters( 'bunnify_admin_allow_attachment_url', false, $url, $post_id ) ) {
			return $url;
		}

		// (c) Images only by default; already-CDN URLs are left alone.
		if ( ! URLTransformer::validate_image_url( $url ) || URLTransformer::is_cdn_url( $url ) ) {
			return $url;
		}

		// (d) Local-dev: a locally-present original stays on origin.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled()
			&& URLTransformer::image_exists_locally( $url ) ) {
			return $url;
		}

		// (e) Full-size CDN URL (no args → preserves the original filename).
		// get_cdn_url_by_id() calls AttachmentUrl::origin() internally, so the
		// suspend flag is set during that inner call: no recursion, no double
		// transform. A null return ("CDN unavailable") keeps the origin URL.
		$cdn_url = URLTransformer::get_cdn_url_by_id( (int) $post_id );

		return $cdn_url ?: $url;
	}

	/**
	 * Rewrite a classic-theme custom header image URL to the CDN.
	 *
	 * `get_header_image()` and the `header_image` theme mod return a stored
	 * absolute origin URL, not via any attachment function — so no other filter
	 * sees it. The header is frequently the LCP element, so this is a high-value
	 * bare URL on classic (non-FSE) themes. Reuses the public `bunnify_url`
	 * path, which resolves the attachment ID, honours `bunnify_skip_for_url`,
	 * and applies the enabled/hostname gating.
	 *
	 * @param mixed $url The header image URL (or a sentinel like 'remove-header').
	 * @return mixed The (possibly CDN-rewritten) URL.
	 */
	public function filter_header_image_url( $url ) {
		if ( ! is_string( $url ) || '' === $url || 'remove-header' === $url ) {
			return $url;
		}

		if ( ! URLTransformer::validate_image_url( $url ) || URLTransformer::is_cdn_url( $url ) ) {
			return $url;
		}

		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled()
			&& URLTransformer::image_exists_locally( $url ) ) {
			return $url;
		}

		return (string) apply_filters( 'bunnify_url', $url );
	}

	/**
	 * Filter srcset array to transform URLs to CDN while preserving dimensions.
	 *
	 * @param array  $sources Array of image sources.
	 * @param array  $size_array Array of width and height values.
	 * @param string $image_src The 'src' of the image.
	 * @param array  $image_meta The image meta data as returned by 'wp_get_attachment_metadata()'.
	 * @param int    $attachment_id Image attachment ID.
	 * @return array Modified sources array.
	 */
	public function filter_srcset_array( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		// Debug logging for srcset processing.
		$this->debug_log( "filter_srcset_array called with attachment_id: {$attachment_id}, sources count: " . count( $sources ), 'filter_srcset_array' );

		if ( empty( $sources ) ) {
			$this->debug_log( 'No sources provided, returning empty array', 'filter_srcset_array' );
			return $sources;
		}

		// Get the original image URL.
		$original_url = \BunnifyFrontend\Library\AttachmentUrl::origin( $attachment_id );
		if ( empty( $original_url ) ) {
			$this->debug_log( "No original URL found for attachment_id: {$attachment_id}", 'filter_srcset_array' );
			return $sources;
		}

		$this->debug_log( "Original URL: {$original_url}", 'filter_srcset_array' );

		// Check local development mode.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			$exists_locally = URLTransformer::image_exists_locally( $original_url );
			if ( $exists_locally ) {
				$this->debug_log( "Local development mode enabled and image exists locally: {$original_url}, bypassing CDN transformation", 'filter_srcset_array' );
				return $sources; // Return original sources unchanged.
			}
		}

		// Get original image metadata to calculate proper aspect ratio.
		$original_meta   = $this->get_cached_attachment_metadata( $attachment_id );
		$original_width  = $original_meta['width'] ?? null;
		$original_height = $original_meta['height'] ?? null;

		// Process each source in the srcset.
		foreach ( $sources as $i => $source ) {
			$this->debug_log( "Processing source URL: {$source['url']}", 'filter_srcset_array' );

			// Skip if already a CDN URL to prevent double-processing.
			if ( $this->is_cdn_url( $source['url'] ) ) {
				$this->debug_log( "Source URL is already a CDN URL, skipping: {$source['url']}", 'filter_srcset_array' );
				continue;
			}

			// Validate the URL before processing.
			if ( ! URLTransformer::validate_image_url( $source['url'] ) ) {
				$this->debug_log( "Source URL validation failed: {$source['url']}", 'filter_srcset_array' );
				continue;
			}

			// Extract dimensions from the source.
			$width  = 'w' === $source['descriptor'] ? $source['value'] : ( $size_array[0] ?? false );
			$height = 'h' === $source['descriptor'] ? $source['value'] : ( $size_array[1] ?? false );

			// If we don't have dimensions, try to extract them from the URL.
			if ( ! $width || ! $height ) {
				$dimensions = \BunnifyFrontend\Library\ImageProcessor::parse_dimensions_from_filename( $source['url'] );
				if ( $dimensions ) {
					$width  = $dimensions[0];
					$height = $dimensions[1];
				}
			}

			// Transform the URL to use CDN with proper aspect ratio.
			$cdn_args = [];
			if ( $width ) {
				$cdn_args['width'] = $width;

				// Calculate proper height from aspect ratio.
				$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, $width, $height );
				$cdn_args['height'] = $dimensions['height'];
			} elseif ( $height ) {
				// If only height is provided, calculate width from aspect ratio.
				$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, 0, $height );
				$cdn_args['width']  = $dimensions['width'];
				$cdn_args['height'] = $height;
			}

			$transformed_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );
			if ( $transformed_url ) {
				$sources[ $i ]['url'] = $transformed_url;
			}
		}

		return $sources;
	}

	/**
	 * Filter image sizes attribute.
	 *
	 * @param string $sizes The sizes attribute.
	 * @param array  $size  The size array.
	 * @return string Modified sizes attribute.
	 */
	public function filter_sizes( string $sizes, array $size ): string {
		// For now, return the original sizes.
		// This could be enhanced to optimize sizes for CDN.
		return $sizes;
	}

	/**
	 * Filter srcset metadata to ensure custom sizes are properly handled.
	 *
	 * @param array  $image_meta The image meta data.
	 * @param array  $size_array Array of width and height values.
	 * @param string $image_src The 'src' of the image.
	 * @param int    $attachment_id Image attachment ID.
	 * @return array Modified image meta data.
	 */
	public function filter_srcset_meta( array $image_meta, array $size_array, string $image_src, int $attachment_id ): array {
		// Debug logging for srcset meta processing.
		$this->debug_log( "filter_srcset_meta called with attachment_id: {$attachment_id}, size_array: [" . implode( ',', $size_array ) . '], meta_sizes_count: ' . ( isset( $image_meta['sizes'] ) ? count( $image_meta['sizes'] ) : 0 ), 'filter_srcset_meta' );

		// If the image meta is empty or missing sizes, try to get it from the attachment.
		if ( empty( $image_meta ) || empty( $image_meta['sizes'] ) ) {
			$attachment_meta = $this->get_cached_attachment_metadata( $attachment_id );
			if ( $attachment_meta && ! empty( $attachment_meta['sizes'] ) ) {
				$image_meta = $attachment_meta;
				$this->debug_log( "Retrieved attachment meta for attachment_id: {$attachment_id}, sizes count: " . count( $attachment_meta['sizes'] ), 'filter_srcset_meta' );
			} else {
				$this->debug_log( "No attachment meta found for attachment_id: {$attachment_id}", 'filter_srcset_meta' );
			}
		}

		// Ensure we have the basic dimensions.
		if ( empty( $image_meta['width'] ) || empty( $image_meta['height'] ) ) {
			$attachment_meta = $this->get_cached_attachment_metadata( $attachment_id );
			if ( $attachment_meta ) {
				$image_meta['width']  = $attachment_meta['width'] ?? $image_meta['width'] ?? 0;
				$image_meta['height'] = $attachment_meta['height'] ?? $image_meta['height'] ?? 0;
				$this->debug_log( "Set dimensions for attachment_id: {$attachment_id}, width: {$image_meta['width']}, height: {$image_meta['height']}", 'filter_srcset_meta' );
			}
		}

		return $image_meta;
	}

	/**
	 * Check if a URL is already a CDN URL to prevent double-processing.
	 *
	 * @param string $url The URL to check.
	 * @return bool True if the URL is already a CDN URL, false otherwise.
	 */
	private function is_cdn_url( string $url ): bool {
		return URLTransformer::is_cdn_url( $url );
	}

	/**
	 * Generate srcset and sizes attributes for an image.
	 *
	 * @param WP_HTML_Tag_Processor $processor The HTML tag processor.
	 * @param int                   $attachment_id The attachment ID.
	 * @param string|array          $size The requested size.
	 * @param string                $cdn_url The CDN URL for the main image.
	 */
	private function generate_srcset_and_sizes( $processor, int $attachment_id, $size, string $cdn_url ): void {
		// Get image metadata.
		$image_meta = $this->get_cached_attachment_metadata( $attachment_id );
		if ( empty( $image_meta ) || empty( $image_meta['sizes'] ) ) {
			return;
		}

		// Get the size array for the requested size.
		$size_array = $this->get_size_array( $size, $image_meta );
		if ( empty( $size_array ) ) {
			return;
		}

		// Generate srcset sources.
		$sources = $this->generate_srcset_sources( $attachment_id, $image_meta, $size_array );
		if ( ! empty( $sources ) ) {
			$srcset_parts = [];
			foreach ( $sources as $width => $source ) {
				$srcset_parts[] = $source['url'] . ' ' . $width . 'w';
			}
			$srcset_value = implode( ', ', $srcset_parts );
			$processor->set_attribute( 'srcset', $srcset_value );
			$this->debug_log( "Generated srcset for attachment {$attachment_id}: {$srcset_value}", 'filter_attachment_image' );
		}

		// Generate sizes attribute.
		$sizes = $this->generate_sizes_attribute( $size_array );
		if ( $sizes ) {
			$processor->set_attribute( 'sizes', $sizes );
		}
	}

	/**
	 * Get the size array for a given size.
	 *
	 * @param string|array $size The requested size.
	 * @param array        $image_meta The image metadata.
	 * @return array|false The size array or false if not found.
	 */
	private function get_size_array( $size, array $image_meta ): array|false {
		if ( is_array( $size ) ) {
			return $size;
		}

		// Handle string sizes.
		if ( is_string( $size ) ) {
			// Check if it's a registered WordPress image size.
			$image_sizes = \BunnifyFrontend\Library\ImageProcessor::get_image_sizes();
			if ( array_key_exists( $size, $image_sizes ) ) {
				$size_data = $image_sizes[ $size ];
				return [ $size_data['width'], $size_data['height'] ];
			}
		}

		// Fallback to image dimensions.
		if ( ! empty( $image_meta['width'] ) && ! empty( $image_meta['height'] ) ) {
			return [ $image_meta['width'], $image_meta['height'] ];
		}

		return false;
	}

	/**
	 * Generate srcset sources for an image.
	 *
	 * @param int   $attachment_id The attachment ID.
	 * @param array $image_meta The image metadata.
	 * @param array $size_array The size array.
	 * @return array The srcset sources.
	 */
	private function generate_srcset_sources( int $attachment_id, array $image_meta, array $size_array ): array {
		$sources = [];

		// Add the main image size.
		$main_width  = $size_array[0];
		$main_height = $size_array[1];

		$main_cdn_url = URLTransformer::get_cdn_url_by_id(
			$attachment_id,
			[
				'width'  => $main_width,
				'height' => $main_height,
			]
		);

		if ( $main_cdn_url ) {
			$sources[ $main_width ] = [
				'url'        => $main_cdn_url,
				'descriptor' => 'w',
				'value'      => $main_width,
			];
		}

		// Add common responsive sizes.
		$responsive_sizes = [ 300, 600, 900, 1200, 1500, 1800 ];
		$full_width       = $image_meta['width'] ?? 0;
		$full_height      = $image_meta['height'] ?? 0;

		foreach ( $responsive_sizes as $width ) {
			// Skip if too close to existing width or larger than full size.
			if ( abs( $width - $main_width ) < 50 || ( $full_width && $width > $full_width ) ) {
				continue;
			}

			// Calculate height based on aspect ratio.
			$height = $full_height && $full_width ? round( ( $width * $full_height ) / $full_width ) : $main_height;

			$cdn_url = URLTransformer::get_cdn_url_by_id(
				$attachment_id,
				[
					'width'  => $width,
					'height' => $height,
				]
			);

			if ( $cdn_url ) {
				$sources[ $width ] = [
					'url'        => $cdn_url,
					'descriptor' => 'w',
					'value'      => $width,
				];
			}
		}

		// Sort by width.
		ksort( $sources );

		return $sources;
	}

	/**
	 * Generate sizes attribute for an image.
	 *
	 * @param array $size_array The size array.
	 * @return string The sizes attribute.
	 */
	private function generate_sizes_attribute( array $size_array ): string {
		$width = $size_array[0] ?? 0;

		if ( $width <= 0 ) {
			return '';
		}

		// Generate responsive sizes attribute.
		if ( $width <= 768 ) {
			return '(max-width: 768px) 100vw, ' . $width . 'px';
		} elseif ( $width <= 1024 ) {
			return '(max-width: 768px) 100vw, (max-width: 1024px) 50vw, ' . $width . 'px';
		} else {
			return '(max-width: 768px) 100vw, (max-width: 1024px) 50vw, (max-width: 1200px) 33vw, ' . $width . 'px';
		}
	}

	/**
	 * Resolve width/height for a string size from a registered image size or a
	 * custom aspect-ratio size (e.g. '16:9-768').
	 *
	 * @param string $size        The requested size name.
	 * @param array  $image_sizes Registered image sizes keyed by name.
	 * @return array Resolved dimensions as [ 'width' => mixed, 'height' => mixed ]; false when unknown.
	 */
	private function resolve_size_dimensions( string $size, array $image_sizes ): array {
		$width  = false;
		$height = false;

		// Check if it's a registered WordPress image size.
		if ( array_key_exists( $size, $image_sizes ) ) {
			$size_data = $image_sizes[ $size ];
			$width     = $size_data['width'] ?? false;
			$height    = $size_data['height'] ?? false;
		} else {
			// Handle custom aspect ratio sizes (e.g., '16:9-768').
			$custom_size = $this->parse_custom_size( $size );
			if ( $custom_size ) {
				$width  = $custom_size['width'];
				$height = $custom_size['height'];
			}
		}

		return [
			'width'  => $width,
			'height' => $height,
		];
	}

	/**
	 * Parse custom aspect ratio sizes like '16:9-768'.
	 *
	 * @param string $size The custom size string.
	 * @return array|false Array with width and height, or false if not a custom size.
	 */
	private function parse_custom_size( string $size ): array|false {
		// Handle custom aspect ratio sizes (e.g., '16:9-768', '4:3-1024', '1:1-600').
		if ( preg_match( '/^(\d+):(\d+)-(\d+)$/', $size, $matches ) ) {
			$aspect_width  = (int) $matches[1];
			$aspect_height = (int) $matches[2];
			$target_width  = (int) $matches[3];

			// Calculate height based on aspect ratio.
			$target_height = round( ( $target_width * $aspect_height ) / $aspect_width );

			return [
				'width'  => $target_width,
				'height' => $target_height,
			];
		}

		return false;
	}
}
