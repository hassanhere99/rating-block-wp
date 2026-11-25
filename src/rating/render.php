<?php
/**
 * Front end render for the rating block.
 *
 * The markup is complete without JavaScript: the average and count are plain
 * text, and the radio inputs are rendered disabled. The view script enables
 * them, so without it the block is a read-only display.
 *
 * Data comes from Summary::get_for_user(), the same function the REST summary
 * endpoint uses. Whether the viewer may vote comes from Access::check_vote(),
 * the same check the vote endpoint runs.
 *
 * @package RatingBlockWP
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner block content.
 * @var WP_Block $block      Block instance.
 */

namespace RatingBlockWP;

defined( 'ABSPATH' ) || exit;

// The post comes from block context, which is what a Query Loop sets for each
// item. It is never read from get_the_ID() or the global $post, so the block
// shows the right post wherever it is placed.
$rating_block_wp_post_id = isset( $block->context['postId'] ) ? absint( $block->context['postId'] ) : 0;

// Show nothing for a post the summary endpoint would refuse, such as a draft
// the viewer cannot read or a password-protected post that is still locked.
// The block must not reveal a rating the API would not give out.
if ( ! $rating_block_wp_post_id || is_wp_error( Access::check_post( $rating_block_wp_post_id, false ) ) ) {
	return;
}

$rating_block_wp_user_id = get_current_user_id();
$rating_block_wp_data    = Summary::get_for_user( $rating_block_wp_post_id, $rating_block_wp_user_id );

// Attributes arrive with their block.json defaults applied; the fallbacks only
// cover a block that was saved with unexpected values.
$rating_block_wp_label = isset( $attributes['label'] ) && is_string( $attributes['label'] ) ? trim( $attributes['label'] ) : '';
if ( '' === $rating_block_wp_label ) {
	$rating_block_wp_label = __( 'Rate this post', 'rating-block-wp' );
}
$rating_block_wp_show_count = ! isset( $attributes['showCount'] ) || (bool) $attributes['showCount'];
$rating_block_wp_allow      = ! isset( $attributes['allowVoting'] ) || (bool) $attributes['allowVoting'];

$rating_block_wp_can_vote = $rating_block_wp_allow && true === Access::check_vote( $rating_block_wp_post_id );
// Offer a login link only where logging in would help: the post can be rated
// and the visitor is logged out.
$rating_block_wp_show_login = $rating_block_wp_allow
	&& ! is_user_logged_in()
	&& ! is_wp_error( Access::check_post( $rating_block_wp_post_id, true ) );

$rating_block_wp_average = $rating_block_wp_data['average']; // Null when there are no votes.
$rating_block_wp_count   = $rating_block_wp_data['count'];

$rating_block_wp_strings = array(
	'noRatings'        => __( 'No ratings yet', 'rating-block-wp' ),
	/* translators: %s: average score, for example 4.2. */
	'averageOnly'      => __( '%s out of 5', 'rating-block-wp' ),
	/* translators: 1: average score, for example 4.2. 2: number of ratings. */
	'averageWithCount' => __( '%1$s out of 5. Ratings: %2$s', 'rating-block-wp' ),
	/* translators: %s: the score the user gave, from 1 to 5. */
	'saved'            => __( 'You rated this %s out of 5.', 'rating-block-wp' ),
	'saveFailed'       => __( 'Your rating could not be saved.', 'rating-block-wp' ),
	'sessionExpired'   => __( 'Your session has expired. Reload the page to rate.', 'rating-block-wp' ),
	/* translators: %s: the score the user gave before, from 1 to 5. */
	'previousRestored' => __( 'Your previous rating of %s out of 5 is unchanged.', 'rating-block-wp' ),
	'noPreviousRating' => __( 'No rating was recorded.', 'rating-block-wp' ),
);

// The summary line, as it is shown and as the view script rebuilds it. Counts
// are not pluralised: the view script cannot call _n(), so the wording avoids
// plural forms ("Ratings: 12") and reads the same in both places.
if ( null === $rating_block_wp_average ) {
	$rating_block_wp_summary = $rating_block_wp_strings['noRatings'];
} elseif ( $rating_block_wp_show_count ) {
	$rating_block_wp_summary = sprintf(
		$rating_block_wp_strings['averageWithCount'],
		number_format_i18n( $rating_block_wp_average, 1 ),
		number_format_i18n( $rating_block_wp_count )
	);
} else {
	$rating_block_wp_summary = sprintf( $rating_block_wp_strings['averageOnly'], number_format_i18n( $rating_block_wp_average, 1 ) );
}

