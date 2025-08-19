<?php
/**
 * PostModel.
 *
 * @package BunnifyFrontend\Base\Model
 */

namespace BunnifyFrontend\Base\Model;

/**
 * A model instance which wraps a `WP_Post` instance.  You should extend this
 * class for a CPT instance.
 *
 * @package BunnifyFrontend\Base\Model
 */
class PostModel extends GenericPostModel {

	const POST_TYPE = self::POST_TYPE_DEFAULT;

}
