<?php

class Tests_Comment_Meta_Cache extends WP_UnitTestCase {
	protected $i = 0;
	protected $queries = 0;

	/**
	 * @ticket 16894
	 */
	public function test_update_comment_meta_cache_should_default_to_true() {
		global $wpdb;

		$p = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$comment_ids = $this->factory->comment->create_post_comments( $p, 3 );

		foreach ( $comment_ids as $cid ) {
			update_comment_meta( $cid, 'foo', 'bar' );
		}

		$q = new WP_Comment_Query( array(
			'post_ID' => $p,
		) );

		$num_queries = $wpdb->num_queries;
		foreach ( $comment_ids as $cid ) {
			get_comment_meta( $cid, 'foo', 'bar' );
		}

		$this->assertSame( $num_queries, $wpdb->num_queries );
	}

	/**
	 * @ticket 16894
	 */
	public function test_update_comment_meta_cache_true() {
		global $wpdb;

		$p = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$comment_ids = $this->factory->comment->create_post_comments( $p, 3 );

		foreach ( $comment_ids as $cid ) {
			update_comment_meta( $cid, 'foo', 'bar' );
		}

		$q = new WP_Comment_Query( array(
			'post_ID' => $p,
			'update_comment_meta_cache' => true,
		) );

		$num_queries = $wpdb->num_queries;
		foreach ( $comment_ids as $cid ) {
			get_comment_meta( $cid, 'foo', 'bar' );
		}

		$this->assertSame( $num_queries, $wpdb->num_queries );
	}

	/**
	 * @ticket 16894
	 */
	public function test_update_comment_meta_cache_false() {
		global $wpdb;

		$p = $this->factory->post->create( array( 'post_status' => 'publish' ) );
		$comment_ids = $this->factory->comment->create_post_comments( $p, 3 );

		foreach ( $comment_ids as $cid ) {
			update_comment_meta( $cid, 'foo', 'bar' );
		}

		$q = new WP_Comment_Query( array(
			'post_ID' => $p,
			'update_comment_meta_cache' => false,
		) );

		$num_queries = $wpdb->num_queries;
		foreach ( $comment_ids as $cid ) {
			get_comment_meta( $cid, 'foo', 'bar' );
		}

		$this->assertSame( $num_queries + 3, $wpdb->num_queries );
	}

	public function test_comment_meta_cache() {
		$post_id = $this->factory->post->create( array(
			'post_status' => 'publish'
		) );

		$comment_ids = $this->factory->comment->create_post_comments( $post_id, 10 );

		foreach ( $comment_ids as $cid ) {
			update_comment_meta( $cid, 'sauce', 'fire' );
		}

		$post = get_post( $post_id );

		$this->assertEquals( $post->comment_count, count( $comment_ids ) );

		$this->go_to( get_permalink( $post_id ) );

		$this->assertTrue( is_single() );
		$this->assertTrue( have_posts() );

		global $wp_query;

		while ( have_posts() ) {
			the_post();

			$comment_args = array(
				'order'   => 'ASC',
				'orderby' => 'comment_date_gmt',
				'status'  => 'approve',
				'post_id' => get_the_ID(),
			);

			$comments = get_comments( $comment_args );

			// This is beyond awful
			$wp_query->comments = $comments;

			wp_list_comments( array(
				'echo' => false,
				'callback' => array( $this, '_comment_callback' )
			) );
		}
	}

	public function _comment_callback( $comment ) {
		global $wpdb;

		get_comment_meta( $comment->comment_ID, 'sauce' );

		if ( 0 === $this->i ) {
			$this->queries = $wpdb->num_queries;
		} else {
			$this->assertEquals( $this->queries, $wpdb->num_queries );
		}

		$this->i++;
	}
}