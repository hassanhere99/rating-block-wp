<?php
/**
 * REST routes.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under rating-block/v1.
 *
 * WordPress validates and sanitizes route args before it calls
 * permission_callback. A request with an invalid score is therefore answered
 * with 400 before the permission checks run, including for a logged-out
 * caller. Only a well-formed request reaches the 401, 403 and 404 checks.
 *
 * Authentication is WordPress cookie auth. The X-WP-Nonce header (the wp_rest
 * nonce) is verified by core before routing. A request that has a session
 * cookie but a missing or wrong nonce is treated as logged out, so it gets 401
 * here, or 403 rest_cookie_invalid_nonce if a bad nonce was sent.
 */
final class Rest_Controller {

	const REST_NAMESPACE = 'rating-block/v1';

	/**
	 * Hook the routes.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register both routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		$post_id_arg = array(
			'description' => __( 'Post ID.', 'rating-block-wp' ),
			'type'        => 'integer',
			'minimum'     => 1,
			'required'    => true,
		);

		// 'schema' is a sibling of the endpoint arrays, not a key inside one.
		// Core only reads it at the route level, for OPTIONS requests.
		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/vote',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_vote' ),
					'permission_callback' => array( __CLASS__, 'create_vote_permissions_check' ),
					'args'                => array(
						'id'    => $post_id_arg,
						'score' => array(
							'description' => __( 'Score from 1 to 5.', 'rating-block-wp' ),
							'type'        => 'integer',
							'minimum'     => Votes::MIN_SCORE,
							'maximum'     => Votes::MAX_SCORE,
							'required'    => true,
						),
					),
				),
				'schema' => array( __CLASS__, 'get_item_schema' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/posts/(?P<id>\d+)/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_summary' ),
					'permission_callback' => array( __CLASS__, 'get_summary_permissions_check' ),
					'args'                => array(
						'id' => $post_id_arg,
					),
				),
				'schema' => array( __CLASS__, 'get_item_schema' ),
			)
		);
	}

	/**
	 * Response schema, shared by both routes.
	 *
	 * @return array
	 */
	public static function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'rating-block-summary',
			'type'       => 'object',
			'properties' => array(
				'average'    => array(
					'description' => __( 'Average score, or null when there are no votes.', 'rating-block-wp' ),
					'type'        => array( 'number', 'null' ),
					'readonly'    => true,
				),
				'count'      => array(
					'description' => __( 'Number of votes.', 'rating-block-wp' ),
					'type'        => 'integer',
					'readonly'    => true,
				),
				'user_score' => array(
					'description' => __( "The current user's score, or null.", 'rating-block-wp' ),
					'type'        => array( 'integer', 'null' ),
					'readonly'    => true,
				),
			),
		);
	}

	/**
	 * Who may vote: a logged-in user who has the 'read' capability on this
	 * site, on a published, viewable post.
	 *
	 * The capability check matters on multisite, where a user can be logged in
	 * to the network without being a member of this site. Such a user has no
	 * role here and so no 'read'. On a single site every role has 'read', and
	 * only a user whose roles were all removed fails the check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function create_vote_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rating_block_wp_not_logged_in',
				__( 'You must be logged in to vote.', 'rating-block-wp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! current_user_can( 'read' ) ) {
			return new WP_Error(
				'rating_block_wp_cannot_read',
				__( 'You are not allowed to vote on this site.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		$post = self::check_post( (int) $request['id'], true );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		/**
		 * Filters whether a post can be rated.
		 *
		 * Runs after the built-in checks, so it can only narrow what they
		 * allow. Return false to refuse votes on a post, for example by post
		 * type or by a meta flag. It applies to voting only; summaries stay
		 * readable.
		 *
		 * @since 0.1.0
		 *
		 * @param bool    $is_rateable Whether the post can be rated. Default true.
		 * @param WP_Post $post        The post being voted on.
		 */
		if ( ! (bool) apply_filters( 'rating_block_wp_post_is_rateable', true, $post ) ) {
			return new WP_Error(
				'rating_block_wp_post_not_rateable',
				__( 'This post cannot be rated.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Who may read a summary: anyone, for a post they could view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function get_summary_permissions_check( WP_REST_Request $request ) {
		$post = self::check_post( (int) $request['id'], false );

		return is_wp_error( $post ) ? $post : true;
	}

	/**
	 * Record a vote and return the new summary.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_vote( WP_REST_Request $request ) {
		$post_id = (int) $request['id'];
		$user_id = get_current_user_id();

		if ( ! Votes::upsert( $post_id, $user_id, (int) $request['score'] ) ) {
			return new WP_Error(
				'rating_block_wp_save_failed',
				__( 'Your vote could not be saved.', 'rating-block-wp' ),
				array( 'status' => 500 )
			);
		}

		// After the write, so a reader that recomputes now sees the new row.
		Summary::invalidate( $post_id );

		// Computed from the table and not cached. Putting this aggregate in the
		// cache would be the write-through the Summary docblock rules out.
		return rest_ensure_response( Summary::get_fresh_for_user( $post_id, $user_id ) );
	}

	/**
	 * Return the summary for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_summary( WP_REST_Request $request ) {
		return rest_ensure_response( Summary::get_for_user( (int) $request['id'], get_current_user_id() ) );
	}

	/**
	 * Load a post and check that it may be rated or summarised.
	 *
	 * 404 if there is no such post. 403 if there is one but it is not
	 * available: not published (draft, pending, private, future, trash), a type
	 * that has no public view, or password protected. This matches how the core
	 * posts endpoint answers for a post the caller cannot read.
	 *
	 * Reading a summary also accepts an unpublished post that the current user
	 * can read, such as their own private post. Voting does not: the spec is
	 * published posts only.
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $require_live True to require the post to be published.
	 * @return WP_Post|WP_Error
	 */
	private static function check_post( int $post_id, bool $require_live ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'rating_block_wp_post_not_found',
				__( 'Post not found.', 'rating-block-wp' ),
				array( 'status' => 404 )
			);
		}

		$allowed = is_post_type_viewable( $post->post_type )
			&& ! post_password_required( $post )
			&& (
				'publish' === $post->post_status
				|| ( ! $require_live && current_user_can( 'read_post', $post->ID ) )
			);

		if ( ! $allowed ) {
			return new WP_Error(
				'rating_block_wp_post_forbidden',
				__( 'This post cannot be rated.', 'rating-block-wp' ),
				array( 'status' => 403 )
			);
		}

		return $post;
	}
}
