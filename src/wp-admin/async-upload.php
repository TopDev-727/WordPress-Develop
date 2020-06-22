<?php
/**
 * Server-side file upload handler from wp-plupload or other asynchronous upload methods.
 *
 * @package WordPress
 * @subpackage Administration
 */

if ( isset( $_REQUEST['action'] ) && 'upload-attachment' === $_REQUEST['action'] ) {
	define( 'DOING_AJAX', true );
}

if ( ! defined( 'WP_ADMIN' ) ) {
	define( 'WP_ADMIN', true );
}

if ( defined( 'ABSPATH' ) ) {
	require_once ABSPATH . 'wp-load.php';
} else {
	require_once dirname( __DIR__ ) . '/wp-load.php';
}

require_once ABSPATH . 'wp-admin/admin.php';

header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );

if ( isset( $_REQUEST['action'] ) && 'upload-attachment' === $_REQUEST['action'] ) {
	require ABSPATH . 'wp-admin/includes/ajax-actions.php';

	send_nosniff_header();
	nocache_headers();

	wp_ajax_upload_attachment();
	die( '0' );
}

if ( ! current_user_can( 'upload_files' ) ) {
	wp_die( __( 'Sorry, you are not allowed to upload files.' ) );
}

// Just fetch the detail form for that attachment.
if ( isset( $_REQUEST['attachment_id'] ) && intval( $_REQUEST['attachment_id'] ) && $_REQUEST['fetch'] ) {
	$id   = intval( $_REQUEST['attachment_id'] );
	$post = get_post( $id );
	if ( 'attachment' !== $post->post_type ) {
		wp_die( __( 'Invalid post type.' ) );
	}

	switch ( $_REQUEST['fetch'] ) {
		case 3:
			$thumb_url = wp_get_attachment_image_src( $id, 'thumbnail', true );
			if ( $thumb_url ) {
				echo '<img class="pinkynail" src="' . esc_url( $thumb_url[0] ) . '" alt="" />';
			}
			if ( current_user_can( 'edit_post', $id ) ) {
				echo '<a class="edit-attachment" href="' . esc_url( get_edit_post_link( $id ) ) . '" target="_blank">' . _x( 'Edit', 'media item' ) . '</a>';
			} else {
				echo '<span class="edit-attachment">' . _x( 'Success', 'media item' ) . '</span>';
			}

			// Title shouldn't ever be empty, but use filename just in case.
			$file  = get_attached_file( $post->ID );
			$title = $post->post_title ? $post->post_title : wp_basename( $file );
			echo '<div class="filename new"><span class="title">' . esc_html( wp_html_excerpt( $title, 60, '&hellip;' ) ) . '</span></div>';
			break;
		case 2:
			add_filter( 'attachment_fields_to_edit', 'media_single_attachment_fields_to_edit', 10, 2 );
			echo get_media_item(
				$id,
				array(
					'send'   => false,
					'delete' => true,
				)
			);
			break;
		default:
			add_filter( 'attachment_fields_to_edit', 'media_post_single_attachment_fields_to_edit', 10, 2 );
			echo get_media_item( $id );
			break;
	}
	exit;
}

check_admin_referer( 'media-form' );

$post_id = 0;
if ( isset( $_REQUEST['post_id'] ) ) {
	$post_id = absint( $_REQUEST['post_id'] );
	if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		$post_id = 0;
	}
}

$id = media_handle_upload( 'async-upload', $post_id );
if ( is_wp_error( $id ) ) {
	printf(
		'<div class="error-div error">%s <strong>%s</strong><br />%s</div>',
		sprintf(
			'<button type="button" class="dismiss button-link" onclick="jQuery(this).parents(\'div.media-item\').slideUp(200, function(){jQuery(this).remove();});">%s</button>',
			__( 'Dismiss' )
		),
		sprintf(
			/* translators: %s: Name of the file that failed to upload. */
			__( '&#8220;%s&#8221; has failed to upload.' ),
			esc_html( $_FILES['async-upload']['name'] )
		),
		esc_html( $id->get_error_message() )
	);
	exit;
}

if ( $_REQUEST['short'] ) {
	// Short form response - attachment ID only.
	echo $id;
} else {
	// Long form response - big chunk of HTML.
	$type = $_REQUEST['type'];

	/**
	 * Filters the returned ID of an uploaded attachment.
	 *
	 * The dynamic portion of the hook name, `$type`, refers to the attachment type,
	 * such as 'image', 'audio', 'video', 'file', etc.
	 *
	 * @since 2.5.0
	 *
	 * @param int $id Uploaded attachment ID.
	 */
	echo apply_filters( "async_upload_{$type}", $id );
}
