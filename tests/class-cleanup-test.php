<?php
/**
 * Tests for vote cleanup on user and post deletion.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Summary;
use RatingBlockWP\Votes;

/**
 * Deleted users and deleted posts.
 */
final class Cleanup_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		require_once ABSPATH . 'wp-admin/includes/user.php'; // Defines wp_delete_user.
	}

	public function test_deleting_a_user_deletes_their_votes_and_refreshes_each_summary(): void {
		$leaving = $this->make_user();
		$staying = $this->make_user();
		$post_1  = $this->make_post();
		$post_2  = $this->make_post();
		$post_3  = $this->make_post();

		Votes::upsert( $post_1, $leaving, 5 );
		Votes::upsert( $post_2, $leaving, 5 );
		Votes::upsert( $post_1, $staying, 1 );
		Votes::upsert( $post_3, $staying, 4 );

		// Prime all three caches so staleness would show.
		$this->assertSame( 2, Summary::get( $post_1 )['count'] );
		$this->assertSame( 1, Summary::get( $post_2 )['count'] );
		$this->assertSame( 1, Summary::get( $post_3 )['count'] );

		wp_delete_user( $leaving );

		$this->assertSame( 0, $this->row_count( null, $leaving ), 'All of the leaving user\'s votes are gone.' );
		$this->assertSame( 2, $this->row_count( null, $staying ), 'Other users\' votes are untouched.' );

		$this->assertFalse( $this->is_cached( $post_1 ), 'Summary for the first affected post was invalidated.' );
		$this->assertFalse( $this->is_cached( $post_2 ), 'Summary for the second affected post was invalidated.' );
		$this->assertTrue( $this->is_cached( $post_3 ), 'A post the user never voted on keeps its cache.' );

		$this->assertSame( 1, Summary::get( $post_1 )['count'] );
		$this->assertEquals( 1.0, Summary::get( $post_1 )['average'] );
		$this->assertSame( 0, Summary::get( $post_2 )['count'] );
	}

	public function test_deleting_a_user_with_no_votes_is_harmless(): void {
		$post_id = $this->make_post();
		Votes::upsert( $post_id, $this->make_user(), 3 );
		Summary::get( $post_id );

		wp_delete_user( $this->make_user() );

		$this->assertSame( 1, $this->row_count() );
		$this->assertTrue( $this->is_cached( $post_id ) );
	}

	public function test_deleting_a_post_deletes_its_votes_and_cached_summary(): void {
		$doomed = $this->make_post();
		$kept   = $this->make_post();
		$user_a = $this->make_user();
		$user_b = $this->make_user();

		Votes::upsert( $doomed, $user_a, 5 );
		Votes::upsert( $doomed, $user_b, 3 );
		Votes::upsert( $kept, $user_a, 2 );
		Summary::get( $doomed );
		Summary::get( $kept );
		$this->assertTrue( $this->is_cached( $doomed ) );

		wp_delete_post( $doomed, true );

		$this->assertSame( 0, $this->row_count( $doomed ) );
		$this->assertFalse( $this->is_cached( $doomed ) );
		$this->assertSame( 1, $this->row_count( $kept ), 'Votes on other posts are untouched.' );
		$this->assertTrue( $this->is_cached( $kept ) );
	}

	public function test_trashing_a_post_keeps_its_votes(): void {
		$post_id = $this->make_post();
		Votes::upsert( $post_id, $this->make_user(), 4 );

		wp_trash_post( $post_id );

		$this->assertSame( 1, $this->row_count( $post_id ) );

		wp_untrash_post( $post_id );

		$this->assertSame( 1, Summary::get( $post_id )['count'] );
	}
}
