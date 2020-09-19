<?php

/**
 * @group user
 * @group slashes
 * @ticket 21767
 */
class Tests_User_Slashes extends WP_UnitTestCase {
	protected static $author_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	function setUp() {
		parent::setUp();

		wp_set_current_user( $this->author_id );

		// It is important to test with both even and odd numbered slashes,
		// as KSES does a strip-then-add slashes in some of its function calls.
		$this->slash_1 = 'String with 1 slash \\';
		$this->slash_2 = 'String with 2 slashes \\\\';
		$this->slash_3 = 'String with 3 slashes \\\\\\';
		$this->slash_4 = 'String with 4 slashes \\\\\\\\';
		$this->slash_5 = 'String with 5 slashes \\\\\\\\\\';
		$this->slash_6 = 'String with 6 slashes \\\\\\\\\\\\';
		$this->slash_7 = 'String with 7 slashes \\\\\\\\\\\\\\';
	}

	/**
	 * Tests the controller function that expects slashed data.
	 */
	function test_add_user() {
		$_POST                 = array();
		$_GET                  = array();
		$_REQUEST              = array();
		$_POST['user_login']   = 'slash_example_user_1';
		$_POST['pass1']        = 'password';
		$_POST['pass2']        = 'password';
		$_POST['role']         = 'subscriber';
		$_POST['email']        = 'user1@example.com';
		$_POST['first_name']   = $this->slash_1;
		$_POST['last_name']    = $this->slash_3;
		$_POST['nickname']     = $this->slash_5;
		$_POST['display_name'] = $this->slash_7;
		$_POST['description']  = $this->slash_3;

		$_POST = add_magic_quotes( $_POST ); // The add_user() function will strip slashes.

		$user_id = add_user();
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( $this->slash_1, $user->first_name );
		$this->assertSame( $this->slash_3, $user->last_name );
		$this->assertSame( $this->slash_5, $user->nickname );
		$this->assertSame( $this->slash_7, $user->display_name );
		$this->assertSame( $this->slash_3, $user->description );

		$_POST                 = array();
		$_GET                  = array();
		$_REQUEST              = array();
		$_POST['user_login']   = 'slash_example_user_2';
		$_POST['pass1']        = 'password';
		$_POST['pass2']        = 'password';
		$_POST['role']         = 'subscriber';
		$_POST['email']        = 'user2@example.com';
		$_POST['first_name']   = $this->slash_2;
		$_POST['last_name']    = $this->slash_4;
		$_POST['nickname']     = $this->slash_6;
		$_POST['display_name'] = $this->slash_2;
		$_POST['description']  = $this->slash_4;

		$_POST = add_magic_quotes( $_POST ); // The add_user() function will strip slashes.

		$user_id = add_user();
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( $this->slash_2, $user->first_name );
		$this->assertSame( $this->slash_4, $user->last_name );
		$this->assertSame( $this->slash_6, $user->nickname );
		$this->assertSame( $this->slash_2, $user->display_name );
		$this->assertSame( $this->slash_4, $user->description );
	}

	/**
	 * Tests the controller function that expects slashed data.
	 */
	function test_edit_user() {
		$user_id = self::factory()->user->create();

		$_POST                 = array();
		$_GET                  = array();
		$_REQUEST              = array();
		$_POST['role']         = 'subscriber';
		$_POST['email']        = 'user1@example.com';
		$_POST['first_name']   = $this->slash_1;
		$_POST['last_name']    = $this->slash_3;
		$_POST['nickname']     = $this->slash_5;
		$_POST['display_name'] = $this->slash_7;
		$_POST['description']  = $this->slash_3;

		$_POST = add_magic_quotes( $_POST ); // The edit_user() function will strip slashes.

		$user_id = edit_user( $user_id );
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( $this->slash_1, $user->first_name );
		$this->assertSame( $this->slash_3, $user->last_name );
		$this->assertSame( $this->slash_5, $user->nickname );
		$this->assertSame( $this->slash_7, $user->display_name );
		$this->assertSame( $this->slash_3, $user->description );

		$_POST                 = array();
		$_GET                  = array();
		$_REQUEST              = array();
		$_POST['role']         = 'subscriber';
		$_POST['email']        = 'user2@example.com';
		$_POST['first_name']   = $this->slash_2;
		$_POST['last_name']    = $this->slash_4;
		$_POST['nickname']     = $this->slash_6;
		$_POST['display_name'] = $this->slash_2;
		$_POST['description']  = $this->slash_4;

		$_POST = add_magic_quotes( $_POST ); // The edit_user() function will strip slashes.

		$user_id = edit_user( $user_id );
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( $this->slash_2, $user->first_name );
		$this->assertSame( $this->slash_4, $user->last_name );
		$this->assertSame( $this->slash_6, $user->nickname );
		$this->assertSame( $this->slash_2, $user->display_name );
		$this->assertSame( $this->slash_4, $user->description );
	}

