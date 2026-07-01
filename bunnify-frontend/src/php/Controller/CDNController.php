<?php
/**
 * CDN Controller for Bunnify Frontend plugin.
 *
 * Handles WordPress hooks and filters for CDN functionality.
 *
 * File Path: src/php/Controller/CDNController.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides WordPress integration for CDN functionality.
 */

namespace BunnifyFrontend\Controller;

use BunnifyFrontend\Base\Main\Controller;
use BunnifyFrontend\Base\Traits\DebugTrait;
use BunnifyFrontend\Library\URLTransformer;
use BunnifyFrontend\Library\CdnClientTrait;

/**
 * Class CDNController
 *
 * Handles WordPress hooks and filters for CDN functionality.
 */
class CDNController extends Controller {
	use DebugTrait;
	use CdnClientTrait;

	/**
	 * Initialize WordPress hooks.
	 */
	public function set_up() {
		// Register the bunnify_url filter for direct URL processing.
		add_filter( 'bunnify_url', [ $this, 'cdn_url' ], 10, 3 );
	}

	/**
	 * Generates a Bunnify URL using the CDN Library.
	 *
	 * @param string       $image_url URL to the publicly accessible image you want to manipulate.
	 * @param array|string $args An array of arguments, e.g. array( 'width' => '300', 'height' => '200' ), or in string form (w=123&h=456).
	 * @param string|null  $scheme URL protocol.
	 * @return string The raw final URL. You should run this through esc_url() before displaying it.
	 */
	public function cdn_url(
		string $image_url,
		array|string $args = [],
		?string $scheme = null,
	): string {
		// Debug logging for CDN URL generation.
		$this->debug_log( 'cdn_url called with: ' . $image_url . ' | args: ' . print_r( $args, true ) . ' | scheme: ' . $scheme, 'cdn_url' );

		$image_url = trim( $image_url );

		// Bail early if disabled.
		if ( defined( 'BUNNIFY_DISABLE' ) && BUNNIFY_DISABLE ) {
			return $image_url;
		}

		// Allow specific image URLs to avoid going through Bunnify.
		if ( false !== apply_filters( 'bunnify_skip_for_url', false, $image_url, $args, $scheme ) ) {
			return $image_url;
		}

		// Filter the original image URL before processing.
		$image_url = apply_filters( 'bunnify_pre_image_url', $image_url, $args, $scheme );

		// Try to get attachment ID first to avoid dimension stripping.
		$attachment_id = \BunnifyFrontend\Library\ImageProcessor::get_attachment_id_from_url( $image_url );

		if ( $attachment_id ) {
			// Use attachment ID approach to preserve original filename.
			$final_url = URLTransformer::get_cdn_url_by_id( $attachment_id, $args, $scheme );
			if ( $final_url ) {
				$this->debug_log( 'cdn_url returning (attachment ID): ' . $final_url, 'cdn_url' );
				return $final_url;
			}
		}

		// Filter the arguments before processing.
		$args = apply_filters( 'bunnify_pre_args', $args, $image_url, $scheme );

		// Use the URLTransformer for the actual transformation.
		$final_url = $this->init_cdn() ? $this->url_transformer->transform_url( $image_url, $args, $scheme ) ?? $image_url : $image_url;

		// Debug logging for final URL.
		$this->debug_log( 'cdn_url returning (fallback): ' . $final_url . ' | original args: ' . print_r( $args, true ), 'cdn_url' );

		return $final_url;
	}
}
