<?php
/**
 * Remove votes when their post or user is deleted.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

use WP_Post;

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
		add_action( 'before_delete_post', array( __CLASS__, 'on_before_delete_post' ), 10, 2 );
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
	 * Post types that can never have votes, and that WordPress creates and
	 * deletes in bulk. Revisions are pruned on every save and menu items on
	 * every menu edit, so skipping them avoids a pointless DELETE each time.
	 */
	const SKIPPED_POST_TYPES = array( 'revision', 'nav_menu_item' );

	/**
	 * Delete a post's votes and its cached summary.
	 *
	 * @param int          $post_id ID of the post about to be deleted.
	 * @param WP_Post|null $post    The post. Used to skip types that cannot have votes.
	 * @return void
	 */
	public static function on_before_delete_post( $post_id, $post = null ): void {
		if ( $post instanceof WP_Post && in_array( $post->post_type, self::SKIPPED_POST_TYPES, true ) ) {
			return;
		}

		$post_id = (int) $post_id;

		Votes::delete_for_post( $post_id );
		Summary::invalidate( $post_id );
	}
}