	/**
	 * Tests the model function that expects slashed data.
	 */
	function test_wp_insert_user() {
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'slash_example_user_3',
				'role'         => 'subscriber',
				'email'        => 'user3@example.com',
				'first_name'   => $this->slash_1,
				'last_name'    => $this->slash_3,
				'nickname'     => $this->slash_5,
				'display_name' => $this->slash_7,
				'description'  => $this->slash_3,
				'user_pass'    => '',
			)
		);
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( wp_unslash( $this->slash_1 ), $user->first_name );
		$this->assertSame( wp_unslash( $this->slash_3 ), $user->last_name );
		$this->assertSame( wp_unslash( $this->slash_5 ), $user->nickname );
		$this->assertSame( wp_unslash( $this->slash_7 ), $user->display_name );
		$this->assertSame( wp_unslash( $this->slash_3 ), $user->description );

		$user_id = wp_insert_user(
			array(
				'user_login'   => 'slash_example_user_4',
				'role'         => 'subscriber',
				'email'        => 'user3@example.com',
				'first_name'   => $this->slash_2,
				'last_name'    => $this->slash_4,
				'nickname'     => $this->slash_6,
				'display_name' => $this->slash_2,
				'description'  => $this->slash_4,
				'user_pass'    => '',
			)
		);
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( wp_unslash( $this->slash_2 ), $user->first_name );
		$this->assertSame( wp_unslash( $this->slash_4 ), $user->last_name );
		$this->assertSame( wp_unslash( $this->slash_6 ), $user->nickname );
		$this->assertSame( wp_unslash( $this->slash_2 ), $user->display_name );
		$this->assertSame( wp_unslash( $this->slash_4 ), $user->description );
	}

	/**
	 * Tests the model function that expects slashed data.
	 */
	function test_wp_update_user() {
		$user_id = self::factory()->user->create();
		$user_id = wp_update_user(
			array(
				'ID'           => $user_id,
				'role'         => 'subscriber',
				'first_name'   => $this->slash_1,
				'last_name'    => $this->slash_3,
				'nickname'     => $this->slash_5,
				'display_name' => $this->slash_7,
				'description'  => $this->slash_3,
			)
		);
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( wp_unslash( $this->slash_1 ), $user->first_name );
		$this->assertSame( wp_unslash( $this->slash_3 ), $user->last_name );
		$this->assertSame( wp_unslash( $this->slash_5 ), $user->nickname );
		$this->assertSame( wp_unslash( $this->slash_7 ), $user->display_name );
		$this->assertSame( wp_unslash( $this->slash_3 ), $user->description );

		$user_id = wp_update_user(
			array(
				'ID'           => $user_id,
				'role'         => 'subscriber',
				'first_name'   => $this->slash_2,
				'last_name'    => $this->slash_4,
				'nickname'     => $this->slash_6,
				'display_name' => $this->slash_2,
				'description'  => $this->slash_4,
			)
		);
		$user    = get_user_to_edit( $user_id );

		$this->assertSame( wp_unslash( $this->slash_2 ), $user->first_name );
		$this->assertSame( wp_unslash( $this->slash_4 ), $user->last_name );
		$this->assertSame( wp_unslash( $this->slash_6 ), $user->nickname );
		$this->assertSame( wp_unslash( $this->slash_2 ), $user->display_name );
		$this->assertSame( wp_unslash( $this->slash_4 ), $user->description );
	}

}
