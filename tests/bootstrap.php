<?php
/**
 * PHPUnit bootstrap. Loads the WordPress test suite and this plugin.
 *
 * Run through wp-env, where WP_TESTS_DIR points at the suite:
 * npm run test:php
 *
 * @package RatingBlockWP
 */

$rating_block_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $rating_block_wp_tests_dir ) {
	$rating_block_wp_tests_dir = '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $rating_block_wp_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test suite in {$rating_block_wp_tests_dir}. Set WP_TESTS_DIR.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	exit( 1 );
}

// The suite needs the Yoast polyfills to run on more than one PHPUnit version.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The WordPress test suite reads this name.
}

require_once $rating_block_wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		global $wpdb;

		// Start from a table built by the current schema, not one left over
		// from an earlier run.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rating_block_votes" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test setup.

		require dirname( __DIR__ ) . '/rating-block-wp.php';
	}
);

require $rating_block_wp_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/class-test-case.php';
