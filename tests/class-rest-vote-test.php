<?php
/**
 * Tests for POST /rating-block/v1/posts/{id}/vote.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Votes;

/**
 * Voting endpoint.
 */
final class Rest_Vote_Test extends Test_Case {

	public function test_logged_out_vote_is_401(): void {
		$post_id = $this->make_post();

		$response = $this->post_vote( $post_id, 4 );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, $this->row_count() );
	}

	public function test_invalid_score_is_400_even_when_logged_out(): void {
		// Args are validated before permission_callback runs.
		$response = $this->post_vote( $this->make_post(), 0 );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A score outside 1 to 5, or not an integer, is rejected with 400.
	 *
	 * @dataProvider invalid_scores
	 *
	 * @param mixed $score Score to send.
	 */
	public function test_invalid_score_is_400( $score ): void {
		wp_set_current_user( $this->make_user() );
		$post_id = $this->make_post();

		$response = $this->post_vote( $post_id, $score );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * Scores the schema must reject.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function invalid_scores(): array {
		return array(
			'zero'       => array( 0 ),
			'six'        => array( 6 ),
			'negative'   => array( -1 ),
			'float'      => array( 3.5 ),
			'text'       => array( 'abc' ),
			'missing'    => array( null ),
			'huge'       => array( 4294967296 ),
			'empty text' => array( '' ),
		);
	}

	public function test_missing_post_is_404(): void {
		wp_set_current_user( $this->make_user() );

		$response = $this->post_vote( 999999, 3 );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * A post that is not published and public gets 403 and no vote.
	 *
	 * @dataProvider unavailable_post_states
	 *
	 * @param string $state Which kind of unavailable post to create.
	 */
	public function test_unavailable_post_is_rejected( string $state ): void {
		wp_set_current_user( $this->make_user() );

		switch ( $state ) {
			case 'password':
				$post_id = self::factory()->post->create(
					array(
						'post_status'   => 'publish',
						'post_password' => 'secret',
					)
				);
				break;
			case 'future':
				$post_id = self::factory()->post->create(
					array(
						'post_status' => 'future',
						'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					)
				);
				break;
			case 'attachment':
				$post_id = self::factory()->attachment->create();
				break;
			default:
				$post_id = $this->make_post( $state );
		}

		$response = $this->post_vote( $post_id, 3 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 0, $this->row_count() );
	}

	/**
	 * Post states that must not accept a vote.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unavailable_post_states(): array {
		return array(
			'draft'    => array( 'draft' ),
			'pending'  => array( 'pending' ),
			'private'  => array( 'private' ),
			'trash'    => array( 'trash' ),
			'future'   => array( 'future' ),
			'password' => array( 'password' ),
		);
	}

	public function test_admin_cannot_vote_on_a_draft(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$response = $this->post_vote( $this->make_post( 'draft' ), 5 );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_first_vote_inserts_a_row(): void {
		$user_id = $this->make_user();
		$post_id = $this->make_post();
		wp_set_current_user( $user_id );

		$response = $this->post_vote( $post_id, 4 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->row_count( $post_id, $user_id ) );
		$this->assertSame( 4, Votes::user_score( $post_id, $user_id ) );
		$this->assertEquals(
			array(
				'average'    => 4.0,
				'count'      => 1,
				'user_score' => 4,
			),
			$response->get_data()
		);
	}

	public function test_second_vote_by_the_same_user_updates_the_row(): void {
		$user_id = $this->make_user();
		$post_id = $this->make_post();
		wp_set_current_user( $user_id );

		$this->post_vote( $post_id, 2 );
		$response = $this->post_vote( $post_id, 5 );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $this->row_count( $post_id, $user_id ), 'Still one row for this user and post.' );
		$this->assertSame( 5, Votes::user_score( $post_id, $user_id ) );
		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertEquals( 5.0, $response->get_data()['average'] );
	}

	public function test_update_keeps_created_at_and_stores_utc(): void {
		global $wpdb;

		$user_id = $this->make_user();
		$post_id = $this->make_post();
		wp_set_current_user( $user_id );

		$this->post_vote( $post_id, 2 );

		// Backdate the row so the update visibly moves updated_at.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test setup.
		$wpdb->update(
			\RatingBlockWP\Schema::table_name(),
			array(
				'created_at' => '2001-01-01 00:00:00',
				'updated_at' => '2001-01-01 00:00:00',
			),
			array( 'post_id' => $post_id )
		);

		$this->post_vote( $post_id, 3 );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT created_at, updated_at FROM %i WHERE post_id = %d', \RatingBlockWP\Schema::table_name(), $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion.

		$this->assertSame( '2001-01-01 00:00:00', $row['created_at'] );
		$this->assertNotSame( '2001-01-01 00:00:00', $row['updated_at'] );
		$this->assertEqualsWithDelta( time(), strtotime( $row['updated_at'] . ' UTC' ), 5, 'updated_at is the current UTC time.' );
	}

	public function test_average_across_three_users(): void {
		$post_id = $this->make_post();

		foreach ( array( 5, 4, 2 ) as $score ) {
			wp_set_current_user( $this->make_user() );
			$response = $this->post_vote( $post_id, $score );
		}

		// (5 + 4 + 2) / 3 = 3.6666...
		$data = $response->get_data();
		$this->assertSame( 3, $data['count'] );
		$this->assertEqualsWithDelta( 3.67, $data['average'], 0.001 );
		$this->assertSame( 3, $this->row_count( $post_id ) );
	}

	public function test_votes_on_different_posts_are_independent(): void {
		$user_id = $this->make_user();
		$post_a  = $this->make_post();
		$post_b  = $this->make_post();
		wp_set_current_user( $user_id );

		$this->post_vote( $post_a, 1 );
		$this->post_vote( $post_b, 5 );

		$this->assertSame( 1, Votes::user_score( $post_a, $user_id ) );
		$this->assertSame( 5, Votes::user_score( $post_b, $user_id ) );
		$this->assertSame( 2, $this->row_count( null, $user_id ) );
	}

	public function test_routes_are_registered_with_a_permission_callback_and_schema(): void {
		$routes = rest_get_server()->get_routes( 'rating-block/v1' );

		foreach ( array( 'vote', 'summary' ) as $name ) {
			$route = "/rating-block/v1/posts/(?P<id>\d+)/{$name}";

			$this->assertArrayHasKey( $route, $routes );
			$this->assertIsCallable( $routes[ $route ][0]['permission_callback'] );
			$this->assertIsCallable( $routes[ $route ][0]['schema'] );
		}
	}
}
