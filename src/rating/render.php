<?php
/**
 * Front end render for the rating block.
 *
 * Placeholder until the block is built in a later phase.
 *
 * @package RatingBlockWP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

?>
<div <?php echo wp_kses_data( get_block_wrapper_attributes() ); ?>>
	<?php esc_html_e( 'Rating Block placeholder.', 'rating-block-wp' ); ?>
</div>
