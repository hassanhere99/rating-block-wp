<?php
/**
 * Vote storage.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes rows in the votes table.
 *
 * This class only touches the table. Cache invalidation is the caller's job,
 * because the caller knows when the write has committed.
 */
final class Votes {

	const MIN_SCORE = 1;
	const MAX_SCORE = 5;

	/**
	 * Insert a vote, or change the existing one.
	 *
	 * A single statement. The UNIQUE KEY (post_id, user_id) makes the database
	 * decide between insert and update, so two concurrent requests from the
	 * same user cannot both insert. The new score is passed as a second
	 * parameter rather than with VALUES(score): VALUES() is deprecated in
	 * MySQL 8.0.20 and the replacement syntax does not exist in MariaDB.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @param int $score   Score, 1 to 5.
	 * @return bool True on success.
	 */
	public static function upsert( int $post_id, int $user_id, int $score ): bool {
		global $wpdb;

		if ( $score < self::MIN_SCORE || $score > self::MAX_SCORE || $post_id < 1 || $user_id < 1 ) {
			return false;
		}

		$now = current_time( 'mysql', true ); // UTC.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; the aggregate is cached by Summary.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (post_id, user_id, score, created_at, updated_at)
				VALUES (%d, %d, %d, %s, %s)
				ON DUPLICATE KEY UPDATE score = %d, updated_at = %s',
				Schema::table_name(),
				$post_id,
				$user_id,
				$score,
				$now,
				$now,
				$score,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Count and average for a post, straight from the table.
	 *
	 * The average is null when there are no votes. 0 would be a value outside
	 * the 1 to 5 range and could be mistaken for a real score.
	 *
	 * @param int $post_id Post ID.
	 * @return array{count: int, average: float|null}
	 */
	public static function aggregate( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Called by Summary on a cache miss.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT COUNT(*) AS vote_count, AVG(score) AS average FROM %i WHERE post_id = %d', Schema::table_name(), $post_id ),
			ARRAY_A
		);

		$count = isset( $row['vote_count'] ) ? (int) $row['vote_count'] : 0;

		return array(
			'count'   => $count,
			'average' => $count > 0 ? round( (float) $row['average'], 2 ) : null,
		);
	}

	/**
	 * One user's score on one post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return int|null The score, or null if the user has not voted.
	 */
	public static function user_score( int $post_id, int $user_id ): ?int {
		global $wpdb;

		if ( $user_id < 1 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-user value, deliberately not cached.
		$score = $wpdb->get_var(
			$wpdb->prepare( 'SELECT score FROM %i WHERE post_id = %d AND user_id = %d', Schema::table_name(), $post_id, $user_id )
		);

		return null === $score ? null : (int) $score;
	}

	/**
	 * IDs of the posts a user has voted on.
	 *
	 * @param int $user_id User ID.
	 * @return int[]
	 */
	public static function post_ids_for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Used once when a user is deleted.
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT post_id FROM %i WHERE user_id = %d', Schema::table_name(), $user_id )
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Delete every vote by a user.
	 *
	 * There is no index on user_id alone, so this scans the table. User
	 * deletion is rare and the table is narrow.
	 *
	 * @param int $user_id User ID.
	 * @return bool True on success.
	 */
	public static function delete_for_user( int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup when a user is deleted.
		$result = $wpdb->delete( Schema::table_name(), array( 'user_id' => $user_id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Delete every vote on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True on success.
	 */
	public static function delete_for_post( int $post_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup when a post is deleted.
		$result = $wpdb->delete( Schema::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );

		return false !== $result;
	}
}
