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
		$this->assertEquals(
			array(
				'average'    => 0.0,
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

	public function test_missing_post_is_404(): void {
		$this->assertSame( 404, $this->get_summary( 999999 )->get_status() );
	}

	public function test_draft_is_403_for_a_logged_out_visitor(): void {
		$this->assertSame( 403, $this->get_summary( $this->make_post( 'draft' ) )->get_status() );
	}

	public function test_private_post_summary_needs_permission_to_read_it(): void {
		$post_id = $this->make_post( 'private' );

		$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'Logged out.' );

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'Subscriber.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, $this->get_summary( $post_id )->get_status(), 'Administrator.' );
	}

	public function test_password_protected_post_is_403(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		$this->assertSame( 403, $this->get_summary( $post_id )->get_status() );
	}
}
