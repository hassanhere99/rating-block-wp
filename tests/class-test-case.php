<?php
/**
 * Shared test case.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Schema;
use RatingBlockWP\Summary;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Base class: a fresh REST server per test and a few helpers.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/**
	 * Build a fresh REST server so rest_api_init runs for each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		unset( $GLOBALS['wp_rest_server'] );
		rest_get_server();
	}

	/**
	 * Drop the REST server.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_server'] );

		parent::tear_down();
	}

	/**
	 * Create a user and a published post for a test.
	 *
	 * @param string $status Post status.
	 * @return int Post ID.
	 */
	protected function make_post( string $status = 'publish' ): int {
		return self::factory()->post->create( array( 'post_status' => $status ) );
	}

	/**
	 * Create a subscriber.
	 *
	 * @return int User ID.
	 */
	protected function make_user(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * POST a vote.
	 *
	 * @param int   $post_id Post ID.
	 * @param mixed $score   Score. Null leaves the param out.
	 * @return WP_REST_Response
	 */
	protected function post_vote( int $post_id, $score ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/rating-block/v1/posts/{$post_id}/vote" );

		if ( null !== $score ) {
			$request->set_param( 'score', $score );
		}

		return rest_do_request( $request );
	}

	/**
	 * GET a summary.
	 *
	 * @param int $post_id Post ID.
	 * @return WP_REST_Response
	 */
	protected function get_summary( int $post_id ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', "/rating-block/v1/posts/{$post_id}/summary" ) );
	}

	/**
	 * Number of rows in the votes table, optionally for one post or user.
	 *
	 * @param int|null $post_id Post ID, or null for any.
	 * @param int|null $user_id User ID, or null for any.
	 * @return int
	 */
	protected function row_count( ?int $post_id = null, ?int $user_id = null ): int {
		global $wpdb;

		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE (%d = 0 OR post_id = %d) AND (%d = 0 OR user_id = %d)', Schema::table_name(), (int) $post_id, (int) $post_id, (int) $user_id, (int) $user_id );

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery -- Test helper; $sql is prepared above.
	}

	/**
	 * Whether a summary is in the object cache.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected function is_cached( int $post_id ): bool {
		return false !== wp_cache_get( Summary::KEY_PREFIX . $post_id, Summary::CACHE_GROUP );
	}

	/**
	 * Put a made-up summary in the object cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $value   Value to store.
	 * @return void
	 */
	protected function seed_cache( int $post_id, array $value ): void {
		wp_cache_set( Summary::KEY_PREFIX . $post_id, $value, Summary::CACHE_GROUP, HOUR_IN_SECONDS );
	}
}
