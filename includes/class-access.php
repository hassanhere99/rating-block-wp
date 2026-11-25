<?php
/**
 * Who may rate a post.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Permission checks shared by the REST routes and the block.
 *
 * They live in one place so the server-rendered block cannot offer a vote that
 * the endpoint would refuse.
 */
final class Access {

	/**
	 * Whether the current user may vote on a post: a logged-in user who has the 'read' capability on this
	 * site, on a published, viewable post.
	 *
	 * The capability check matters on multisite, where a user can be logged in
	 * to the network without being a member of this site. Such a user has no
	 * role here and so no 'read'. On a single site every role has 'read', and
	 * only a user whose roles were all removed fails the check.
	 *
	 * Used by the REST permission callback and by the block's render.php, so
	 * the page only offers a vote the API will accept.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error True if the current user may vote on the post.
	 */
	public static function check_vote( int $post_id ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rating_block_wp_not_logged_in',
				__( 'You must be logged in to vote.', 'rating-block-wp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rating_block_wp_cannot_read',
				__( 'You are not allowed to vote on this site.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		$post = self::check_post( $post_id, true );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		/**
		 * Filters whether a post can be rated.
		 *
		 * Runs after the built-in checks, so it can only narrow what they
		 * allow. Return false to refuse votes on a post, for example by post
		 * type or by a meta flag. It applies to voting only; summaries stay
		 * readable.
		 *
		 * @since 0.1.0
		 *
		 * @param bool    $is_rateable Whether the post can be rated. Default true.
		 * @param WP_Post $post        The post being voted on.
		 */
		if ( ! (bool) apply_filters( 'rating_block_wp_post_is_rateable', true, $post ) ) {
			return new WP_Error(
				'rating_block_wp_post_not_rateable',
				__( 'This post cannot be rated.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Load a post and check that it may be rated or summarised.
	 *
	 * 404 if there is no such post. 403 if there is one but it is not
	 * available: not published (draft, pending, private, future, trash), a type
	 * that has no public view, or password protected. This matches how the core
	 * posts endpoint answers for a post the caller cannot read.
	 *
	 * Reading a summary also accepts an unpublished post that the current user
	 * can read, such as their own private post. Voting does not: the spec is
	 * published posts only.
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $require_live True to require the post to be published.
	 * @return WP_Post|WP_Error
	 */
	public static function check_post( int $post_id, bool $require_live ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'rating_block_wp_post_not_found',
				__( 'Post not found.', 'rating-block-wp' ),
				array( 'status' => 404 )
			);
		}

		$allowed = is_post_type_viewable( $post->post_type )
			&& ! post_password_required( $post )
			&& (
				'publish' === $post->post_status
				|| ( ! $require_live && current_user_can( 'read_post', $post->ID ) )
			);

		if ( ! $allowed ) {
			return new WP_Error(
				'rating_block_wp_post_forbidden',
				__( 'This post cannot be rated.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		return $post;
	}
}
