<?php
/**
 * Tests for Access::check_post().
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Access;
use WP_Error;
use WP_Post;

/**
 * The status of a post that is not available follows rest_authorization_required_code().
 */
final class Access_Test extends Test_Case {

	/**
	 * Both modes: voting needs a published post, reading a summary also accepts
	 * one the user can read.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public static function modes(): array {
		return array(
			'require published' => array( true ),
			'allow readable'    => array( false ),
		);
	}

	/**
	 * A logged-out caller gets 401, in both modes.
	 *
	 * @dataProvider modes
	 *
	 * @param bool $require_live Whether the post must be published.
	 */
	public function test_an_unavailable_post_is_401_when_logged_out( bool $require_live ): void {
		wp_set_current_user( 0 );

		$result = Access::check_post( $this->make_post( 'draft' ), $require_live );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rating_block_wp_post_forbidden', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( rest_authorization_required_code(), $result->get_error_data()['status'] );
	}

	/**
	 * A logged-in caller gets 403, in both modes.
	 *
	 * @dataProvider modes
	 *
	 * @param bool $require_live Whether the post must be published.
	 */
	public function test_an_unavailable_post_is_403_when_logged_in( bool $require_live ): void {
		// Create the draft first: a post made while a user is current is theirs,
		// and an author can read their own draft.
		$post_id = $this->make_post( 'draft' );
		wp_set_current_user( $this->make_user() );

		$result = Access::check_post( $post_id, $require_live );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( rest_authorization_required_code(), $result->get_error_data()['status'] );
	}

	public function test_a_missing_post_is_404_whoever_asks(): void {
		foreach ( array( 0, $this->make_user() ) as $user_id ) {
			wp_set_current_user( $user_id );

			$result = Access::check_post( 999999, false );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 404, $result->get_error_data()['status'] );
		}
	}

	public function test_a_published_post_is_returned(): void {
		$post_id = $this->make_post();

		$this->assertInstanceOf( WP_Post::class, Access::check_post( $post_id, true ) );
		$this->assertInstanceOf( WP_Post::class, Access::check_post( $post_id, false ) );
	}
}
