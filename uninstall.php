<?php
/**
 * Remove the plugin's data.
 *
 * Runs when the plugin is deleted from the Plugins screen. Deactivating the
 * plugin does not remove votes.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-schema.php';

if ( is_multisite() ) {
	// The table and options are per site, so each site has to be cleaned.
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $rating_block_wp_site_id ) {
		switch_to_blog( $rating_block_wp_site_id );
		Schema::uninstall_site();
		restore_current_blog();
	}
} else {
	Schema::uninstall_site();
}
