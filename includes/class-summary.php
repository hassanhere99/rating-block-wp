<?php
/**
 * Cached vote summary.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

/**
 * The one place a post's rating summary is computed.
 *
 * The REST summary endpoint and the block's render.php both call
 * get_for_user(), so the page and the API cannot disagree.
 *
 * Caching is invalidate-on-write. A vote deletes the cached summary and the
 * next read recomputes it from the table. A vote never writes the new
 * aggregate into the cache. Two concurrent votes would each compute an
 * aggregate from the rows they could see and the one that finished last would
 * overwrite the other, which can leave a stale average in the cache. Deleting
 * is idempotent: it does not matter which delete runs last.
 *
 * The same race exists in a narrower form on the read side. A reader can
 * compute the aggregate, a vote can commit and delete the key, and the reader
 * can then store its older result. CACHE_TTL bounds how long that can last.
 */
final class Summary {

	const CACHE_GROUP = 'rating_block_wp';

	/**
	 * Transient key prefix. uninstall.php deletes options matching it.
	 */
	const KEY_PREFIX = 'rating_block_wp_summary_';

	/**
	 * Backstop for the read-side race described above. Not the freshness
	 * mechanism; invalidation is.
	 */
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Count and average for a post, from cache when possible.
	 *
	 * With a persistent object cache, wp_cache_* is the only store. Without
	 * one, wp_cache_* only lasts for the request, so a transient (a database
	 * option) carries the value between requests.
	 *
	 * @param int $post_id Post ID.
	 * @return array{count: int, average: float}
	 */
	public static function get( int $post_id ): array {
		$cached = self::cache_get( $post_id );

		if ( is_array( $cached ) && isset( $cached['count'], $cached['average'] ) ) {
			return array(
				'count'   => (int) $cached['count'],
				'average' => (float) $cached['average'],
			);
		}

		$summary = Votes::aggregate( $post_id );
		self::cache_set( $post_id, $summary );

		return $summary;
	}

	/**
	 * Summary plus one user's own score. This is the shape both the REST
	 * endpoint and the block use.
	 *
	 * The user's score is read from the table every time and never cached
	 * with the shared aggregate.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID, or 0 for a logged-out visitor.
	 * @return array{average: float, count: int, user_score: int|null}
	 */
	public static function get_for_user( int $post_id, int $user_id ): array {
		$summary = self::get( $post_id );

		return array(
			'average'    => $summary['average'],
			'count'      => $summary['count'],
			'user_score' => Votes::user_score( $post_id, $user_id ),
		);
	}

	/**
	 * Drop the cached summary for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function invalidate( int $post_id ): void {
		wp_cache_delete( self::key( $post_id ), self::CACHE_GROUP );
		delete_transient( self::key( $post_id ) );
	}

	/**
	 * Cache key for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function key( int $post_id ): string {
		return self::KEY_PREFIX . $post_id;
	}

	/**
	 * Read from the cache.
	 *
	 * @param int $post_id Post ID.
	 * @return mixed False on a miss.
	 */
	private static function cache_get( int $post_id ) {
		$value = wp_cache_get( self::key( $post_id ), self::CACHE_GROUP );

		if ( false === $value && ! wp_using_ext_object_cache() ) {
			$value = get_transient( self::key( $post_id ) );
		}

		return $value;
	}

	/**
	 * Write to the cache.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $summary Summary to store.
	 * @return void
	 */
	private static function cache_set( int $post_id, array $summary ): void {
		wp_cache_set( self::key( $post_id ), $summary, self::CACHE_GROUP, self::CACHE_TTL );

		if ( ! wp_using_ext_object_cache() ) {
			set_transient( self::key( $post_id ), $summary, self::CACHE_TTL );
		}
	}
}
