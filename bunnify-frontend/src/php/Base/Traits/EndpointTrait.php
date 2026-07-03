<?php
/**
 * Trait for handling common endpoint functionalities.
 *
 * File Path: src/php/Base/Traits/EndpointTrait.php
 *
 * @package BunnifyFrontend\Base
 */

namespace BunnifyFrontend\Base\Traits;

trait EndpointTrait {

	/**
	 * Holds the response to pass back to front end request.
	 *
	 * @var array
	 */
	public $response = [
		'data'    => [],
		'success' => true,
		'status'  => '',
	];

	/**
	 * Retrieve a parameter from the request.
	 *
	 * @param string $param         Parameter to retrieve.
	 * @param mixed  $default_value Default value if parameter is not found (default = '').
	 *
	 * @return mixed|string The value for the parameter or the default.
	 */
	public function get_param( string $param, $default_value = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only parameter accessor; callers verify context.
		return isset( $_REQUEST[ $param ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $param ] ) ) : $default_value;
	}

	/**
	 * Check if a parameter exists in the request.
	 *
	 * @param string $param The parameter to check.
	 *
	 * @return bool True if the parameter exists, false otherwise.
	 */
	public function has_param( string $param ): bool {
		return isset( $_REQUEST[ $param ] ); // phpcs:ignore
	}

	/**
	 * Sanitize a string parameter from the request.
	 *
	 * @param string $param         Parameter to retrieve and sanitize.
	 * @param mixed  $default_value Default value if parameter is not found (default = '').
	 *
	 * @return string The sanitized value of the parameter.
	 */
	public function get_sanitized_param( string $param, $default_value = '' ): string {
		return sanitize_text_field( $_REQUEST[ $param ] ?? $default_value ); // phpcs:ignore
	}

	/**
	 * Add data to the response.
	 *
	 * @param array $data Data to include in the response.
	 *
	 * @return void
	 */
	public function add_response_data( array $data ) {
		$this->response['data'] = array_merge( $this->response['data'], $data );
	}

	/**
	 * Set the success flag for the response.
	 *
	 * @param bool $success The success flag (default = true).
	 *
	 * @return void
	 */
	public function set_success( bool $success = true ) {
		$this->response['success'] = $success;
	}

	/**
	 * Set a status message for the response.
	 *
	 * @param string $status The status message.
	 *
	 * @return void
	 */
	public function set_status( string $status ) {
		$this->response['status'] = $status;
	}

	/**
	 * Sends a JSON response for AJAX or simple JSON requests using the internal response array.
	 *
	 * @param int $status The HTTP status code (default = 200).
	 *
	 * @return void
	 */
	public function json_resp( int $status = 200 ) {
		wp_send_json( $this->response, $status );
	}

	/**
	 * Sends a REST API response using WP_REST_Response with the internal response array.
	 *
	 * @param int   $status  The HTTP status code (default = 200).
	 * @param array $headers Any additional headers to add to the response.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_resp( int $status = 200, array $headers = [] ) {

		$response = new \WP_REST_Response( $this->response, $status );

		// Add any additional headers
		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}

		return $response;
	}
}
