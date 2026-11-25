<?php
/**
 * Database schema for stored votes.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the votes table.
 *
 * The schema version is stored per site. A request compares it with
 * SCHEMA_VERSION and runs the installer when they differ. That one check covers
 * a fresh activation, a plugin update that changes the schema, a plugin that is
 * network-activated on a multisite install, and a site created after
 * network activation.
 */
final class Schema {

	/**
	 * Current schema version. Bump when the CREATE TABLE statement changes.
	 */
	const SCHEMA_VERSION = '1';

	/**
	 * Option that stores the installed schema version.
	 */
	const VERSION_OPTION = 'rating_block_wp_schema_version';

	/**
	 * Name of the votes table for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'rating_block_votes';
	}

	/**
	 * Build the CREATE TABLE statement.
	 *
	 * Kept separate from install() so tests can assert on it.
	 *
	 * dbDelta() is picky: two spaces after PRIMARY KEY, one definition per
	 * line, and KEY rather than INDEX.
	 *
	 * Timestamps have no column default. Zero-date defaults are rejected under
	 * strict SQL modes and CURRENT_TIMESTAMP defaults are not uniform across
	 * the MySQL and MariaDB versions WordPress supports, so the writer sets
	 * both values explicitly, in UTC.
	 *
	 * The 1 to 5 range on score is enforced in PHP. CHECK constraints are
	 * ignored by MySQL before 8.0.16 and dbDelta() does not manage them.
	 *
	 * @return string
	 */
	public static function create_table_sql(): string {
		global $wpdb;

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	post_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	score tinyint(3) unsigned NOT NULL,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY post_user (post_id,user_id),
	KEY post_id (post_id)
) {$charset_collate};";
	}

	/**
	 * Create or update the table, then record the schema version.
	 *
	 * Safe to call repeatedly. The version is only written if the table exists
	 * afterwards, so a failed dbDelta() is retried on the next request.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::create_table_sql() );

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check on our own table; caching it would defeat the check.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		if ( $exists === $table ) {
			update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
		}
	}

	/**
	 * Run the installer when the stored version is behind the code.
	 *
	 * Hooked to plugins_loaded. Activation hooks do not fire on plugin
	 * updates, so this is the only place an upgrade can be detected.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Drop the table and remove this site's options and transients.
	 *
	 * Called from uninstall.php, once per site on multisite.
	 *
	 * @return void
	 */
	public static function uninstall_site(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing our own table on uninstall.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) );

		delete_option( self::VERSION_OPTION );

		// Transients live in wp_options unless an external object cache is in
		// use. With an external cache they expire on their own TTL and there is
		// nothing in the database to delete.
		$like_value   = $wpdb->esc_like( '_transient_rating_block_wp_' ) . '%';
		$like_timeout = $wpdb->esc_like( '_transient_timeout_rating_block_wp_' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete of this plugin's transients by prefix; no API exists for it.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like_value, $like_timeout ) );
	}
}
