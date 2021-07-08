<?php
/**
 * Unit tests covering WP_REST_Posts_Statuses_Controller functionality.
 *
 * @package WordPress
 * @subpackage REST API
 */

/**
 * @group restapi
 */
class WP_Test_REST_Post_Statuses_Controller extends WP_Test_REST_Controller_Testcase {

	public function test_register_routes() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/wp/v2/statuses', $routes );
		$this->assertArrayHasKey( '/wp/v2/statuses/(?P<status>[\w-]+)', $routes );
	}

	public function test_context_param() {
		// Collection.
		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/statuses' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 'view', $data['endpoints'][0]['args']['context']['default'] );
		$this->assertSameSets( array( 'embed', 'view', 'edit' ), $data['endpoints'][0]['args']['context']['enum'] );
		// Single.
		$request  = new WP_REST_Request( 'OPTIONS', '/wp/v2/statuses/publish' );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$this->assertSame( 'view', $data['endpoints'][0]['args']['context']['default'] );
		$this->assertSameSets( array( 'embed', 'view', 'edit' ), $data['endpoints'][0]['args']['context']['enum'] );
	}

	public function test_get_items() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/statuses' );
		$response = rest_get_server()->dispatch( $request );

		$data     = $response->get_data();
		$statuses = get_post_stati( array( 'public' => true ), 'objects' );
		$this->assertCount( 1, $data );
		$this->assertSame( 'publish', $data['publish']['slug'] );
	}

	public function test_get_items_logged_in() {
		$user_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/statuses' );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->assertCount( 6, $data );
		$this->assertSameSets(
			array(
				'publish',
				'private',
				'pending',
				'draft',
				'trash',
				'future',
			),
			array_keys( $data )
		);
	}

	public function test_get_items_unauthorized_context() {
		$request = new WP_REST_Request( 'GET', '/wp/v2/statuses' );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_view', $response, 401 );
	}

	public function test_get_item() {
		$user_id = $this->factory->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $user_id );
		$request = new WP_REST_Request( 'GET', '/wp/v2/statuses/publish' );
		$request->set_param( 'context', 'edit' );
		$response = rest_get_server()->dispatch( $request );
		$this->check_post_status_object_response( $response );
	}

	public function test_get_item_invalid_status() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/statuses/invalid' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_status_invalid', $response, 404 );
	}

	public function test_get_item_invalid_access() {
		wp_set_current_user( 0 );
		$request  = new WP_REST_Request( 'GET', '/wp/v2/statuses/draft' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_read_status', $response, 401 );
	}

	public function test_get_item_invalid_internal() {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$request  = new WP_REST_Request( 'GET', '/wp/v2/statuses/inherit' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertErrorResponse( 'rest_cannot_read_status', $response, 403 );
	}

	public function test_create_item() {
		/** Post statuses can't be created */
		$request  = new WP_REST_Request( 'POST', '/wp/v2/statuses' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_update_item() {
		/** Post statuses can't be updated */
		$request  = new WP_REST_Request( 'POST', '/wp/v2/statuses/draft' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_delete_item() {
		/** Post statuses can't be deleted */
		$request  = new WP_REST_Request( 'DELETE', '/wp/v2/statuses/draft' );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_prepare_item() {
		$obj      = get_post_status_object( 'publish' );
		$endpoint = new WP_REST_Post_Statuses_Controller;
		$request  = new WP_REST_Request;
		$request->set_param( 'context', 'edit' );
		$data = $endpoint->prepare_item_for_response( $obj, $request );
		$this->check_post_status_obj( $obj, $data->get_data(), $data->get_links() );
	}

	public function test_prepare_item_limit_fields() {
		$obj      = get_post_status_object( 'publish' );
		$request  = new WP_REST_Request;
		$endpoint = new WP_REST_Post_Statuses_Controller;
		$request->set_param( 'context', 'edit' );
		$request->set_param( '_fields', 'id,name' );
		$response = $endpoint->prepare_item_for_response( $obj, $request );
		$this->assertSame(
			array(
				// 'id' doesn't exist in this context.
				'name',
			),
			array_keys( $response->get_data() )
		);
	}

	public function test_get_item_schema() {
		$request    = new WP_REST_Request( 'OPTIONS', '/wp/v2/statuses' );
		$response   = rest_get_server()->dispatch( $request );
		$data       = $response->get_data();
		$properties = $data['schema']['properties'];
		$this->assertCount( 8, $properties );
		$this->assertArrayHasKey( 'name', $properties );
		$this->assertArrayHasKey( 'private', $properties );
		$this->assertArrayHasKey( 'protected', $properties );
		$this->assertArrayHasKey( 'public', $properties );
		$this->assertArrayHasKey( 'queryable', $properties );
		$this->assertArrayHasKey( 'show_in_list', $properties );
		$this->assertArrayHasKey( 'slug', $properties );
		$this->assertArrayHasKey( 'date_floating', $properties );
	}

	public function test_get_additional_field_registration() {

		$schema = array(
			'type'        => 'integer',
			'description' => 'Some integer of mine',
			'enum'        => array( 1, 2, 3, 4 ),
			'context'     => array( 'view', 'edit' ),
		);

		register_rest_field(
			'status',
			'my_custom_int',
			array(
				'schema'          => $schema,
				'get_callback'    => array( $this, 'additional_field_get_callback' ),
				'update_callback' => array( $this, 'additional_field_update_callback' ),
			)
		);

		$request = new WP_REST_Request( 'OPTIONS', '/wp/v2/statuses' );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'my_custom_int', $data['schema']['properties'] );
		$this->assertSame( $schema, $data['schema']['properties']['my_custom_int'] );

		$request = new WP_REST_Request( 'GET', '/wp/v2/statuses/publish' );

		$response = rest_get_server()->dispatch( $request );
		$this->assertArrayHasKey( 'my_custom_int', $response->data );

		global $wp_rest_additional_fields;
		$wp_rest_additional_fields = array();
	}

	public function additional_field_get_callback( $object ) {
		return 123;
	}

	protected function check_post_status_obj( $status_obj, $data, $links ) {
		$this->assertSame( $status_obj->label, $data['name'] );
		$this->assertSame( $status_obj->private, $data['private'] );
		$this->assertSame( $status_obj->protected, $data['protected'] );
		$this->assertSame( $status_obj->public, $data['public'] );
		$this->assertSame( $status_obj->publicly_queryable, $data['queryable'] );
		$this->assertSame( $status_obj->show_in_admin_all_list, $data['show_in_list'] );
		$this->assertSame( $status_obj->name, $data['slug'] );
		$this->assertSameSets(
			array(
				'archives',
			),
			array_keys( $links )
		);
		$this->assertSame( $status_obj->date_floating, $data['date_floating'] );
	}

	protected function check_post_status_object_response( $response ) {
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$obj  = get_post_status_object( 'publish' );
		$this->check_post_status_obj( $obj, $data, $response->get_links() );
	}

}
