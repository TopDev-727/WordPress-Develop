<?php

/**
 * Unit test factory for posts.
 *
 * Note: The below @method notations are defined solely for the benefit of IDEs,
 * as a way to indicate expected return values from the given factory methods.
 *
 * @method int create( $args = array(), $generation_definitions = null )
 * @method WP_Post create_and_get( $args = array(), $generation_definitions = null )
 * @method int[] create_many( $count, $args = array(), $generation_definitions = null )
 */
class WP_UnitTest_Factory_For_Post extends WP_UnitTest_Factory_For_Thing {

	function __construct( $factory = null ) {
		parent::__construct( $factory );
		$this->default_generation_definitions = array(
			'post_status'  => 'publish',
			'post_title'   => new WP_UnitTest_Generator_Sequence( 'Post title %s' ),
			'post_content' => new WP_UnitTest_Generator_Sequence( 'Post content %s' ),
			'post_excerpt' => new WP_UnitTest_Generator_Sequence( 'Post excerpt %s' ),
			'post_type'    => 'post',
		);
	}

	/**
	 * Creates a post object.
	 *
	 * @param array $args Array with elements for the post.
	 *
	 * @return int|WP_Error The post ID on success. The value 0 or WP_Error on failure.
	 */
	function create_object( $args ) {
		return wp_insert_post( $args );
	}

	/**
	 * Updates an existing post object.
	 *
	 * @param int   $post_id The post id to update.
	 * @param array $fields  Post data.
	 *
	 * @return int|WP_Error The value 0 or WP_Error on failure. The post ID on success.
	 */
	function update_object( $post_id, $fields ) {
		$fields['ID'] = $post_id;
		return wp_update_post( $fields );
	}

	/**
	 * Retrieves a object by an id.
	 *
	 * @param int   $post_id The post id to update.
	 *
	 * @return null|WP_Post WP_Post on success or null on failure.
	 */
	function get_object_by_id( $post_id ) {
		return get_post( $post_id );
	}
}
