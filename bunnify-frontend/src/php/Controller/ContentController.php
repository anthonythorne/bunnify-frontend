<?php
/**
 * Content processing controller for Bunnify Frontend plugin.
 *
 * Handles content filtering including the_content, blocks, galleries, and widgets.
 *
 * File Path: src/php/Controller/ContentController.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides content processing and transformation logic.
 */

namespace BunnifyFrontend\Controller;

use WP_HTML_Tag_Processor;
use BunnifyFrontend\Base\Main\Controller;
use BunnifyFrontend\Base\Traits\DebugTrait;
use BunnifyFrontend\Base\Traits\CachingTrait;
use BunnifyFrontend\Library\URLTransformer;
use BunnifyFrontend\Library\ImageProcessor;
use BunnifyFrontend\Library\CdnClientTrait;

/**
 * Class ContentController
 *
 * Handles content filtering and processing.
 */
class ContentController extends Controller {
	use DebugTrait;
	use CachingTrait;
	use CdnClientTrait;

	/**
	 * Initialize WordPress hooks for content processing.
	 */
	public function set_up() {
		// Add a filter to easily apply image CDN urls without applying all `the_content` filters.
		add_filter( 'bunnify_content', [ $this, 'filter_the_content' ], 10 );

		// Content filtering hooks with reasonable priorities.
		add_filter( 'the_content', [ $this, 'filter_the_content' ], 20 );
		add_filter( 'widget_text', [ $this, 'filter_the_content' ], 20 );
		add_filter( 'get_post_galleries', [ $this, 'filter_the_galleries' ], 20 );
		add_filter( 'widget_media_image_instance', [ $this, 'filter_the_image_widget' ], 20 );

		// Filter block rendering to catch gallery blocks and other blocks.
		add_filter( 'render_block', [ $this, 'filter_block_rendering' ], 20, 2 );

		// Filter gallery block specifically to ensure original images are used.
		add_filter( 'render_block_core/gallery', [ $this, 'filter_gallery_block' ], 20, 2 );
	}

	/**
	 * Filter block rendering to process images.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block The block data.
	 * @return string Modified block content.
	 */
	public function filter_block_rendering( $block_content, $block ) {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		// Process images in the block content.
		return $this->filter_the_content( $block_content );
	}

	/**
	 * Filter gallery block to use CDN URLs.
	 *
	 * @param string $block_content The block content.
	 * @param array  $block The block data.
	 * @return string Modified block content.
	 */
	public function filter_gallery_block( $block_content, $block ) {
		if ( empty( $block_content ) ) {
			return $block_content;
		}

		// Process images in the gallery block.
		return $this->filter_the_content( $block_content );
	}

	/**
	 * Filter the content to replace image URLs with CDN URLs.
	 *
	 * @param string $content The content to filter.
	 * @return string Modified content.
	 */
	public function filter_the_content( $content ) {
		if ( empty( $content ) ) {
			return $content;
		}

		// Allow plugins to disable content processing for attachment images.
		if ( apply_filters( 'bunnify_skip_content_processing', false, $content ) ) {
			return $content;
		}

		// Use WP_HTML_Tag_Processor to safely process HTML (always available in WP 6.8+).
		return $this->process_content_with_tag_processor( $content );
	}

