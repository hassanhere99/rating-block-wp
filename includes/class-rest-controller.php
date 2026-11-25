<?php
/**
 * REST routes.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

use WP_Error;
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
	 * Who may vote. See Access::check_vote().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function create_vote_permissions_check( WP_REST_Request $request ) {
		return Access::check_vote( (int) $request['id'] );
	}

	/**
	 * Who may read a summary: anyone, for a post they could view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function get_summary_permissions_check( WP_REST_Request $request ) {
		$post = Access::check_post( (int) $request['id'], false );

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
}
