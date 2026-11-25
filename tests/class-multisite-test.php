<?php
/**
 * Tests that only mean something on a multisite network.
 *
 * Run with: npm run test:php:multisite
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Votes;

/**
 * A network user who is not a member of the site.
 *
 * On multisite a user can be logged in to the network without belonging to this
 * site. Such a user has no role here, so no `read` capability, and must not be
 * able to vote. Unlike the "no role" test in Rest_Vote_Test, this removes the
 * user from the site with core's own API, as happens on a real network.
 */
final class Multisite_Test extends Test_Case {

	public function set_up(): void {
		parent::set_up();

		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only. Run with phpunit.multisite.xml.dist.' );
		}
	}

	/**
	 * A user who belongs to the network but not to the current site.
	 *
	 * @return int User ID.
	 */
	private function make_non_member(): int {
		$user_id = $this->make_user();
		remove_user_from_blog( $user_id, get_current_blog_id() );

		return $user_id;
	}

	public function test_a_site_member_can_vote(): void {
		$user_id = $this->make_user();
		$post_id = $this->make_post();
		wp_set_current_user( $user_id );

		$this->assertTrue( is_user_member_of_blog( $user_id ), 'Precondition: a member.' );
		$this->assertSame( 200, $this->post_vote( $post_id, 4 )->get_status() );
		$this->assertSame( 4, Votes::user_score( $post_id, $user_id ) );
	}

	public function test_a_network_user_who_is_not_a_member_of_the_site_cannot_vote(): void {
		$user_id = $this->make_non_member();
		$post_id = $this->make_post();
		wp_set_current_user( $user_id );

		$this->assertFalse( is_user_member_of_blog( $user_id ), 'Precondition: not a member of this site.' );
		$this->assertFalse( current_user_can( 'read' ), 'Precondition: no read capability here.' );
		$this->assertTrue( is_user_logged_in(), 'Precondition: logged in to the network.' );

		$response = $this->post_vote( $post_id, 4 );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rating_block_wp_cannot_read', $response->get_data()['code'] );
		$this->assertSame( 0, $this->row_count() );
		$this->assertNull( Votes::user_score( $post_id, $user_id ) );
	}

	public function test_the_block_offers_a_non_member_no_inputs(): void {
		$post_id = $this->make_post();
		Votes::upsert( $post_id, $this->make_user(), 5 );

		wp_set_current_user( $this->make_non_member() );
		$html = ( new \WP_Block(
			array(
				'blockName'    => 'rating-block/rating',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			array( 'postId' => $post_id )
		) )->render();

		$this->assertStringNotContainsString( '<input', $html, 'No vote is offered that the endpoint would refuse.' );
		$this->assertStringContainsString( '5.0 out of 5', $html, 'The rating is still readable.' );
	}

	public function test_a_non_member_can_still_read_the_summary(): void {
		$post_id = $this->make_post();
		Votes::upsert( $post_id, $this->make_user(), 3 );
		wp_set_current_user( $this->make_non_member() );

		$response = $this->get_summary( $post_id );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['count'] );
		$this->assertNull( $response->get_data()['user_score'] );
	}
}
