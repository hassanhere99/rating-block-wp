<?php
/**
 * Plugin Name:       Rating Block
 * Description:       A 1 to 5 star rating block for the current post. Logged-in users vote; everyone sees the average.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Author:            Muhammad Hassan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rating-block-wp
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

define( 'RATING_BLOCK_WP_VERSION', '0.1.0' );
define( 'RATING_BLOCK_WP_FILE', __FILE__ );
define( 'RATING_BLOCK_WP_DIR', plugin_dir_path( __FILE__ ) );

require_once RATING_BLOCK_WP_DIR . 'includes/class-schema.php';
require_once RATING_BLOCK_WP_DIR . 'includes/class-votes.php';
require_once RATING_BLOCK_WP_DIR . 'includes/class-summary.php';
require_once RATING_BLOCK_WP_DIR . 'includes/class-access.php';
require_once RATING_BLOCK_WP_DIR . 'includes/class-rest-controller.php';
require_once RATING_BLOCK_WP_DIR . 'includes/class-cleanup.php';

register_activation_hook( __FILE__, array( Schema::class, 'install' ) );

// Activation hooks do not run on update, and do not run per site for a
// network-activated plugin. This catches both.
add_action( 'plugins_loaded', array( Schema::class, 'maybe_upgrade' ) );

Rest_Controller::register();
Cleanup::register();

/**
 * Register the block from the built block.json.
 *
 * This uses register_block_type() rather than the collection helper added in
 * WordPress 6.8, because the plugin supports WordPress 6.6.
 *
 * @return void
 */
function rating_block_wp_register_block(): void {
	register_block_type( RATING_BLOCK_WP_DIR . 'build/rating' );
}
add_action( 'init', __NAMESPACE__ . '\rating_block_wp_register_block' );
