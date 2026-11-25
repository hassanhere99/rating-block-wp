<?php
/**
 * Remove votes when their post or user is deleted.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the votes table and the cached summaries in step with deletions.
 *
 * Trashing a post keeps its votes, so restoring it restores the rating. Only a
 * permanent delete removes them.
 *
 * On multisite the votes table is per site. deleted_user fires once, so a user
 * removed from the whole network only has the current site's votes cleaned up.
 * See the known limitations in the README.
 */
final class Cleanup {

	/**
	 * Hook the deletions.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'deleted_user', array( __CLASS__, 'on_deleted_user' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ) );
	}

	/**
	 * Delete a user's votes and refresh every summary they were part of.
	 *
	 * Runs after the user is gone. Votes are not content, so they are removed
	 * whether or not the user's posts were reassigned. The affected post IDs
	 * are read first, because the rows are deleted next.
	 *
	 * @param int $user_id ID of the deleted user.
	 * @return void
	 */
	public static function on_deleted_user( $user_id ): void {
		$user_id  = (int) $user_id;
		$post_ids = Votes::post_ids_for_user( $user_id );

		if ( empty( $post_ids ) ) {
			return;
		}

		Votes::delete_for_user( $user_id );

		foreach ( $post_ids as $post_id ) {
			Summary::invalidate( $post_id );
		}
	}

	/**
	 * Delete a post's votes and its cached summary.
	 *
	 * @param int $post_id ID of the post about to be deleted.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id ): void {
		$post_id = (int) $post_id;

		Votes::delete_for_post( $post_id );
		Summary::invalidate( $post_id );
	}
}
