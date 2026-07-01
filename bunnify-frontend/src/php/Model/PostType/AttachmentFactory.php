<?php
/**
 * The Post|Post factory, internal use for bunnify.
 *
 * File Path: src/php/Model/PostType/AttachmentFactory.php
 *
 * @package BunnifyFrontend
 */

namespace BunnifyFrontend\Model\PostType;

use WP_Post;
use BunnifyFrontend\Base\Model\GenericPostModelFactory;

/**
 * The Post|Attachment factory, internal use for bunnify.
 */
class AttachmentFactory extends GenericPostModelFactory {

	/**
	 * Wrap a WordPress post object in an Attachment model.
	 *
	 * @param mixed $the_post WordPress post object or post ID.
	 *
	 * @return Attachment|mixed The wrapped Attachment model or original value if wrapping fails.
	 */
	public function wrap( mixed $the_post ): mixed {
		return new Attachment( $the_post );
	}
}