	/**
	 * Process content using WP_HTML_Tag_Processor.
	 *
	 * @param string $content The content to process.
	 * @return string Modified content.
	 */
	private function process_content_with_tag_processor( $content ) {
		$processor = new WP_HTML_Tag_Processor( $content );

		while ( $processor->next_tag( 'img' ) ) {
			$src = $processor->get_attribute( 'src' );

			// Skip processing if this is likely an attachment image that's already handled.
			// WordPress attachment images typically have specific patterns and attributes.
			if ( $this->is_attachment_image( $processor ) ) {
				continue;
			}

			if ( ! empty( $src ) && URLTransformer::validate_image_url( $src ) ) {
				// Extract dimensions from the original URL for CDN parameters.
				$extracted_dimensions = ImageProcessor::parse_dimensions_from_filename( $src );

				// Get attachment ID from the URL (even if it has dimensions).
				$attachment_id = ImageProcessor::get_attachment_id_from_url( $src );

				// Debug logging for content image processing.
				$this->debug_log( "Content image processing: src={$src}, attachment_id=" . ( $attachment_id ?: 'false' ), 'content_processing' );

				if ( $attachment_id ) {
					// Check local development mode first.
					if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
						$original_url = wp_get_attachment_url( $attachment_id );
						if ( $original_url && URLTransformer::image_exists_locally( $original_url ) ) {
							$this->debug_log( "Local development mode enabled and image exists locally: {$original_url}, bypassing CDN transformation", 'content_processing' );
							continue; // Skip CDN transformation for this image.
						}
					}

					// Extract dimensions from the image attributes.
					$width  = $processor->get_attribute( 'width' );
					$height = $processor->get_attribute( 'height' );

					// Build CDN arguments based on the image dimensions.
					$cdn_args = [];
					if ( $width ) {
						$cdn_args['width'] = (int) $width;

						// Calculate proper height to maintain aspect ratio.
						$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, (int) $width, (int) $height );
						$cdn_args['height'] = $dimensions['height'];
					} elseif ( $height ) {
						// If only height is specified, calculate width from aspect ratio.
						$dimensions         = $this->calculate_aspect_ratio_dimensions( $attachment_id, 0, (int) $height );
						$cdn_args['width']  = $dimensions['width'];
						$cdn_args['height'] = (int) $height;
					} elseif ( $extracted_dimensions ) {
						// Use dimensions extracted from the filename if no width/height attributes.
						$cdn_args['width']  = $extracted_dimensions[0];
						$cdn_args['height'] = $extracted_dimensions[1];
					}

					// Debug logging for CDN arguments.
					$this->debug_log( "CDN args for attachment {$attachment_id}: " . print_r( $cdn_args, true ), 'content_processing' );

					// Generate CDN URL using attachment ID with proper dimensions.
					// This will use the original image URL, not the resized one.
					$cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $cdn_args );

					// Debug logging for CDN URL generation.
					$this->debug_log( 'Generated CDN URL: ' . ( $cdn_url ?: 'null' ), 'content_processing' );

					if ( $cdn_url ) {
						$processor->set_attribute( 'src', $cdn_url );

						// Also transform srcset if it exists.
						$srcset_value = $processor->get_attribute( 'srcset' );
						if ( $srcset_value ) {
							$new_srcset = $this->transform_srcset_for_content( $srcset_value, $attachment_id );
							if ( $new_srcset ) {
								$processor->set_attribute( 'srcset', $new_srcset );
							}
						}
					}
				} else {
					// Debug logging for failed attachment ID lookup.
					$this->debug_log( "Failed to find attachment ID for URL: {$src}", 'content_processing' );

					// For non-attachment images, use a direct CDN transformation.
					$cdn_url = $this->transform_url_direct( $src );
					if ( $cdn_url ) {
						$processor->set_attribute( 'src', $cdn_url );
					}
				}
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Transform srcset URLs for content images.
	 *
	 * @param string $srcset_value The srcset attribute value.
	 * @param int    $attachment_id The attachment ID.
	 * @return string|false The transformed srcset or false on failure.
	 */
	private function transform_srcset_for_content( string $srcset_value, int $attachment_id ): string|false {
		// Check local development mode first.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			$original_url = wp_get_attachment_url( $attachment_id );
			if ( $original_url && URLTransformer::image_exists_locally( $original_url ) ) {
				$this->debug_log( "Local development mode enabled and image exists locally: {$original_url}, bypassing srcset transformation", 'content_processing' );
				return false; // Return false to keep original srcset unchanged.
			}
		}

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
				if ( URLTransformer::is_cdn_url( $url ) ) {
					$new_srcset_parts[] = $part;
					continue;
				}

				// Check if this is a local URL that should be transformed.
				if ( URLTransformer::validate_image_url( $url ) ) {
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

					// Generate CDN URL for this srcset entry.
					$srcset_cdn_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $srcset_cdn_args );
					$new_url        = $srcset_cdn_url ?: $url;

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

