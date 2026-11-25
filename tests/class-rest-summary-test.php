<?php
/**
 * Tests for GET /rating-block/v1/posts/{id}/summary.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

/**
 * Summary endpoint.
 */
final class Rest_Summary_Test extends Test_Case {

	public function test_summary_with_no_votes(): void {
		$response = $this->get_summary( $this->make_post() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array(
				'average'    => null,
				'count'      => 0,
				'user_score' => null,
			),
			$response->get_data()
		);
	}

	public function test_logged_out_visitor_can_read_and_has_no_user_score(): void {
		$post_id = $this->make_post();
		wp_set_current_user( $this->make_user() );
		$this->post_vote( $post_id, 4 );
		wp_set_current_user( 0 );

		$data = $this->get_summary( $post_id )->get_data();

		$this->assertSame( 1, $data['count'] );
		$this->assertEquals( 4.0, $data['average'] );
		$this->assertNull( $data['user_score'] );
	}

	public function test_logged_in_user_gets_their_own_score(): void {
		$post_id = $this->make_post();
		$voter   = $this->make_user();

		wp_set_current_user( $voter );
		$this->post_vote( $post_id, 2 );
		wp_set_current_user( $this->make_user() );
		$this->post_vote( $post_id, 4 );

		wp_set_current_user( $voter );
		$data = $this->get_summary( $post_id )->get_data();

		$this->assertSame( 2, $data['count'] );
		$this->assertEquals( 3.0, $data['average'] );
		$this->assertSame( 2, $data['user_score'] );
	}

	public function test_draft_is_401_for_a_logged_out_visitor_and_403_for_a_logged_in_one(): void {
		$post_id = $this->make_post( 'draft' );

		$this->assertSame( 401, $this->get_summary( $post_id )->get_status(), 'Logged out.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'Logged in.' );
	}

	public function test_private_post_summary_needs_permission_to_read_it(): void {
		$post_id = $this->make_post( 'private' );

		$this->assertSame( 401, $this->get_summary( $post_id )->get_status(), 'Logged out.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'Subscriber.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, $this->get_summary( $post_id )->get_status(), 'Administrator.' );
	}

	public function test_password_protected_post_is_401_logged_out_and_403_logged_in(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->assertSame( 401, $this->get_summary( $post_id )->get_status(), 'Logged out.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'Logged in.' );
	}

	/**
	 * Every kind of unavailable post answers with rest_authorization_required_code(),
	 * as core does for a post the caller cannot read: 401 logged out, 403 logged in.
	 *
	 * @dataProvider unavailable_posts
	 *
	 * @param string $state Which kind of unavailable post.
	 */
	public function test_unavailable_post_status_is_core_authorization_required_code( string $state ): void {
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
			default:
				$post_id = $this->make_post( $state );
		}

		wp_set_current_user( 0 );
		$logged_out = $this->get_summary( $post_id );
		$this->assertSame( 401, $logged_out->get_status() );
		$this->assertSame( rest_authorization_required_code(), $logged_out->get_status() );
		$this->assertSame( 'rating_block_wp_post_forbidden', $logged_out->get_data()['code'] );

		wp_set_current_user( $this->make_user() );
		$logged_in = $this->get_summary( $post_id );
		$this->assertSame( 403, $logged_in->get_status() );
		$this->assertSame( rest_authorization_required_code(), $logged_in->get_status() );
		$this->assertSame( 'rating_block_wp_post_forbidden', $logged_in->get_data()['code'] );
	}

	/**
	 * Post states a subscriber and a visitor cannot read. Attachments are left
	 * out: a logged-in user can read one, as core allows, and voting on one is
	 * covered by Rest_Vote_Test.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function unavailable_posts(): array {
		return array(
			'draft'    => array( 'draft' ),
			'pending'  => array( 'pending' ),
			'private'  => array( 'private' ),
			'trash'    => array( 'trash' ),
			'future'   => array( 'future' ),
			'password' => array( 'password' ),
		);
	}

	public function test_a_missing_post_is_404_for_everyone(): void {
		$this->assertSame( 404, $this->get_summary( 999999 )->get_status(), 'Logged out.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 404, $this->get_summary( 999999 )->get_status(), 'Logged in.' );
	}
}
