<?php
/**
 * REST API: WP_REST_Posts_Controller class
 *
 * @package WordPress
 * @subpackage REST_API
 * @since 4.7.0
 */

/**
 * Core class to access posts via the REST API.
 *
 * @since 4.7.0
 *
 * @see WP_REST_Controller
 */
class WP_REST_Posts_Controller extends WP_REST_Controller {

	/**
	 * Post type.
	 *
	 * @since 4.7.0
	 * @access protected
	 * @var string
	 */
	protected $post_type;

	/**
	 * Instance of a post meta fields object.
	 *
	 * @since 4.7.0
	 * @access protected
	 * @var WP_REST_Post_Meta_Fields
	 */
	protected $meta;

	/**
	 * Constructor.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param string $post_type Post type.
	 */
	public function __construct( $post_type ) {
		$this->post_type = $post_type;
		$this->namespace = 'wp/v2';
		$obj = get_post_type_object( $post_type );
		$this->rest_base = ! empty( $obj->rest_base ) ? $obj->rest_base : $obj->name;

		$this->meta = new WP_REST_Post_Meta_Fields( $this->post_type );
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {

		register_rest_route( $this->namespace, '/' . $this->rest_base, array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'get_items_permissions_check' ),
				'args'                => $this->get_collection_params(),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<id>[\d]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'get_item_permissions_check' ),
				'args'                => array(
					'context'  => $this->get_context_param( array( 'default' => 'view' ) ),
					'password' => array(
						'description' => __( 'The password for the post if it is password protected.' ),
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_item' ),
				'permission_callback' => array( $this, 'update_item_permissions_check' ),
				'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_item' ),
				'permission_callback' => array( $this, 'delete_item_permissions_check' ),
				'args'                => array(
					'force' => array(
						'default'     => false,
						'description' => __( 'Whether to bypass trash and force deletion.' ),
					),
				),
			),
			'schema' => array( $this, 'get_public_item_schema' ),
		) );
	}

	/**
	 * Checks if a given request has access to read posts.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param  WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has read access, WP_Error object otherwise.
	 */
	public function get_items_permissions_check( $request ) {

		$post_type = get_post_type_object( $this->post_type );

		if ( 'edit' === $request['context'] && ! current_user_can( $post_type->cap->edit_posts ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to edit these posts in this post type.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Retrieves a collection of posts.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_items( $request ) {

		// Ensure a search string is set in case the orderby is set to 'relevance'.
		if ( ! empty( $request['orderby'] ) && 'relevance' === $request['orderby'] && empty( $request['search'] ) ) {
			return new WP_Error( 'rest_no_search_term_defined', __( 'You need to define a search term to order by relevance.' ), array( 'status' => 400 ) );
		}

		// Retrieve the list of registered collection query parameters.
		$registered = $this->get_collection_params();
		$args = array();

		/*
		 * This array defines mappings between public API query parameters whose
		 * values are accepted as-passed, and their internal WP_Query parameter
		 * name equivalents (some are the same). Only values which are also
		 * present in $registered will be set.
		 */
		$parameter_mappings = array(
			'author'         => 'author__in',
			'author_exclude' => 'author__not_in',
			'exclude'        => 'post__not_in',
			'include'        => 'post__in',
			'menu_order'     => 'menu_order',
			'offset'         => 'offset',
			'order'          => 'order',
			'orderby'        => 'orderby',
			'page'           => 'paged',
			'parent'         => 'post_parent__in',
			'parent_exclude' => 'post_parent__not_in',
			'search'         => 's',
			'slug'           => 'name',
			'status'         => 'post_status',
		);

		/*
		 * For each known parameter which is both registered and present in the request,
		 * set the parameter's value on the query $args.
		 */
		foreach ( $parameter_mappings as $api_param => $wp_param ) {
			if ( isset( $registered[ $api_param ], $request[ $api_param ] ) ) {
				$args[ $wp_param ] = $request[ $api_param ];
			}
		}

		// Check for & assign any parameters which require special handling or setting.
		$args['date_query'] = array();

		// Set before into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['before'], $request['before'] ) ) {
			$args['date_query'][0]['before'] = $request['before'];
		}

		// Set after into date query. Date query must be specified as an array of an array.
		if ( isset( $registered['after'], $request['after'] ) ) {
			$args['date_query'][0]['after'] = $request['after'];
		}

		// Ensure our per_page parameter overrides any provided posts_per_page filter.
		if ( isset( $registered['per_page'] ) ) {
			$args['posts_per_page'] = $request['per_page'];
		}

		if ( isset( $registered['sticky'], $request['sticky'] ) ) {
			$sticky_posts = get_option( 'sticky_posts', array() );
			if ( $sticky_posts && $request['sticky'] ) {
				/*
				 * As post__in will be used to only get sticky posts,
				 * we have to support the case where post__in was already
				 * specified.
				 */
				$args['post__in'] = $args['post__in'] ? array_intersect( $sticky_posts, $args['post__in'] ) : $sticky_posts;

				/*
				 * If we intersected, but there are no post ids in common,
				 * WP_Query won't return "no posts" for post__in = array()
				 * so we have to fake it a bit.
				 */
				if ( ! $args['post__in'] ) {
					$args['post__in'] = array( -1 );
				}
			} elseif ( $sticky_posts ) {
				/*
				 * As post___not_in will be used to only get posts that
				 * are not sticky, we have to support the case where post__not_in
				 * was already specified.
				 */
				$args['post__not_in'] = array_merge( $args['post__not_in'], $sticky_posts );
			}
		}

		// Force the post_type argument, since it's not a user input variable.
		$args['post_type'] = $this->post_type;

		/**
		 * Filters the query arguments for a request.
		 *
		 * Enables adding extra arguments or setting defaults for a post collection request.
		 *
		 * @since 4.7.0
		 *
		 * @link https://developer.wordpress.org/reference/classes/wp_query/
		 *
		 * @param array           $args    Key value array of query var to query value.
		 * @param WP_REST_Request $request The request used.
		 */
		$args = apply_filters( "rest_{$this->post_type}_query", $args, $request );
		$query_args = $this->prepare_items_query( $args, $request );

		$taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
			$tax_exclude = $base . '_exclude';

			if ( ! empty( $request[ $base ] ) ) {
				$query_args['tax_query'][] = array(
					'taxonomy'         => $taxonomy->name,
					'field'            => 'term_id',
					'terms'            => $request[ $base ],
					'include_children' => false,
				);
			}

			if ( ! empty( $request[ $tax_exclude ] ) ) {
				$query_args['tax_query'][] = array(
					'taxonomy'         => $taxonomy->name,
					'field'            => 'term_id',
					'terms'            => $request[ $tax_exclude ],
					'include_children' => false,
					'operator'         => 'NOT IN',
				);
			}
		}

		$posts_query  = new WP_Query();
		$query_result = $posts_query->query( $query_args );

		// Allow access to all password protected posts if the context is edit.
		if ( 'edit' === $request['context'] ) {
			add_filter( 'post_password_required', '__return_false' );
		}

		$posts = array();

		foreach ( $query_result as $post ) {
			if ( ! $this->check_read_permission( $post ) ) {
				continue;
			}

			$data    = $this->prepare_item_for_response( $post, $request );
			$posts[] = $this->prepare_response_for_collection( $data );
		}

		// Reset filter.
		if ( 'edit' === $request['context'] ) {
			remove_filter( 'post_password_required', '__return_false' );
		}

		$page = (int) $query_args['paged'];
		$total_posts = $posts_query->found_posts;

		if ( $total_posts < 1 ) {
			// Out-of-bounds, run the query again without LIMIT for total count.
			unset( $query_args['paged'] );

			$count_query = new WP_Query();
			$count_query->query( $query_args );
			$total_posts = $count_query->found_posts;
		}

		$max_pages = ceil( $total_posts / (int) $posts_query->query_vars['posts_per_page'] );
		$response  = rest_ensure_response( $posts );

		$response->header( 'X-WP-Total', (int) $total_posts );
		$response->header( 'X-WP-TotalPages', (int) $max_pages );

		$request_params = $request->get_query_params();
		$base = add_query_arg( $request_params, rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ) );

		if ( $page > 1 ) {
			$prev_page = $page - 1;

			if ( $prev_page > $max_pages ) {
				$prev_page = $max_pages;
			}

			$prev_link = add_query_arg( 'page', $prev_page, $base );
			$response->link_header( 'prev', $prev_link );
		}
		if ( $max_pages > $page ) {
			$next_page = $page + 1;
			$next_link = add_query_arg( 'page', $next_page, $base );

			$response->link_header( 'next', $next_link );
		}

		return $response;
	}

	/**
	 * Checks if a given request has access to read a post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return bool|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {

		$post = $this->get_post( (int) $request['id'] );

		if ( 'edit' === $request['context'] && $post && ! $this->check_update_permission( $post ) ) {
			return new WP_Error( 'rest_forbidden_context', __( 'Sorry, you are not allowed to edit this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $post && ! empty( $request['password'] ) ) {
			// Check post password, and return error if invalid.
			if ( ! hash_equals( $post->post_password, $request['password'] ) ) {
				return new WP_Error( 'rest_post_incorrect_password', __( 'Incorrect post password.' ), array( 'status' => 403 ) );
			}
		}

		// Allow access to all password protected posts if the context is edit.
		if ( 'edit' === $request['context'] ) {
			add_filter( 'post_password_required', '__return_false' );
		}

		if ( $post ) {
			return $this->check_read_permission( $post );
		}

		return true;
	}

	/**
	 * Checks if the user can access password-protected content.
	 *
	 * This method determines whether we need to override the regular password
	 * check in core with a filter.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param WP_Post         $post    Post to check against.
	 * @param WP_REST_Request $request Request data to check.
	 * @return bool True if the user can access password-protected content, otherwise false.
	 */
	protected function can_access_password_content( $post, $request ) {
		if ( empty( $post->post_password ) ) {
			// No filter required.
			return false;
		}

		// Edit context always gets access to password-protected posts.
		if ( 'edit' === $request['context'] ) {
			return true;
		}

		// No password, no auth.
		if ( empty( $request['password'] ) ) {
			return false;
		}

		// Double-check the request password.
		return hash_equals( $post->post_password, $request['password'] );
	}

	/**
	 * Retrieves a single post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function get_item( $request ) {
		$id   = (int) $request['id'];
		$post = $this->get_post( $id );

		if ( empty( $id ) || empty( $post->ID ) || $this->post_type !== $post->post_type ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		$data     = $this->prepare_item_for_response( $post, $request );
		$response = rest_ensure_response( $data );

		if ( is_post_type_viewable( get_post_type_object( $post->post_type ) ) ) {
			$response->link_header( 'alternate',  get_permalink( $id ), array( 'type' => 'text/html' ) );
		}

		return $response;
	}

	/**
	 * Checks if a given request has access to create a post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create items, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {

		$post_type = get_post_type_object( $this->post_type );

		if ( ! empty( $request['author'] ) && get_current_user_id() !== $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_edit_others', __( 'You are not allowed to create posts as this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! empty( $request['sticky'] ) && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_assign_sticky', __( 'You do not have permission to make posts sticky.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! current_user_can( $post_type->cap->create_posts ) ) {
			return new WP_Error( 'rest_cannot_create', __( 'Sorry, you are not allowed to create new posts.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Creates a single post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error( 'rest_post_exists', __( 'Cannot create existing post.' ), array( 'status' => 400 ) );
		}

		$post = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$post->post_type = $this->post_type;
		$post_id         = wp_insert_post( $post, true );

		if ( is_wp_error( $post_id ) ) {

			if ( 'db_insert_error' === $post_id->get_error_code() ) {
				$post_id->add_data( array( 'status' => 500 ) );
			} else {
				$post_id->add_data( array( 'status' => 400 ) );
			}

			return $post_id;
		}

		$post->ID = $post_id;

		$schema = $this->get_item_schema();

		if ( ! empty( $schema['properties']['sticky'] ) ) {
			if ( ! empty( $request['sticky'] ) ) {
				stick_post( $post_id );
			} else {
				unstick_post( $post_id );
			}
		}

		if ( ! empty( $schema['properties']['featured_media'] ) && isset( $request['featured_media'] ) ) {
			$this->handle_featured_media( $request['featured_media'], $post->ID );
		}

		if ( ! empty( $schema['properties']['format'] ) && ! empty( $request['format'] ) ) {
			set_post_format( $post, $request['format'] );
		}

		if ( ! empty( $schema['properties']['template'] ) && isset( $request['template'] ) ) {
			$this->handle_template( $request['template'], $post->ID );
		}

		$terms_update = $this->handle_terms( $post->ID, $request );

		if ( is_wp_error( $terms_update ) ) {
			return $terms_update;
		}

		$post = $this->get_post( $post_id );

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], (int) $request['id'] );

			if ( is_wp_error( $meta_update ) ) {
				return $meta_update;
			}
		}

		$fields_update = $this->update_additional_fields_for_object( $post, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		/**
		 * Fires after a single post is created or updated via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @since 4.7.0
		 *
		 * @param object          $post     Inserted Post object (not a WP_Post object).
		 * @param WP_REST_Request $request  Request object.
		 * @param bool            $creating True when creating post, false when updating.
		 */
		do_action( "rest_insert_{$this->post_type}", $post, $request, true );

		$request->set_param( 'context', 'edit' );

		$response = $this->prepare_item_for_response( $post, $request );
		$response = rest_ensure_response( $response );

		$response->set_status( 201 );
		$response->header( 'Location', rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $post_id ) ) );

		return $response;
	}

	/**
	 * Checks if a given request has access to update a post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {

		$post = $this->get_post( $request['id'] );
		$post_type = get_post_type_object( $this->post_type );

		if ( $post && ! $this->check_update_permission( $post ) ) {
			return new WP_Error( 'rest_cannot_edit', __( 'Sorry, you are not allowed to update this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! empty( $request['author'] ) && get_current_user_id() !== $request['author'] && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_edit_others', __( 'You are not allowed to update posts as this user.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( ! empty( $request['sticky'] ) && ! current_user_can( $post_type->cap->edit_others_posts ) ) {
			return new WP_Error( 'rest_cannot_assign_sticky', __( 'You do not have permission to make posts sticky.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Updates a single post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$id   = (int) $request['id'];
		$post = $this->get_post( $id );

		if ( empty( $id ) || empty( $post->ID ) || $this->post_type !== $post->post_type ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Post id is invalid.' ), array( 'status' => 404 ) );
		}

		$post = $this->prepare_item_for_database( $request );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// convert the post object to an array, otherwise wp_update_post will expect non-escaped input.
		$post_id = wp_update_post( (array) $post, true );

		if ( is_wp_error( $post_id ) ) {
			if ( 'db_update_error' === $post_id->get_error_code() ) {
				$post_id->add_data( array( 'status' => 500 ) );
			} else {
				$post_id->add_data( array( 'status' => 400 ) );
			}
			return $post_id;
		}

		$schema = $this->get_item_schema();

		if ( ! empty( $schema['properties']['format'] ) && ! empty( $request['format'] ) ) {
			set_post_format( $post, $request['format'] );
		}

		if ( ! empty( $schema['properties']['featured_media'] ) && isset( $request['featured_media'] ) ) {
			$this->handle_featured_media( $request['featured_media'], $post_id );
		}

		if ( ! empty( $schema['properties']['sticky'] ) && isset( $request['sticky'] ) ) {
			if ( ! empty( $request['sticky'] ) ) {
				stick_post( $post_id );
			} else {
				unstick_post( $post_id );
			}
		}

		if ( ! empty( $schema['properties']['template'] ) && isset( $request['template'] ) ) {
			$this->handle_template( $request['template'], $post->ID );
		}

		$terms_update = $this->handle_terms( $post->ID, $request );

		if ( is_wp_error( $terms_update ) ) {
			return $terms_update;
		}

		$post = $this->get_post( $post_id );

		if ( ! empty( $schema['properties']['meta'] ) && isset( $request['meta'] ) ) {
			$meta_update = $this->meta->update_value( $request['meta'], $post->ID );

			if ( is_wp_error( $meta_update ) ) {
				return $meta_update;
			}
		}

		$fields_update = $this->update_additional_fields_for_object( $post, $request );

		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		/* This action is documented in lib/endpoints/class-wp-rest-controller.php */
		do_action( "rest_insert_{$this->post_type}", $post, $request, false );

		$request->set_param( 'context', 'edit' );

		$response = $this->prepare_item_for_response( $post, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Checks if a given request has access to delete a post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to delete the item, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {

		$post = $this->get_post( $request['id'] );

		if ( $post && ! $this->check_delete_permission( $post ) ) {
			return new WP_Error( 'rest_cannot_delete', __( 'Sorry, you are not allowed to delete posts.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		return true;
	}

	/**
	 * Deletes a single post.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request['force'];

		$post = $this->get_post( $id );

		if ( empty( $id ) || empty( $post->ID ) || $this->post_type !== $post->post_type ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post id.' ), array( 'status' => 404 ) );
		}

		$supports_trash = ( EMPTY_TRASH_DAYS > 0 );

		if ( 'attachment' === $post->post_type ) {
			$supports_trash = $supports_trash && MEDIA_TRASH;
		}

		/**
		 * Filters whether a post is trashable.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * Pass false to disable trash support for the post.
		 *
		 * @since 4.7.0
		 *
		 * @param bool    $supports_trash Whether the post type support trashing.
		 * @param WP_Post $post           The Post object being considered for trashing support.
		 */
		$supports_trash = apply_filters( "rest_{$this->post_type}_trashable", $supports_trash, $post );

		if ( ! $this->check_delete_permission( $post ) ) {
			return new WP_Error( 'rest_user_cannot_delete_post', __( 'Sorry, you are not allowed to delete this post.' ), array( 'status' => rest_authorization_required_code() ) );
		}

		$request->set_param( 'context', 'edit' );

		$response = $this->prepare_item_for_response( $post, $request );

		// If we're forcing, then delete permanently.
		if ( $force ) {
			$result = wp_delete_post( $id, true );
		} else {
			// If we don't support trashing for this type, error out.
			if ( ! $supports_trash ) {
				return new WP_Error( 'rest_trash_not_supported', __( 'The post does not support trashing.' ), array( 'status' => 501 ) );
			}

			// Otherwise, only trash if we haven't already.
			if ( 'trash' === $post->post_status ) {
				return new WP_Error( 'rest_already_trashed', __( 'The post has already been deleted.' ), array( 'status' => 410 ) );
			}

			// (Note that internally this falls through to `wp_delete_post` if
			// the trash is disabled.)
			$result = wp_trash_post( $id );
		}

		if ( ! $result ) {
			return new WP_Error( 'rest_cannot_delete', __( 'The post cannot be deleted.' ), array( 'status' => 500 ) );
		}

		/**
		 * Fires immediately after a single post is deleted or trashed via the REST API.
		 *
		 * They dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @since 4.7.0
		 *
		 * @param object           $post     The deleted or trashed post.
		 * @param WP_REST_Response $response The response data.
		 * @param WP_REST_Request  $request  The request sent to the API.
		 */
		do_action( "rest_delete_{$this->post_type}", $post, $response, $request );

		return $response;
	}

	/**
	 * Determines the allowed query_vars for a get_items() response and prepares
	 * them for WP_Query.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param array           $prepared_args Optional. Prepared WP_Query arguments. Default empty array.
	 * @param WP_REST_Request $request       Optional. Full details about the request.
	 * @return array Items query arguments.
	 */
	protected function prepare_items_query( $prepared_args = array(), $request = null ) {

		$valid_vars = array_flip( $this->get_allowed_query_vars( $request ) );
		$query_args = array();

		foreach ( $valid_vars as $var => $index ) {
			if ( isset( $prepared_args[ $var ] ) ) {
				/**
				 * Filters the query_vars used in get_items() for the constructed query.
				 *
				 * The dynamic portion of the hook name, `$var`, refers to the query_var key.
				 *
				 * @since 4.7.0
				 *
				 * @param string $var The query_var value.
				 */
				$query_args[ $var ] = apply_filters( "rest_query_var-{$var}", $prepared_args[ $var ] );
			}
		}

		if ( 'post' !== $this->post_type || ! isset( $query_args['ignore_sticky_posts'] ) ) {
			$query_args['ignore_sticky_posts'] = true;
		}

		if ( 'include' === $query_args['orderby'] ) {
			$query_args['orderby'] = 'post__in';
		}

		return $query_args;
	}

	/**
	 * Retrieves all of the WP Query vars that are allowed for the REST API request.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param WP_REST_Request $request Optional. Full details about the request.
	 * @return array Allowed query variables.
	 */
	protected function get_allowed_query_vars( $request = null ) {
		global $wp;

		/**
		 * Filters the publicly allowed query vars.
		 *
		 * Allows adjusting of the default query vars that are made public.
		 *
		 * @since 4.7.0
		 *
		 * @param array  Array of allowed WP_Query query vars.
		 */
		$valid_vars = apply_filters( 'query_vars', $wp->public_query_vars );

		$post_type_obj = get_post_type_object( $this->post_type );
		if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
			/**
			 * Filters the allowed 'private' query vars for authorized users.
			 *
			 * If the user has the `edit_posts` capability, we also allow use of
			 * private query parameters, which are only undesirable on the
			 * frontend, but are safe for use in query strings.
			 *
			 * To disable anyway, use
			 * `add_filter( 'rest_private_query_vars', '__return_empty_array' );`
			 *
			 * @since 4.7.0
			 *
			 * @param array $private_query_vars Array of allowed query vars for authorized users.
			 */
			$private = apply_filters( 'rest_private_query_vars', $wp->private_query_vars );

			$valid_vars = array_merge( $valid_vars, $private );
		}

		// Define our own in addition to WP's normal vars.
		$rest_valid = array(
			'author__in',
			'author__not_in',
			'ignore_sticky_posts',
			'menu_order',
			'offset',
			'post__in',
			'post__not_in',
			'post_parent',
			'post_parent__in',
			'post_parent__not_in',
			'posts_per_page',
			'date_query',
		);

		$valid_vars = array_merge( $valid_vars, $rest_valid );

		/**
		 * Filters allowed query vars for the REST API.
		 *
		 * This filter allows you to add or remove query vars from the final allowed
		 * list for all requests, including unauthenticated ones. To alter the
		 * vars for editors only, see {@see 'rest_private_query_vars'}.
		 *
		 * @since 4.7.0
		 *
		 * @param array {
		 *    Array of allowed WP_Query query vars.
		 *
		 *    @param string          $allowed_query_var The query var to allow.
		 *    @param WP_REST_Request $request           Request object.
		 * }
		 */
		$valid_vars = apply_filters( 'rest_query_vars', $valid_vars, $request );

		return $valid_vars;
	}

	/**
	 * Checks the post_date_gmt or modified_gmt and prepare any post or
	 * modified date for single post output.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param string      $date_gmt GMT publication time.
	 * @param string|null $date     Optional. Local publication time. Default null.
	 * @return string|null ISO8601/RFC3339 formatted datetime.
	 */
	protected function prepare_date_response( $date_gmt, $date = null ) {
		// Use the date if passed.
		if ( isset( $date ) ) {
			return mysql_to_rfc3339( $date );
		}

		// Return null if $date_gmt is empty/zeros.
		if ( '0000-00-00 00:00:00' === $date_gmt ) {
			return null;
		}

		// Return the formatted datetime.
		return mysql_to_rfc3339( $date_gmt );
	}

	/**
	 * Prepares a single post for create or update.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return stdClass|WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$prepared_post = new stdClass;

		// Post ID.
		if ( isset( $request['id'] ) ) {
			$prepared_post->ID = absint( $request['id'] );
		}

		$schema = $this->get_item_schema();

		// Post title.
		if ( ! empty( $schema['properties']['title'] ) && isset( $request['title'] ) ) {
			if ( is_string( $request['title'] ) ) {
				$prepared_post->post_title = wp_filter_post_kses( $request['title'] );
			} elseif ( ! empty( $request['title']['raw'] ) ) {
				$prepared_post->post_title = wp_filter_post_kses( $request['title']['raw'] );
			}
		}

		// Post content.
		if ( ! empty( $schema['properties']['content'] ) && isset( $request['content'] ) ) {
			if ( is_string( $request['content'] ) ) {
				$prepared_post->post_content = wp_filter_post_kses( $request['content'] );
			} elseif ( isset( $request['content']['raw'] ) ) {
				$prepared_post->post_content = wp_filter_post_kses( $request['content']['raw'] );
			}
		}

		// Post excerpt.
		if ( ! empty( $schema['properties']['excerpt'] ) && isset( $request['excerpt'] ) ) {
			if ( is_string( $request['excerpt'] ) ) {
				$prepared_post->post_excerpt = wp_filter_post_kses( $request['excerpt'] );
			} elseif ( isset( $request['excerpt']['raw'] ) ) {
				$prepared_post->post_excerpt = wp_filter_post_kses( $request['excerpt']['raw'] );
			}
		}

		// Post type.
		if ( empty( $request['id'] ) ) {
			// Creating new post, use default type for the controller.
			$prepared_post->post_type = $this->post_type;
		} else {
			// Updating a post, use previous type.
			$prepared_post->post_type = get_post_type( $request['id'] );
		}

		$post_type = get_post_type_object( $prepared_post->post_type );

		// Post status.
		if ( ! empty( $schema['properties']['status'] ) && isset( $request['status'] ) ) {
			$status = $this->handle_status_param( $request['status'], $post_type );

			if ( is_wp_error( $status ) ) {
				return $status;
			}

			$prepared_post->post_status = $status;
		}

		// Post date.
		if ( ! empty( $schema['properties']['date'] ) && ! empty( $request['date'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['date'] );

			if ( ! empty( $date_data ) ) {
				list( $prepared_post->post_date, $prepared_post->post_date_gmt ) = $date_data;
			}
		} elseif ( ! empty( $schema['properties']['date_gmt'] ) && ! empty( $request['date_gmt'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['date_gmt'], true );

			if ( ! empty( $date_data ) ) {
				list( $prepared_post->post_date, $prepared_post->post_date_gmt ) = $date_data;
			}
		}

		// Post slug.
		if ( ! empty( $schema['properties']['slug'] ) && isset( $request['slug'] ) ) {
			$prepared_post->post_name = $request['slug'];
		}

		// Author.
		if ( ! empty( $schema['properties']['author'] ) && ! empty( $request['author'] ) ) {
			$post_author = (int) $request['author'];

			if ( get_current_user_id() !== $post_author ) {
				$user_obj = get_userdata( $post_author );

				if ( ! $user_obj ) {
					return new WP_Error( 'rest_invalid_author', __( 'Invalid author id.' ), array( 'status' => 400 ) );
				}
			}

			$prepared_post->post_author = $post_author;
		}

		// Post password.
		if ( ! empty( $schema['properties']['password'] ) && isset( $request['password'] ) && '' !== $request['password'] ) {
			$prepared_post->post_password = $request['password'];

			if ( ! empty( $schema['properties']['sticky'] ) && ! empty( $request['sticky'] ) ) {
				return new WP_Error( 'rest_invalid_field', __( 'A post can not be sticky and have a password.' ), array( 'status' => 400 ) );
			}

			if ( ! empty( $prepared_post->ID ) && is_sticky( $prepared_post->ID ) ) {
				return new WP_Error( 'rest_invalid_field', __( 'A sticky post can not be password protected.' ), array( 'status' => 400 ) );
			}
		}

		if ( ! empty( $schema['properties']['sticky'] ) && ! empty( $request['sticky'] ) ) {
			if ( ! empty( $prepared_post->ID ) && post_password_required( $prepared_post->ID ) ) {
				return new WP_Error( 'rest_invalid_field', __( 'A password protected post can not be set to sticky.' ), array( 'status' => 400 ) );
			}
		}

		// Parent.
		if ( ! empty( $schema['properties']['parent'] ) && ! empty( $request['parent'] ) ) {
			$parent = $this->get_post( (int) $request['parent'] );

			if ( empty( $parent ) ) {
				return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post parent id.' ), array( 'status' => 400 ) );
			}

			$prepared_post->post_parent = (int) $parent->ID;
		}

		// Menu order.
		if ( ! empty( $schema['properties']['menu_order'] ) && isset( $request['menu_order'] ) ) {
			$prepared_post->menu_order = (int) $request['menu_order'];
		}

		// Comment status.
		if ( ! empty( $schema['properties']['comment_status'] ) && ! empty( $request['comment_status'] ) ) {
			$prepared_post->comment_status = $request['comment_status'];
		}

		// Ping status.
		if ( ! empty( $schema['properties']['ping_status'] ) && ! empty( $request['ping_status'] ) ) {
			$prepared_post->ping_status = $request['ping_status'];
		}

		/**
		 * Filters a post before it is inserted via the REST API.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @since 4.7.0
		 *
		 * @param stdClass        $prepared_post An object representing a single post prepared
		 *                                       for inserting or updating the database.
		 * @param WP_REST_Request $request       Request object.
		 */
		return apply_filters( "rest_pre_insert_{$this->post_type}", $prepared_post, $request );

	}

	/**
	 * Determines validity and normalizes the given status parameter.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param string $post_status Post status.
	 * @param object $post_type   Post type.
	 * @return string|WP_Error Post status or WP_Error if lacking the proper permission.
	 */
	protected function handle_status_param( $post_status, $post_type ) {

		switch ( $post_status ) {
			case 'draft':
			case 'pending':
				break;
			case 'private':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) {
					return new WP_Error( 'rest_cannot_publish', __( 'Sorry, you are not allowed to create private posts in this post type.' ), array( 'status' => rest_authorization_required_code() ) );
				}
				break;
			case 'publish':
			case 'future':
				if ( ! current_user_can( $post_type->cap->publish_posts ) ) {
					return new WP_Error( 'rest_cannot_publish', __( 'Sorry, you are not allowed to publish posts in this post type.' ), array( 'status' => rest_authorization_required_code() ) );
				}
				break;
			default:
				if ( ! get_post_status_object( $post_status ) ) {
					$post_status = 'draft';
				}
				break;
		}

		return $post_status;
	}

	/**
	 * Determines the featured media based on a request param.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param int $featured_media Featured Media ID.
	 * @param int $post_id        Post ID.
	 * @return bool|WP_Error Whether the post thumbnail was successfully deleted, otherwise WP_Error.
	 */
	protected function handle_featured_media( $featured_media, $post_id ) {

		$featured_media = (int) $featured_media;
		if ( $featured_media ) {
			$result = set_post_thumbnail( $post_id, $featured_media );
			if ( $result ) {
				return true;
			} else {
				return new WP_Error( 'rest_invalid_featured_media', __( 'Invalid featured media id.' ), array( 'status' => 400 ) );
			}
		} else {
			return delete_post_thumbnail( $post_id );
		}

	}

	/**
	 * Sets the template for a page.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param string  $template Page template filename.
	 * @param integer $post_id  Post ID.
	 */
	public function handle_template( $template, $post_id ) {
		if ( in_array( $template, array_keys( wp_get_theme()->get_page_templates( $this->get_post( $post_id ) ) ), true ) ) {
			update_post_meta( $post_id, '_wp_page_template', $template );
		} else {
			update_post_meta( $post_id, '_wp_page_template', '' );
		}
	}

	/**
	 * Updates the post's terms from a REST request.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param int             $post_id The post ID to update the terms form.
	 * @param WP_REST_Request $request The request object with post and terms data.
	 * @return null|WP_Error WP_Error on an error assigning any of the terms, otherwise null.
	 */
	protected function handle_terms( $post_id, $request ) {
		$taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			if ( ! isset( $request[ $base ] ) ) {
				continue;
			}

			$terms  = array_map( 'absint', $request[ $base ] );
			$result = wp_set_object_terms( $post_id, $terms, $taxonomy->name );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	/**
	 * Checks if a given post type can be viewed or managed.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param object|string $post_type Post type name or object.
	 * @return bool Whether the post type is allowed in REST.
	 */
	protected function check_is_post_type_allowed( $post_type ) {
		if ( ! is_object( $post_type ) ) {
			$post_type = get_post_type_object( $post_type );
		}

		if ( ! empty( $post_type ) && ! empty( $post_type->show_in_rest ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a post can be read.
	 *
	 * Correctly handles posts with the inherit status.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param object $post Post object.
	 * @return bool Whether the post can be read.
	 */
	public function check_read_permission( $post ) {
		$post_type = get_post_type_object( $post->post_type );
		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		// Is the post readable?
		if ( 'publish' === $post->post_status || current_user_can( $post_type->cap->read_post, $post->ID ) ) {
			return true;
		}

		$post_status_obj = get_post_status_object( $post->post_status );
		if ( $post_status_obj && $post_status_obj->public ) {
			return true;
		}

		// Can we read the parent if we're inheriting?
		if ( 'inherit' === $post->post_status && $post->post_parent > 0 ) {
			$parent = $this->get_post( $post->post_parent );
			return $this->check_read_permission( $parent );
		}

		/*
		 * If there isn't a parent, but the status is set to inherit, assume
		 * it's published (as per get_post_status()).
		 */
		if ( 'inherit' === $post->post_status ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a post can be edited.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param object $post Post object.
	 * @return bool Whether the post can be edited.
	 */
	protected function check_update_permission( $post ) {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( $post_type->cap->edit_post, $post->ID );
	}

	/**
	 * Checks if a post can be created.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param object $post Post object.
	 * @return bool Whether the post can be created.
	 */
	protected function check_create_permission( $post ) {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( $post_type->cap->create_posts );
	}

	/**
	 * Checks if a post can be deleted.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param object $post Post object.
	 * @return bool Whether the post can be deleted.
	 */
	protected function check_delete_permission( $post ) {
		$post_type = get_post_type_object( $post->post_type );

		if ( ! $this->check_is_post_type_allowed( $post_type ) ) {
			return false;
		}

		return current_user_can( $post_type->cap->delete_post, $post->ID );
	}

	/**
	 * Prepares a single post output for response.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param WP_Post         $post    Post object.
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $post, $request ) {
		$GLOBALS['post'] = $post;

		setup_postdata( $post );

		$schema = $this->get_item_schema();

		// Base fields for every post.
		$data = array();

		if ( ! empty( $schema['properties']['id'] ) ) {
			$data['id'] = $post->ID;
		}

		if ( ! empty( $schema['properties']['date'] ) ) {
			$data['date'] = $this->prepare_date_response( $post->post_date_gmt, $post->post_date );
		}

		if ( ! empty( $schema['properties']['date_gmt'] ) ) {
			$data['date_gmt'] = $this->prepare_date_response( $post->post_date_gmt );
		}

		if ( ! empty( $schema['properties']['guid'] ) ) {
			$data['guid'] = array(
				/** This filter is documented in wp-includes/post-template.php */
				'rendered' => apply_filters( 'get_the_guid', $post->guid ),
				'raw'      => $post->guid,
			);
		}

		if ( ! empty( $schema['properties']['modified'] ) ) {
			$data['modified'] = $this->prepare_date_response( $post->post_modified_gmt, $post->post_modified );
		}

		if ( ! empty( $schema['properties']['modified_gmt'] ) ) {
			$data['modified_gmt'] = $this->prepare_date_response( $post->post_modified_gmt );
		}

		if ( ! empty( $schema['properties']['password'] ) ) {
			$data['password'] = $post->post_password;
		}

		if ( ! empty( $schema['properties']['slug'] ) ) {
			$data['slug'] = $post->post_name;
		}

		if ( ! empty( $schema['properties']['status'] ) ) {
			$data['status'] = $post->post_status;
		}

		if ( ! empty( $schema['properties']['type'] ) ) {
			$data['type'] = $post->post_type;
		}

		if ( ! empty( $schema['properties']['link'] ) ) {
			$data['link'] = get_permalink( $post->ID );
		}

		if ( ! empty( $schema['properties']['title'] ) ) {
			add_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );

			$data['title'] = array(
				'raw'      => $post->post_title,
				'rendered' => get_the_title( $post->ID ),
			);

			remove_filter( 'protected_title_format', array( $this, 'protected_title_format' ) );
		}

		$has_password_filter = false;

		if ( $this->can_access_password_content( $post, $request ) ) {
			// Allow access to the post, permissions already checked before.
			add_filter( 'post_password_required', '__return_false' );

			$has_password_filter = true;
		}

		if ( ! empty( $schema['properties']['content'] ) ) {
			$data['content'] = array(
				'raw'       => $post->post_content,
				/** This filter is documented in wp-includes/post-template.php */
				'rendered'  => post_password_required( $post ) ? '' : apply_filters( 'the_content', $post->post_content ),
				'protected' => (bool) $post->post_password,
			);
		}

		if ( ! empty( $schema['properties']['excerpt'] ) ) {
			/** This filter is documented in wp-includes/post-template.php */
			$excerpt = apply_filters( 'the_excerpt', apply_filters( 'get_the_excerpt', $post->post_excerpt, $post ) );
			$data['excerpt'] = array(
				'raw'       => $post->post_excerpt,
				'rendered'  => post_password_required( $post ) ? '' : $excerpt,
				'protected' => (bool) $post->post_password,
			);
		}

		if ( $has_password_filter ) {
			// Reset filter.
			remove_filter( 'post_password_required', '__return_false' );
		}

		if ( ! empty( $schema['properties']['author'] ) ) {
			$data['author'] = (int) $post->post_author;
		}

		if ( ! empty( $schema['properties']['featured_media'] ) ) {
			$data['featured_media'] = (int) get_post_thumbnail_id( $post->ID );
		}

		if ( ! empty( $schema['properties']['parent'] ) ) {
			$data['parent'] = (int) $post->post_parent;
		}

		if ( ! empty( $schema['properties']['menu_order'] ) ) {
			$data['menu_order'] = (int) $post->menu_order;
		}

		if ( ! empty( $schema['properties']['comment_status'] ) ) {
			$data['comment_status'] = $post->comment_status;
		}

		if ( ! empty( $schema['properties']['ping_status'] ) ) {
			$data['ping_status'] = $post->ping_status;
		}

		if ( ! empty( $schema['properties']['sticky'] ) ) {
			$data['sticky'] = is_sticky( $post->ID );
		}

		if ( ! empty( $schema['properties']['template'] ) ) {
			if ( $template = get_page_template_slug( $post->ID ) ) {
				$data['template'] = $template;
			} else {
				$data['template'] = '';
			}
		}

		if ( ! empty( $schema['properties']['format'] ) ) {
			$data['format'] = get_post_format( $post->ID );

			// Fill in blank post format.
			if ( empty( $data['format'] ) ) {
				$data['format'] = 'standard';
			}
		}

		if ( ! empty( $schema['properties']['meta'] ) ) {
			$data['meta'] = $this->meta->get_value( $post->ID, $request );
		}

		$taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			if ( ! empty( $schema['properties'][ $base ] ) ) {
				$terms = get_the_terms( $post, $taxonomy->name );
				$data[ $base ] = $terms ? array_values( wp_list_pluck( $terms, 'term_id' ) ) : array();
			}
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		// Wrap the data in a response object.
		$response = rest_ensure_response( $data );

		$response->add_links( $this->prepare_links( $post ) );

		/**
		 * Filters the post data for a response.
		 *
		 * The dynamic portion of the hook name, `$this->post_type`, refers to the post type slug.
		 *
		 * @since 4.7.0
		 *
		 * @param WP_REST_Response $response The response object.
		 * @param WP_Post          $post     Post object.
		 * @param WP_REST_Request  $request  Request object.
		 */
		return apply_filters( "rest_prepare_{$this->post_type}", $response, $post, $request );
	}

	/**
	 * Overwrites the default protected title format.
	 *
	 * By default, WordPress will show password protected posts with a title of
	 * "Protected: %s", as the REST API communicates the protected status of a post
	 * in a machine readable format, we remove the "Protected: " prefix.
	 *
	 * @return string Protected title format.
	 */
	public function protected_title_format() {
		return '%s';
	}

	/**
	 * Prepares links for the request.
	 *
	 * @since 4.7.0
	 * @access protected
	 *
	 * @param WP_Post $post Post object.
	 * @return array Links for the given post.
	 */
	protected function prepare_links( $post ) {
		$base = sprintf( '%s/%s', $this->namespace, $this->rest_base );

		// Entity meta.
		$links = array(
			'self' => array(
				'href'   => rest_url( trailingslashit( $base ) . $post->ID ),
			),
			'collection' => array(
				'href'   => rest_url( $base ),
			),
			'about'      => array(
				'href'   => rest_url( 'wp/v2/types/' . $this->post_type ),
			),
		);

		if ( ( in_array( $post->post_type, array( 'post', 'page' ), true ) || post_type_supports( $post->post_type, 'author' ) )
			&& ! empty( $post->post_author ) ) {
			$links['author'] = array(
				'href'       => rest_url( 'wp/v2/users/' . $post->post_author ),
				'embeddable' => true,
			);
		}

		if ( in_array( $post->post_type, array( 'post', 'page' ), true ) || post_type_supports( $post->post_type, 'comments' ) ) {
			$replies_url = rest_url( 'wp/v2/comments' );
			$replies_url = add_query_arg( 'post', $post->ID, $replies_url );

			$links['replies'] = array(
				'href'       => $replies_url,
				'embeddable' => true,
			);
		}

		if ( in_array( $post->post_type, array( 'post', 'page' ), true ) || post_type_supports( $post->post_type, 'revisions' ) ) {
			$links['version-history'] = array(
				'href' => rest_url( trailingslashit( $base ) . $post->ID . '/revisions' ),
			);
		}

		$post_type_obj = get_post_type_object( $post->post_type );

		if ( $post_type_obj->hierarchical && ! empty( $post->post_parent ) ) {
			$links['up'] = array(
				'href'       => rest_url( trailingslashit( $base ) . (int) $post->post_parent ),
				'embeddable' => true,
			);
		}

		// If we have a featured media, add that.
		if ( $featured_media = get_post_thumbnail_id( $post->ID ) ) {
			$image_url = rest_url( 'wp/v2/media/' . $featured_media );

			$links['https://api.w.org/featuredmedia'] = array(
				'href'       => $image_url,
				'embeddable' => true,
			);
		}

		if ( ! in_array( $post->post_type, array( 'attachment', 'nav_menu_item', 'revision' ), true ) ) {
			$attachments_url = rest_url( 'wp/v2/media' );
			$attachments_url = add_query_arg( 'parent', $post->ID, $attachments_url );

			$links['https://api.w.org/attachment'] = array(
				'href' => $attachments_url,
			);
		}

		$taxonomies = get_object_taxonomies( $post->post_type );

		if ( ! empty( $taxonomies ) ) {
			$links['https://api.w.org/term'] = array();

			foreach ( $taxonomies as $tax ) {
				$taxonomy_obj = get_taxonomy( $tax );

				// Skip taxonomies that are not public.
				if ( empty( $taxonomy_obj->show_in_rest ) ) {
					continue;
				}

				$tax_base = ! empty( $taxonomy_obj->rest_base ) ? $taxonomy_obj->rest_base : $tax;

				$terms_url = add_query_arg(
					'post',
					$post->ID,
					rest_url( 'wp/v2/' . $tax_base )
				);

				$links['https://api.w.org/term'][] = array(
					'href'       => $terms_url,
					'taxonomy'   => $tax,
					'embeddable' => true,
				);
			}
		}

		return $links;
	}

	/**
	 * Retrieves the post's schema, conforming to JSON Schema.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {

		$schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => $this->post_type,
			'type'       => 'object',
			// Base properties for every Post.
			'properties' => array(
				'date'            => array(
					'description' => __( "The date the object was published, in the site's timezone." ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit', 'embed' ),
				),
				'date_gmt'        => array(
					'description' => __( 'The date the object was published, as GMT.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
				),
				'guid'            => array(
					'description' => __( 'The globally unique identifier for the object.' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
					'properties'  => array(
						'raw'      => array(
							'description' => __( 'GUID for the object, as it exists in the database.' ),
							'type'        => 'string',
							'context'     => array( 'edit' ),
							'readonly'    => true,
						),
						'rendered' => array(
							'description' => __( 'GUID for the object, transformed for display.' ),
							'type'        => 'string',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
				),
				'id'              => array(
					'description' => __( 'Unique identifier for the object.' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'link'            => array(
					'description' => __( 'URL to the object.' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
				'modified'        => array(
					'description' => __( "The date the object was last modified, in the site's timezone." ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'modified_gmt'    => array(
					'description' => __( 'The date the object was last modified, as GMT.' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'slug'            => array(
					'description' => __( 'An alphanumeric identifier for the object unique to its type.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'arg_options' => array(
						'sanitize_callback' => array( $this, 'sanitize_slug' ),
					),
				),
				'status'          => array(
					'description' => __( 'A named status for the object.' ),
					'type'        => 'string',
					'enum'        => array_keys( get_post_stati( array( 'internal' => false ) ) ),
					'context'     => array( 'edit' ),
				),
				'type'            => array(
					'description' => __( 'Type of Post for the object.' ),
					'type'        => 'string',
					'context'     => array( 'view', 'edit', 'embed' ),
					'readonly'    => true,
				),
			),
		);

		$post_type_obj = get_post_type_object( $this->post_type );

		if ( $post_type_obj->hierarchical ) {
			$schema['properties']['parent'] = array(
				'description' => __( 'The id for the parent of the object.' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
			);
		}

		$post_type_attributes = array(
			'title',
			'editor',
			'author',
			'excerpt',
			'thumbnail',
			'comments',
			'revisions',
			'page-attributes',
			'post-formats',
			'custom-fields',
		);
		$fixed_schemas = array(
			'post' => array(
				'title',
				'editor',
				'author',
				'excerpt',
				'thumbnail',
				'comments',
				'revisions',
				'post-formats',
				'custom-fields',
			),
			'page' => array(
				'title',
				'editor',
				'author',
				'excerpt',
				'thumbnail',
				'comments',
				'revisions',
				'page-attributes',
				'custom-fields',
			),
			'attachment' => array(
				'title',
				'author',
				'comments',
				'revisions',
				'custom-fields',
			),
		);
		foreach ( $post_type_attributes as $attribute ) {
			if ( isset( $fixed_schemas[ $this->post_type ] ) && ! in_array( $attribute, $fixed_schemas[ $this->post_type ], true ) ) {
				continue;
			} elseif ( ! isset( $fixed_schemas[ $this->post_type ] ) && ! post_type_supports( $this->post_type, $attribute ) ) {
				continue;
			}

			switch ( $attribute ) {

				case 'title':
					$schema['properties']['title'] = array(
						'description' => __( 'The title for the object.' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit', 'embed' ),
						'properties'  => array(
							'raw' => array(
								'description' => __( 'Title for the object, as it exists in the database.' ),
								'type'        => 'string',
								'context'     => array( 'edit' ),
							),
							'rendered' => array(
								'description' => __( 'HTML title for the object, transformed for display.' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit', 'embed' ),
								'readonly'    => true,
							),
						),
					);
					break;

				case 'editor':
					$schema['properties']['content'] = array(
						'description' => __( 'The content for the object.' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'properties'  => array(
							'raw' => array(
								'description' => __( 'Content for the object, as it exists in the database.' ),
								'type'        => 'string',
								'context'     => array( 'edit' ),
							),
							'rendered' => array(
								'description' => __( 'HTML content for the object, transformed for display.' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
							'protected'       => array(
								'description' => __( 'Whether the content is protected with a password.' ),
								'type'        => 'boolean',
								'context'     => array( 'view', 'edit', 'embed' ),
								'readonly'    => true,
							),
						),
					);
					break;

				case 'author':
					$schema['properties']['author'] = array(
						'description' => __( 'The id for the author of the object.' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit', 'embed' ),
					);
					break;

				case 'excerpt':
					$schema['properties']['excerpt'] = array(
						'description' => __( 'The excerpt for the object.' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit', 'embed' ),
						'properties'  => array(
							'raw' => array(
								'description' => __( 'Excerpt for the object, as it exists in the database.' ),
								'type'        => 'string',
								'context'     => array( 'edit' ),
							),
							'rendered' => array(
								'description' => __( 'HTML excerpt for the object, transformed for display.' ),
								'type'        => 'string',
								'context'     => array( 'view', 'edit', 'embed' ),
								'readonly'    => true,
							),
							'protected'       => array(
								'description' => __( 'Whether the excerpt is protected with a password.' ),
								'type'        => 'boolean',
								'context'     => array( 'view', 'edit', 'embed' ),
								'readonly'    => true,
							),
						),
					);
					break;

				case 'thumbnail':
					$schema['properties']['featured_media'] = array(
						'description' => __( 'The id of the featured media for the object.' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					);
					break;

				case 'comments':
					$schema['properties']['comment_status'] = array(
						'description' => __( 'Whether or not comments are open on the object.' ),
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'context'     => array( 'view', 'edit' ),
					);
					$schema['properties']['ping_status'] = array(
						'description' => __( 'Whether or not the object can be pinged.' ),
						'type'        => 'string',
						'enum'        => array( 'open', 'closed' ),
						'context'     => array( 'view', 'edit' ),
					);
					break;

				case 'page-attributes':
					$schema['properties']['menu_order'] = array(
						'description' => __( 'The order of the object in relation to other object of its type.' ),
						'type'        => 'integer',
						'context'     => array( 'view', 'edit' ),
					);
					break;

				case 'post-formats':
					$schema['properties']['format'] = array(
						'description' => __( 'The format for the object.' ),
						'type'        => 'string',
						'enum'        => array_values( get_post_format_slugs() ),
						'context'     => array( 'view', 'edit' ),
					);
					break;

				case 'custom-fields':
					$schema['properties']['meta'] = $this->meta->get_field_schema();
					break;

			}
		}

		if ( 'post' === $this->post_type ) {
			$schema['properties']['sticky'] = array(
				'description' => __( 'Whether or not the object should be treated as sticky.' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
			);

			$schema['properties']['password'] = array(
				'description' => __( 'A password to protect access to the content and excerpt.' ),
				'type'        => 'string',
				'context'     => array( 'edit' ),
			);
		}

		if ( 'page' === $this->post_type ) {
			$schema['properties']['template'] = array(
				'description' => __( 'The theme file to use to display the object.' ),
				'type'        => 'string',
				'enum'        => array_keys( wp_get_theme()->get_page_templates() ),
				'context'     => array( 'view', 'edit' ),
			);
		}

		$taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );
		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;
			$schema['properties'][ $base ] = array(
				'description' => sprintf( __( 'The terms assigned to the object in the %s taxonomy.' ), $taxonomy->name ),
				'type'        => 'array',
				'items'       => array(
					'type'    => 'integer',
				),
				'context'     => array( 'view', 'edit' ),
			);
			$schema['properties'][ $base . '_exclude' ] = array(
				'description' => sprintf( __( 'The terms in the %s taxonomy that should not be assigned to the object.' ), $taxonomy->name ),
				'type'        => 'array',
				'context'     => array( 'view', 'edit' ),
			);
		}

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Retrieves the query params for the posts collection.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @return array Collection parameters.
	 */
	public function get_collection_params() {
		$params = parent::get_collection_params();

		$params['context']['default'] = 'view';

		$params['after'] = array(
			'description'        => __( 'Limit response to resources published after a given ISO8601 compliant date.' ),
			'type'               => 'string',
			'format'             => 'date-time',
			'validate_callback'  => 'rest_validate_request_arg',
		);

		if ( post_type_supports( $this->post_type, 'author' ) ) {
			$params['author'] = array(
				'description'         => __( 'Limit result set to posts assigned to specific authors.' ),
				'type'                => 'array',
				'default'             => array(),
				'sanitize_callback'   => 'wp_parse_id_list',
			);
			$params['author_exclude'] = array(
				'description'         => __( 'Ensure result set excludes posts assigned to specific authors.' ),
				'type'                => 'array',
				'default'             => array(),
				'sanitize_callback'   => 'wp_parse_id_list',
			);
		}

		$params['before'] = array(
			'description'        => __( 'Limit response to resources published before a given ISO8601 compliant date.' ),
			'type'               => 'string',
			'format'             => 'date-time',
			'validate_callback'  => 'rest_validate_request_arg',
		);

		$params['exclude'] = array(
			'description'        => __( 'Ensure result set excludes specific ids.' ),
			'type'               => 'array',
			'default'            => array(),
			'sanitize_callback'  => 'wp_parse_id_list',
		);

		$params['include'] = array(
			'description'        => __( 'Limit result set to specific ids.' ),
			'type'               => 'array',
			'default'            => array(),
			'sanitize_callback'  => 'wp_parse_id_list',
		);

		if ( 'page' === $this->post_type || post_type_supports( $this->post_type, 'page-attributes' ) ) {
			$params['menu_order'] = array(
				'description'        => __( 'Limit result set to resources with a specific menu_order value.' ),
				'type'               => 'integer',
				'sanitize_callback'  => 'absint',
				'validate_callback'  => 'rest_validate_request_arg',
			);
		}

		$params['offset'] = array(
			'description'        => __( 'Offset the result set by a specific number of items.' ),
			'type'               => 'integer',
			'sanitize_callback'  => 'absint',
			'validate_callback'  => 'rest_validate_request_arg',
		);

		$params['order'] = array(
			'description'        => __( 'Order sort attribute ascending or descending.' ),
			'type'               => 'string',
			'default'            => 'desc',
			'enum'               => array( 'asc', 'desc' ),
			'validate_callback'  => 'rest_validate_request_arg',
		);

		$params['orderby'] = array(
			'description'        => __( 'Sort collection by object attribute.' ),
			'type'               => 'string',
			'default'            => 'date',
			'enum'               => array(
				'date',
				'relevance',
				'id',
				'include',
				'title',
				'slug',
			),
			'validate_callback'  => 'rest_validate_request_arg',
		);

		if ( 'page' === $this->post_type || post_type_supports( $this->post_type, 'page-attributes' ) ) {
			$params['orderby']['enum'][] = 'menu_order';
		}

		$post_type_obj = get_post_type_object( $this->post_type );

		if ( $post_type_obj->hierarchical || 'attachment' === $this->post_type ) {
			$params['parent'] = array(
				'description'       => __( 'Limit result set to those of particular parent ids.' ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_id_list',
				'default'           => array(),
			);
			$params['parent_exclude'] = array(
				'description'       => __( 'Limit result set to all items except those of a particular parent id.' ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_id_list',
				'default'           => array(),
			);
		}

		$params['slug'] = array(
			'description'       => __( 'Limit result set to posts with a specific slug.' ),
			'type'              => 'string',
			'validate_callback' => 'rest_validate_request_arg',
		);

		$params['status'] = array(
			'default'           => 'publish',
			'description'       => __( 'Limit result set to posts assigned a specific status; can be comma-delimited list of status types.' ),
			'enum'              => array_merge( array_keys( get_post_stati() ), array( 'any' ) ),
			'sanitize_callback' => 'sanitize_key',
			'type'              => 'string',
			'validate_callback' => array( $this, 'validate_user_can_query_private_statuses' ),
		);

		$taxonomies = wp_list_filter( get_object_taxonomies( $this->post_type, 'objects' ), array( 'show_in_rest' => true ) );

		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			$params[ $base ] = array(
				'description'       => sprintf( __( 'Limit result set to all items that have the specified term assigned in the %s taxonomy.' ), $base ),
				'type'              => 'array',
				'sanitize_callback' => 'wp_parse_id_list',
				'default'           => array(),
			);
		}

		if ( 'post' === $this->post_type ) {
			$params['sticky'] = array(
				'description'       => __( 'Limit result set to items that are sticky.' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_parse_request_arg',
			);
		}

		return $params;
	}

	/**
	 * Validates whether the user can query private statuses.
	 *
	 * @since 4.7.0
	 * @access public
	 *
	 * @param  mixed           $value     Post status.
	 * @param  WP_REST_Request $request   Full details about the request.
	 * @param  string          $parameter Additional parameter to pass to validation.
	 * @return bool|WP_Error Whether the request can query private statuses, otherwise WP_Error object.
	 */
	public function validate_user_can_query_private_statuses( $value, $request, $parameter ) {
		if ( 'publish' === $value ) {
			return rest_validate_request_arg( $value, $request, $parameter );
		}

		$post_type_obj = get_post_type_object( $this->post_type );

		if ( current_user_can( $post_type_obj->cap->edit_posts ) ) {
			return rest_validate_request_arg( $value, $request, $parameter );
		}

		return new WP_Error( 'rest_forbidden_status', __( 'Status is forbidden.' ), array( 'status' => rest_authorization_required_code() ) );
	}
}
