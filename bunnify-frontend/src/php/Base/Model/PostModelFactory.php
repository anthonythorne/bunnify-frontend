<?php
/**
 * PostFactory.
 *
 * @package BunnifyFrontend\Base\Model
 */

namespace BunnifyFrontend\Base\Model;

/**
 * A factory which produces `PostModel` instances.  A `PostModel` instance
 * should not be instantiated directly.  It should always go via one of the
 * methods in this class.
 */
class PostModelFactory extends GenericPostModelFactory {

	/** Gets post type of the custom post type associated with this model factory */

	/**
	 * Get the model post type.
	 *
	 * @return string
	 */
	public function get_post_type() {
		return PostModel::POST_TYPE;
	}

	/**
	 * Wrap a post object to get a model instance.
	 *
	 * @param object|\WP_Post $the_post The WP_Post to wrap.
	 *
	 * @return mixed
	 */
	public function wrap( mixed $the_post ): mixed {
		assert( ! empty( $the_post ) );

		return new PostModel( $the_post );
	}
}