		return implode( ', ', $new_srcset_parts );
	}

	/**
	 * Transform URL directly to CDN without dimension stripping.
	 *
	 * @param string $image_url The image URL to transform.
	 * @return string|false The CDN URL or false on failure.
	 */
	private function transform_url_direct( string $image_url ): string|false {
		// Check local development mode first.
		if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
			if ( URLTransformer::image_exists_locally( $image_url ) ) {
				$this->debug_log( "Local development mode enabled and image exists locally: {$image_url}, bypassing direct URL transformation", 'content_processing' );
				return false; // Return false to keep original URL unchanged.
			}
		}

		if ( ! $this->init_cdn() ) {
			return false;
		}

		// Parse the URL to get the path.
		$url_parts = wp_parse_url( $image_url );
		if ( ! is_array( $url_parts ) || empty( $url_parts['host'] ) || empty( $url_parts['path'] ) ) {
			return false;
		}

		// Check if this is a local WordPress upload.
		$upload_dir  = wp_upload_dir();
		$upload_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );

		// Only transform if it's a local upload.
		if ( ! empty( $upload_path ) && strpos( $url_parts['path'], $upload_path ) === 0 ) {
			// Build CDN URL using the original path (preserving -scaled, -full, etc.).
			$cdn_hostname = get_option( 'bunnify_hostname' );
			if ( ! empty( $cdn_hostname ) ) {
				return 'https://' . $cdn_hostname . $url_parts['path'];
			}
		}

		return false;
	}

	/**
	 * Check if an image is likely a WordPress attachment image that's already handled.
	 *
	 * @param WP_HTML_Tag_Processor $processor The HTML tag processor.
	 * @return bool True if this appears to be an attachment image.
	 */
	private function is_attachment_image( $processor ): bool {
		// Check for WordPress attachment-specific attributes.
		$class = $processor->get_attribute( 'class' );
		$src   = $processor->get_attribute( 'src' );

		// Skip if this has WordPress post thumbnail classes (handled by ImageController).
		if ( $class && strpos( $class, 'wp-post-image' ) !== false ) {
			return true;
		}

		// Skip if this has WordPress attachment classes AND is in a specific context.
		// Content images can have wp-image-* classes but should still be processed.
		if ( $class && (
			strpos( $class, 'attachment-' ) !== false ||
			strpos( $class, 'size-' ) !== false
		) ) {
			// Only skip if this is likely a post thumbnail or featured image.
			// Content images with these classes should still be processed.
			return false;
		}

		// Skip if this has srcset attribute AND is likely a post thumbnail.
		// Content images can have srcset but should still be processed.
		if ( $processor->get_attribute( 'srcset' ) ) {
			// Only skip if this appears to be a post thumbnail.
			// Check if it has post thumbnail specific classes.
			if ( $class && strpos( $class, 'wp-post-image' ) !== false ) {
				return true;
			}
			// Content images with srcset should still be processed.
			return false;
		}

		// Skip if this has sizes attribute AND is likely a post thumbnail.
		if ( $processor->get_attribute( 'sizes' ) ) {
			// Only skip if this appears to be a post thumbnail.
			if ( $class && strpos( $class, 'wp-post-image' ) !== false ) {
				return true;
			}
			// Content images with sizes should still be processed.
			return false;
		}

		// Don't skip based on width/height attributes alone.
		// Content images can have these attributes and should be processed.

		return false;
	}

	/**
	 * Filter galleries to use CDN URLs.
	 *
	 * @param array $galleries Array of gallery data.
	 * @return array Modified galleries array.
	 */
	public function filter_the_galleries( $galleries ) {
		if ( empty( $galleries ) ) {
			return $galleries;
		}

		foreach ( $galleries as &$gallery ) {
			if ( isset( $gallery['src'] ) ) {
				// Check local development mode first.
				if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
					if ( URLTransformer::image_exists_locally( $gallery['src'] ) ) {
						$this->debug_log( "Local development mode enabled and image exists locally: {$gallery['src']}, bypassing gallery transformation", 'content_processing' );
						continue; // Keep original URL unchanged.
					}
				}

				$cdn_url        = $this->init_cdn() ? $this->url_transformer->transform_url( $gallery['src'] ) : null;
				$gallery['src'] = $cdn_url ?? $gallery['src'];
			}
		}

		return $galleries;
	}

	/**
	 * Filter image widget to use CDN URLs.
	 *
	 * @param array $instance Widget instance.
	 * @return array Modified widget instance.
	 */
	public function filter_the_image_widget( $instance ) {
		if ( ! empty( $instance['url'] ) ) {
			// Check local development mode first.
			if ( \BunnifyFrontend\Controller\SettingsController::is_local_dev_mode_enabled() ) {
				if ( URLTransformer::image_exists_locally( $instance['url'] ) ) {
					$this->debug_log( "Local development mode enabled and image exists locally: {$instance['url']}, bypassing image widget transformation", 'content_processing' );
					return $instance; // Keep original URL unchanged.
				}
			}

			$cdn_url         = $this->init_cdn() ? $this->url_transformer->transform_url( $instance['url'] ) : null;
			$instance['url'] = $cdn_url ?? $instance['url'];
		}

		return $instance;
	}
}
