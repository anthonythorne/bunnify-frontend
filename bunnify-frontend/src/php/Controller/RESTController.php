<?php
/**
 * REST API controller for Bunnify Frontend plugin.
 *
 * Handles REST API related functionality for image processing.
 *
 * File Path: src/php/Controller/RESTController.php
 *
 * @package BunnifyFrontend
 * @since   1.0.0
 *
 * Provides REST API integration and processing logic.
 */

namespace BunnifyFrontend\Controller;

use BunnifyFrontend\Model\PostType\Attachment;
use WP_Post;
use BunnifyFrontend\Base\Main\Controller;
use BunnifyFrontend\Library\URLTransformer;

/**
 * Class RESTController
 *
 * Handles REST API related functionality.
 */
class RESTController extends Controller {

	/**
	 * URL Transformer instance.
	 */
	private ?URLTransformer $url_transformer = null;

	/**
	 * BunnyCDN hostname.
	 */
	private ?string $bunnify_hostname = null;

	/**
	 * Initialize WordPress hooks for REST API functionality.
	 */
	public function set_up() {
		// REST API support for frontend functionality.
		add_filter( 'rest_request_before_callbacks', [ $this, 'should_rest_bunnify_image_downsize' ], 10, 3 );
		add_filter( 'rest_after_insert_attachment', [ $this, 'should_rest_bunnify_image_downsize_insert_attachment' ], 10, 2 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'cleanup_rest_bunnify_image_downsize' ], 10, 3 );
	}

	/**
	 * Check if REST request should use CDN image downsize.
	 *
	 * @param mixed            $response Response data.
	 * @param array            $endpoint_data Endpoint data.
	 * @param \WP_REST_Request $request Request object.
	 * @return mixed Response data.
	 */
	public function should_rest_bunnify_image_downsize( $response, $endpoint_data, $request ) {
		try {
			// Check if this is an attachment-related endpoint.
			if ( ! is_array( $endpoint_data ) || empty( $endpoint_data['callback'] ) ) {
				return $response;
			}

			// For now, always return the original response.
			// This could be enhanced to conditionally apply CDN processing.
			return $response;
		} catch ( \Exception $e ) {
			// Log error and return original response.
			error_log( 'Bunnify REST error: ' . $e->getMessage() );
			return $response;
		}
	}

	/**
	 * Handle REST attachment insertion for CDN processing.
	 *
	 * @param \WP_Post         $attachment Attachment post object.
	 * @param \WP_REST_Request $request Request object.
	 */
	public function should_rest_bunnify_image_downsize_insert_attachment( $attachment, $request ) {
		try {
			// Validate attachment.
			if ( ! $attachment instanceof WP_Post || Attachment::POST_TYPE !== $attachment->post_type ) {
				return;
			}

			// For now, do nothing.
			// This could be enhanced to process attachments during REST insertion.
		} catch ( \Exception $e ) {
			// Log error.
			error_log( 'Bunnify REST attachment error: ' . $e->getMessage() );
		}
	}

	/**
	 * Clean up REST CDN image downsize processing.
	 *
	 * @param mixed $response Response data.
	 * @return mixed Response data.
	 */
	public function cleanup_rest_bunnify_image_downsize( $response ) {
		try {
			// For now, return the original response.
			// This could be enhanced to clean up any CDN processing.
			return $response;
		} catch ( \Exception $e ) {
			// Log error and return original response.
			error_log( 'Bunnify REST cleanup error: ' . $e->getMessage() );
			return $response;
		}
	}
}