$rating_block_wp_id = wp_unique_id( 'rating-block-' );

if ( $rating_block_wp_can_vote ) {
	wp_interactivity_state(
		'rating-block/rating',
		array(
			'restUrl'   => esc_url_raw( rest_url( Rest_Controller::REST_NAMESPACE . '/posts/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'locale'    => get_bloginfo( 'language' ),
			'strings'   => $rating_block_wp_strings,
			// Derived state: whether this star is the viewer's score. It runs on
			// the server for the first render and in the view script after that,
			// and reads the context of the star being processed.
			'isChecked' => static function () {
				$context = wp_interactivity_get_context();

				return isset( $context['score'], $context['userScore'] ) && $context['score'] === $context['userScore'];
			},
		)
	);
}
?>
<?php // get_block_wrapper_attributes() runs esc_attr() on every value it returns, so it is echoed as is. ?>
<div
	<?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php if ( $rating_block_wp_can_vote ) : ?>
		data-wp-interactive="rating-block/rating"
		<?php
		echo wp_interactivity_data_wp_context( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hex-encoded JSON in a single-quoted attribute.
			array(
				'postId'     => $rating_block_wp_post_id,
				'userScore'  => $rating_block_wp_data['user_score'],
				'average'    => $rating_block_wp_average,
				'count'      => $rating_block_wp_count,
				'showCount'  => $rating_block_wp_show_count,
				'summary'    => $rating_block_wp_summary,
				'message'    => '',
				'isError'    => false,
				'isDisabled' => true,
				'requestId'  => 0,
			)
		);
		?>
		data-wp-init="callbacks.init"
	<?php endif; ?>
>
<?php if ( $rating_block_wp_can_vote ) : ?>
	<fieldset class="rating-block__fieldset">
		<legend class="rating-block__title"><?php echo esc_html( $rating_block_wp_label ); ?></legend>
		<div class="rating-block__choices" data-wp-class--is-interactive="!context.isDisabled">
			<?php for ( $rating_block_wp_score = 1; $rating_block_wp_score <= Votes::MAX_SCORE; $rating_block_wp_score++ ) : ?>
				<input
					type="radio"
					class="rating-block__input"
					name="<?php echo esc_attr( $rating_block_wp_id ); ?>"
					id="<?php echo esc_attr( $rating_block_wp_id . '-' . $rating_block_wp_score ); ?>"
					value="<?php echo esc_attr( (string) $rating_block_wp_score ); ?>"
					<?php echo wp_interactivity_data_wp_context( array( 'score' => $rating_block_wp_score ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Hex-encoded JSON in a single-quoted attribute. ?>
					data-wp-bind--checked="state.isChecked"
					data-wp-bind--disabled="context.isDisabled"
					data-wp-on--change="actions.vote"
				/>
				<label class="rating-block__star" for="<?php echo esc_attr( $rating_block_wp_id . '-' . $rating_block_wp_score ); ?>">
					<span class="rating-block__sr">
						<?php
						/* translators: %s: a score from 1 to 5. */
						echo esc_html( sprintf( __( '%s out of 5', 'rating-block-wp' ), number_format_i18n( $rating_block_wp_score ) ) );
						?>
					</span>
				</label>
			<?php endfor; ?>
		</div>
		<p class="rating-block__summary" data-wp-text="context.summary"><?php echo esc_html( $rating_block_wp_summary ); ?></p>
		<p class="rating-block__status" aria-live="polite" aria-atomic="true" data-wp-text="context.message" data-wp-class--is-error="context.isError"></p>
	</fieldset>
<?php else : ?>
	<p class="rating-block__title"><?php echo esc_html( $rating_block_wp_label ); ?></p>
	<?php if ( null !== $rating_block_wp_average ) : ?>
		<span class="rating-block__display" style="--rating-block-fill: <?php echo esc_attr( (string) round( $rating_block_wp_average / Votes::MAX_SCORE * 100, 1 ) ); ?>%" aria-hidden="true"></span>
	<?php endif; ?>
	<p class="rating-block__summary"><?php echo esc_html( $rating_block_wp_summary ); ?></p>
	<?php if ( $rating_block_wp_show_login ) : ?>
		<p class="rating-block__login">
			<a href="<?php echo esc_url( wp_login_url( (string) get_permalink( $rating_block_wp_post_id ) ) ); ?>"><?php esc_html_e( 'Log in to rate this post', 'rating-block-wp' ); ?></a>
		</p>
	<?php endif; ?>
<?php endif; ?>
</div>
