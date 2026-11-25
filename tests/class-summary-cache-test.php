<?php
/**
 * Tests for the summary cache.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Summary;
use RatingBlockWP\Votes;

/**
 * Cache behaviour. The test environment has no persistent object cache, so the
 * cache lasts for the request; the logic is the same as with a persistent one.
 */
final class Summary_Cache_Test extends Test_Case {

	public function test_a_read_fills_the_cache(): void {
		$post_id = $this->make_post();

		$this->assertFalse( $this->is_cached( $post_id ) );

		Summary::get( $post_id );

		$this->assertTrue( $this->is_cached( $post_id ) );
	}

	public function test_nothing_is_written_to_the_options_table(): void {
		global $wpdb;

		$post_id = $this->make_post();
		Votes::upsert( $post_id, $this->make_user(), 4 );

		Summary::get( $post_id );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion, no user input.
		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%transient%rating_block_wp%'" );
		// phpcs:enable

		$this->assertSame( array(), $rows, 'No transient fallback: the summary is not stored in wp_options.' );
	}

	public function test_a_cached_value_is_served_without_touching_the_table(): void {
		$post_id = $this->make_post();
		$this->seed_cache(
			$post_id,
			array(
				'count'   => 42,
				'average' => 4.2,
			)
		);

		$this->assertSame( 42, Summary::get( $post_id )['count'] );
	}

	public function test_a_vote_invalidates_a_stale_cached_summary(): void {
		$post_id = $this->make_post();
		$this->seed_cache(
			$post_id,
			array(
				'count'   => 99,
				'average' => 1.0,
			)
		);

		wp_set_current_user( $this->make_user() );
		$response = $this->post_vote( $post_id, 5 );

		// The seeded value is gone and the response is computed from the table.
		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertEquals( 5.0, $response->get_data()['average'] );
		$this->assertSame( 1, Summary::get( $post_id )['count'] );
	}

	public function test_a_vote_leaves_the_cache_entry_absent(): void {
		$post_id = $this->make_post();
		$this->seed_cache(
			$post_id,
			array(
				'count'   => 99,
				'average' => 1.0,
			)
		);

		wp_set_current_user( $this->make_user() );
		$response = $this->post_vote( $post_id, 5 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->is_cached( $post_id ), 'The stale entry is gone and the vote did not write a new one.' );
	}

	public function test_a_vote_on_an_uncached_post_does_not_create_an_entry(): void {
		$post_id = $this->make_post();

		wp_set_current_user( $this->make_user() );
		$this->post_vote( $post_id, 3 );

		$this->assertFalse( $this->is_cached( $post_id ) );
	}

	public function test_a_vote_removes_the_cache_entry_before_the_next_read(): void {
		$post_id = $this->make_post();
		$user_id = $this->make_user();
		$this->seed_cache(
			$post_id,
			array(
				'count'   => 99,
				'average' => 1.0,
			)
		);

		// The write path on its own, without the response's own read.
		Votes::upsert( $post_id, $user_id, 3 );
		$this->assertTrue( $this->is_cached( $post_id ), 'The table write alone does not touch the cache.' );

		Summary::invalidate( $post_id );

		$this->assertFalse( $this->is_cached( $post_id ) );
		$this->assertSame( 1, Summary::get( $post_id )['count'] );
	}

	public function test_the_cache_is_per_post(): void {
		$post_a = $this->make_post();
		$post_b = $this->make_post();
		$this->seed_cache(
			$post_a,
			array(
				'count'   => 5,
				'average' => 5.0,
			)
		);
		$this->seed_cache(
			$post_b,
			array(
				'count'   => 6,
				'average' => 1.0,
			)
		);

		wp_set_current_user( $this->make_user() );
		$this->post_vote( $post_a, 2 );

		$this->assertSame( 1, Summary::get( $post_a )['count'] );
		$this->assertSame( 6, Summary::get( $post_b )['count'], 'The other post keeps its cached value.' );
	}
}
