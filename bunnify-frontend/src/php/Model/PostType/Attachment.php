<?php
/**
 * The Attachment model, internal use for bunnify.
 *
 * File Path: src/php/Model/PostType/Attachment.php
 *
 * @package BunnifyFrontend
 */

namespace BunnifyFrontend\Model\PostType;

use BunnifyFrontend\Base\Model\GenericPostModel;

/**
 * The Attachment Post Model, internal use for bunnify.
 */
class Attachment extends GenericPostModel {

	const POST_TYPE = 'attachment';

	/**
	 * Cached attachment URL.
	 *
	 * @var string|null
	 */
	private ?string $cached_url = null;

	/**
	 * Cached attachment metadata.
	 *
	 * @var array|null
	 */
	private ?array $cached_metadata = null;

	/**
	 * Get the original attachment URL.
	 *
	 * @return string|null The attachment URL or null if not found.
	 */
	public function get_attachment_url(): ?string {
		if ( null === $this->cached_url ) {
			$this->cached_url = \BunnifyFrontend\Library\AttachmentUrl::origin( (int) $this->post->ID );
		}
		return $this->cached_url;
	}

	/**
	 * Get attachment metadata.
	 *
	 * @return array|null The attachment metadata or null if not found.
	 */
	public function get_metadata(): ?array {
		if ( null === $this->cached_metadata ) {
			$this->cached_metadata = wp_get_attachment_metadata( $this->post->ID );
		}
		return $this->cached_metadata;
	}

	/**
	 * Check if this attachment is an image.
	 *
	 * @return bool True if the attachment is an image, false otherwise.
	 */
	public function is_image(): bool {
		return wp_attachment_is_image( $this->post->ID );
	}

	/**
	 * Get the file path of the attachment.
	 *
	 * @return string|null The file path or null if not found.
	 */
	public function get_file_path(): ?string {
		return get_attached_file( $this->post->ID );
	}

	/**
	 * Get the attachment's mime type.
	 *
	 * @return string|null The mime type or null if not found.
	 */
	public function get_mime_type(): ?string {
		return get_post_mime_type( $this->post->ID );
	}

	/**
	 * Get the attachment's file size.
	 *
	 * @return int|null The file size in bytes or null if not found.
	 */
	public function get_file_size(): ?int {
		$file_path = $this->get_file_path();
		if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
			return null;
		}
		return filesize( $file_path );
	}

	/**
	 * Get image dimensions if this is an image attachment.
	 *
	 * @return array|null Array with 'width' and 'height' keys or null if not an image.
	 */
	public function get_dimensions(): ?array {
		if ( ! $this->is_image() ) {
			return null;
		}

		$metadata = $this->get_metadata();
		if ( empty( $metadata ) || ! isset( $metadata['width'], $metadata['height'] ) ) {
			return null;
		}

		return [
			'width'  => $metadata['width'],
			'height' => $metadata['height'],
		];
	}

	/**
	 * Get the attachment's alt text.
	 *
	 * @return string|null The alt text or null if not found.
	 */
	public function get_alt_text(): ?string {
		return get_post_meta( $this->post->ID, '_wp_attachment_image_alt', true );
	}

	/**
	 * Get the attachment's caption.
	 *
	 * @return string|null The caption or null if not found.
	 */
	public function get_caption(): ?string {
		return $this->post->post_excerpt;
	}

	/**
	 * Get the attachment's description.
	 *
	 * @return string|null The description or null if not found.
	 */
	public function get_description(): ?string {
		return $this->post->post_content;
	}

	/**
	 * Get the attachment's title.
	 *
	 * @return string|null The title or null if not found.
	 */
	public function get_title(): ?string {
		return $this->post->post_title;
	}
}
