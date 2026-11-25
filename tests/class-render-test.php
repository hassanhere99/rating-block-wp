<?php
/**
 * Tests for the block's server-side render.
 *
 * @package RatingBlockWP
 */

namespace RatingBlockWP\Tests;

use RatingBlockWP\Summary;
use RatingBlockWP\Votes;
use WP_Block;
use WP_Block_Type_Registry;
use WP_HTML_Tag_Processor;

/**
 * Renders src/rating/render.php through WP_Block, as WordPress does.
 *
 * The block is registered from build/, so `npm run build` must have run.
 */
final class Render_Test extends Test_Case {

	const BLOCK = 'rating-block/rating';

	public function set_up(): void {
		parent::set_up();

		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK ),
			'The block is not registered. Run `npm run build` first.'
		);
	}

	/**
	 * Render the block for a post, as a block inside a post or Query Loop item.
	 *
	 * @param int|null $post_id Post ID for the postId context, or null for none.
	 * @param array    $attrs   Block attributes.
	 * @return string
	 */
	private function render( ?int $post_id, array $attrs = array() ): string {
		$context = null === $post_id ? array() : array( 'postId' => $post_id );
		$block   = new WP_Block(
			array(
				'blockName'    => self::BLOCK,
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			$context
		);

		return $block->render();
	}

	/**
	 * Visible text of rendered markup, whitespace collapsed.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function text( string $html ): string {
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ) ) ) );
	}

	/**
	 * The text of the summary line.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function summary_text( string $html ): string {
		preg_match( '/<p class="rating-block__summary"[^>]*>(.*?)<\/p>/s', $html, $match );

		return $this->text( $match[1] ?? '' );
	}

	/**
	 * Count the tags with a given name.
	 *
	 * @param string $html Markup.
	 * @param string $tag  Tag name.
	 * @return int
	 */
	private function count_tags( string $html, string $tag ): int {
		$processor = new WP_HTML_Tag_Processor( $html );
		$count     = 0;

		while ( $processor->next_tag( $tag ) ) {
			++$count;
		}

		return $count;
	}

	/**
	 * Every attribute name used anywhere in the markup.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	private function attribute_names( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$names     = array();

		while ( $processor->next_tag() ) {
			$names = array_merge( $names, $processor->get_attribute_names_with_prefix( '' ) ?? array() );
		}

		return array_unique( $names );
	}

	/**
	 * Add votes to a post from fresh users.
	 *
	 * @param int   $post_id Post ID.
	 * @param int[] $scores  One score per new user.
	 * @return int[] The new user IDs.
	 */
	private function add_votes( int $post_id, array $scores ): array {
		$users = array();

		foreach ( $scores as $score ) {
			$users[] = $this->make_user();
			Votes::upsert( $post_id, end( $users ), $score );
		}

		return $users;
	}

	public function test_block_registration(): void {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK );

		$this->assertSame( 'Rating', $type->title );
		$this->assertSame( 3, $type->api_version );
		$this->assertSame( array( 'postId' ), $type->uses_context );
		$this->assertSame( '', $type->attributes['label']['default'] );
		$this->assertTrue( $type->attributes['showCount']['default'] );
		$this->assertTrue( $type->attributes['allowVoting']['default'] );
		$this->assertNotEmpty( $type->view_script_module_ids, 'The front end script is a script module.' );
	}

	public function test_it_renders_the_post_from_context_not_the_global_post(): void {
		$post_a = $this->make_post();
		$post_b = $this->make_post();
		$global = $this->make_post();
		$this->add_votes( $post_a, array( 5, 5 ) );
		$this->add_votes( $post_b, array( 1 ) );
		$this->add_votes( $global, array( 3, 3, 3 ) );

		// A different post is "current", as it is on a page that lists posts.
		$GLOBALS['post'] = get_post( $global ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Setting up the case under test.
		$this->assertSame( $global, get_the_ID(), 'Precondition: the global post is the third post.' );

		$html_a = $this->render( $post_a );
		$html_b = $this->render( $post_b );

		$this->assertStringContainsString( '5.0 out of 5. Ratings: 2', $this->text( $html_a ) );
		$this->assertStringContainsString( '1.0 out of 5. Ratings: 1', $this->text( $html_b ) );
		$this->assertStringNotContainsString( '3.0 out of 5', $html_a . $html_b );

		// The login link returns to the post being rated.
		$this->assertStringContainsString( rawurlencode( get_permalink( $post_a ) ), $html_a );
		$this->assertStringContainsString( rawurlencode( get_permalink( $post_b ) ), $html_b );
	}

	public function test_the_context_post_is_used_for_a_logged_in_voter_too(): void {
		$post_a = $this->make_post();
		$post_b = $this->make_post();
		$global = $this->make_post();
		$user   = $this->make_user();
		Votes::upsert( $post_a, $user, 2 );
		Votes::upsert( $post_b, $user, 5 );
		wp_set_current_user( $user );
		$GLOBALS['post'] = get_post( $global ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Setting up the case under test.

		$context_a = $this->wrapper_context( $this->render( $post_a ) );
		$context_b = $this->wrapper_context( $this->render( $post_b ) );

		$this->assertSame( $post_a, $context_a['postId'] );
		$this->assertSame( 2, $context_a['userScore'] );
		$this->assertSame( $post_b, $context_b['postId'] );
		$this->assertSame( 5, $context_b['userScore'] );
	}

	public function test_it_renders_each_item_of_a_real_query_loop(): void {
		$post_a = self::factory()->post->create( array( 'post_title' => 'Ratingloop alpha' ) );
		$post_b = self::factory()->post->create( array( 'post_title' => 'Ratingloop beta' ) );
		$this->add_votes( $post_a, array( 4, 4, 4 ) );
		$this->add_votes( $post_b, array( 2 ) );

		$html = do_blocks(
			'<!-- wp:query {"queryId":71,"query":{"perPage":10,"pages":0,"offset":0,"postType":"post","order":"asc","orderBy":"title","search":"Ratingloop","exclude":[],"sticky":"","inherit":false}} -->'
			. '<div class="wp-block-query"><!-- wp:post-template -->'
			. '<!-- wp:post-title /--><!-- wp:rating-block/rating /-->'
			. '<!-- /wp:post-template --></div><!-- /wp:query -->'
		);

		$text = $this->text( $html );

		$this->assertSame( 2, substr_count( $html, 'rating-block__summary' ), 'One block per post in the loop.' );
		$this->assertMatchesRegularExpression( '/Ratingloop alpha.*4\.0 out of 5\. Ratings: 3.*Ratingloop beta.*2\.0 out of 5\. Ratings: 1/', $text );
	}

	public function test_a_password_protected_post_renders_nothing(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->add_votes( $post_id, array( 5, 5 ) );

		foreach ( array( 0, $this->make_user() ) as $user_id ) {
			wp_set_current_user( $user_id );

			$this->assertSame( '', trim( $this->render( $post_id ) ), 'No summary for a locked post.' );
			$this->assertSame( 403, $this->get_summary( $post_id )->get_status(), 'The endpoint refuses it too.' );
		}
	}

	/**
	 * The block shows a summary if and only if the summary endpoint would
	 * answer, for every kind of post and every kind of viewer.
	 *
	 * @dataProvider post_states_and_viewers
	 *
	 * @param string $state  Post state.
	 * @param string $viewer Viewer kind.
	 */
	public function test_the_block_and_the_summary_endpoint_allow_the_same_posts( string $state, string $viewer ): void {
		switch ( $state ) {
			case 'password':
				$post_id = self::factory()->post->create(
					array(
						'post_status'   => 'publish',
						'post_password' => 'secret',
					)
				);
				break;
			case 'future':
				$post_id = self::factory()->post->create(
					array(
						'post_status' => 'future',
						'post_date'   => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
					)
				);
				break;
			case 'attachment':
				$post_id = self::factory()->attachment->create();
				break;
			default:
				$post_id = $this->make_post( $state );
		}

		if ( 'admin' === $viewer ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		} elseif ( 'subscriber' === $viewer ) {
			wp_set_current_user( $this->make_user() );
		} else {
			wp_set_current_user( 0 );
		}

		$endpoint_allows = 200 === $this->get_summary( $post_id )->get_status();
		$block_renders   = '' !== trim( $this->render( $post_id ) );

		$this->assertSame( $endpoint_allows, $block_renders, "{$state} post, {$viewer} viewer: block and endpoint must agree." );
	}

	/**
	 * Every post state against every viewer.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function post_states_and_viewers(): array {
		$cases = array();

		foreach ( array( 'publish', 'draft', 'pending', 'private', 'trash', 'future', 'password', 'attachment' ) as $state ) {
			foreach ( array( 'logged out', 'subscriber', 'admin' ) as $viewer ) {
				$cases[ "{$state} / {$viewer}" ] = array( $state, $viewer );
			}
		}

		return $cases;
	}

	public function test_nothing_renders_without_a_post(): void {
		$this->assertSame( '', trim( $this->render( null ) ), 'No postId in context.' );
		$this->assertSame( '', trim( $this->render( 999999 ) ), 'A post that does not exist.' );
	}

	/**
	 * A label with markup or quotes is shown as text and never becomes markup.
	 *
	 * @dataProvider malicious_labels
	 *
	 * @param string $label Label attribute.
	 */
	public function test_label_is_escaped( string $label ): void {
		$post_id = $this->make_post();

		foreach ( array( 'logged out', 'logged in' ) as $viewer ) {
			wp_set_current_user( 'logged in' === $viewer ? $this->make_user() : 0 );
			$html = $this->render( $post_id, array( 'label' => $label ) );

			$this->assertStringNotContainsString( $label, $html, "Raw label must not appear ({$viewer})." );
			$this->assertStringContainsString( esc_html( $label ), $html, "Escaped label appears as text ({$viewer})." );
			$this->assertSame( 0, $this->count_tags( $html, 'script' ), "No script element ({$viewer})." );
			$this->assertSame( 0, $this->count_tags( $html, 'img' ), "No injected img element ({$viewer})." );
			$this->assertSame( array(), preg_grep( '/^on(?!.*data-wp)/', $this->attribute_names( $html ) ), "No inline event handler attribute ({$viewer})." );
		}
	}

	/**
	 * Labels that try to break out of text and out of an attribute.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malicious_labels(): array {
		return array(
			'script tag'            => array( '<script>alert(1)</script>' ),
			'attribute breaking'    => array( '"><img src=x onerror=alert(1)>' ),
			'single quote breakout' => array( "' onmouseover='alert(1)" ),
			'ampersand and tag'     => array( '</legend></fieldset><b onclick="x">&amp;' ),
		);
	}

	public function test_wrapper_class_name_is_escaped(): void {
		$post_id = $this->make_post();

		foreach ( array( 'x" onmouseover="alert(1)', '"><script>alert(1)</script>' ) as $class_name ) {
			$html = $this->render( $post_id, array( 'className' => $class_name ) );

			$this->assertSame( 0, $this->count_tags( $html, 'script' ) );
			$this->assertNotContains( 'onmouseover', $this->attribute_names( $html ) );
		}
	}

	public function test_logged_in_voter_gets_a_fieldset_with_five_radios(): void {
		$post_id = $this->make_post();
		wp_set_current_user( $this->make_user() );

		$html = $this->render( $post_id );

		$this->assertSame( 1, $this->count_tags( $html, 'fieldset' ) );
		$this->assertSame( 1, $this->count_tags( $html, 'legend' ) );
		$this->assertSame( 5, substr_count( $html, 'type="radio"' ) );
		$this->assertStringContainsString( '>Rate this post</legend>', $html, 'Default legend.' );
		$this->assertStringContainsString( 'aria-live="polite"', $html );

		// One group: the radios share a name, so the arrow keys move between them.
		preg_match_all( '/name="([^"]+)"/', $html, $names );
		$this->assertCount( 1, array_unique( $names[1] ) );

		// Each radio has a label that names its score.
		foreach ( range( 1, 5 ) as $score ) {
			$this->assertMatchesRegularExpression( '/<label[^>]*for="[^"]+-' . $score . '"[^>]*>\s*<span[^>]*>\s*' . $score . ' out of 5\s*<\/span>/', $html );
		}
	}

	public function test_custom_label_becomes_the_legend(): void {
		wp_set_current_user( $this->make_user() );

		$html = $this->render( $this->make_post(), array( 'label' => 'How was the recipe?' ) );

		$this->assertStringContainsString( '>How was the recipe?</legend>', $html );
	}

	public function test_two_blocks_on_a_page_have_separate_radio_groups(): void {
		wp_set_current_user( $this->make_user() );
		$post_id = $this->make_post();

		preg_match( '/name="([^"]+)"/', $this->render( $post_id ), $first );
		preg_match( '/name="([^"]+)"/', $this->render( $post_id ), $second );

		$this->assertNotSame( $first[1], $second[1] );
	}

	public function test_voters_own_score_is_checked(): void {
		$post_id = $this->make_post();
		$user    = $this->make_user();
		Votes::upsert( $post_id, $user, 4 );
		wp_set_current_user( $user );

		$html = $this->render( $post_id );

		$this->assertSame( 1, substr_count( $html, ' checked' ), 'Exactly one star is checked.' );
		$this->assertMatchesRegularExpression( '/<input[^>]*value="4"[^>]*checked|<input[^>]*checked[^>]*value="4"/s', $html );
	}

	public function test_allow_voting_false_renders_no_inputs(): void {
		$post_id = $this->make_post();
		$this->add_votes( $post_id, array( 4 ) );

		foreach ( array( 0, $this->make_user() ) as $user_id ) {
			wp_set_current_user( $user_id );
			$html = $this->render( $post_id, array( 'allowVoting' => false ) );

			$this->assertSame( 0, $this->count_tags( $html, 'input' ) );
			$this->assertSame( 0, $this->count_tags( $html, 'fieldset' ) );
			$this->assertStringNotContainsString( 'data-wp-', $html );
			$this->assertStringNotContainsString( 'wp-login.php', $html, 'No login link when voting is off.' );
			$this->assertStringContainsString( '4.0 out of 5. Ratings: 1', $this->text( $html ) );
		}
	}

	public function test_logged_out_visitor_sees_the_rating_and_a_login_link(): void {
		$post_id = $this->make_post();
		$this->add_votes( $post_id, array( 5, 4 ) );

		$html = $this->render( $post_id );

		$this->assertSame( 0, $this->count_tags( $html, 'input' ) );
		$this->assertStringContainsString( '4.5 out of 5. Ratings: 2', $this->text( $html ) );
		$this->assertStringContainsString( 'wp-login.php?redirect_to=', $html );
		$this->assertStringContainsString( 'Log in to rate this post', $html );
		$this->assertStringNotContainsString( 'data-wp-', $html, 'A read-only block ships no directives.' );
	}

	public function test_a_post_that_cannot_be_voted_on_gets_no_inputs_and_no_login_link(): void {
		$draft = $this->make_post( 'draft' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$html = $this->render( $draft );

		$this->assertSame( 0, $this->count_tags( $html, 'input' ) );
		$this->assertStringNotContainsString( 'wp-login.php', $html );
		$this->assertStringContainsString( 'No ratings yet', $html );

		wp_set_current_user( 0 );
		$this->assertStringNotContainsString( 'wp-login.php', $this->render( $draft ), 'A logged-out visitor would still be refused, so no link.' );
	}

	public function test_the_block_offers_no_vote_the_endpoint_would_refuse(): void {
		$post_id = $this->make_post();
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		add_filter( 'rating_block_wp_post_is_rateable', '__return_false' );
		$this->assertSame( 0, $this->count_tags( $this->render( $post_id ), 'input' ), 'Filter says no.' );
		remove_all_filters( 'rating_block_wp_post_is_rateable' );

		// Remove the role first, then make the user current, so the current user
		// object is built without it.
		get_user_by( 'id', $user_id )->set_role( '' );
		wp_set_current_user( 0 ); // Same ID would return the cached user object.
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'read' ), 'Precondition: no read capability.' );
		$this->assertSame( 0, $this->count_tags( $this->render( $post_id ), 'input' ), 'User has no read capability.' );
	}

	public function test_no_votes_says_no_ratings_yet_and_never_zero_stars(): void {
		$post_id = $this->make_post();

		foreach ( array( 0, $this->make_user() ) as $user_id ) {
			wp_set_current_user( $user_id );
			$html = $this->render( $post_id );
			$text = $this->text( $html );

			$this->assertStringContainsString( 'No ratings yet', $text );
			$this->assertSame( 'No ratings yet', $this->summary_text( $html ), 'The summary line is only that, with no average.' );
			$this->assertStringNotContainsString( 'rating-block__display', $html, 'No star row at all, so no zero-star row.' );
			$this->assertStringNotContainsString( '--rating-block-fill', $html );
			$this->assertStringNotContainsString( 'Ratings: 0', $text );
		}
	}

	public function test_no_votes_with_the_count_hidden_still_says_no_ratings_yet(): void {
		$this->assertStringContainsString( 'No ratings yet', $this->text( $this->render( $this->make_post(), array( 'showCount' => false ) ) ) );
	}

	public function test_show_count_false_hides_the_count_but_keeps_the_average(): void {
		$post_id = $this->make_post();
		$this->add_votes( $post_id, array( 4, 5, 3 ) );

		foreach ( array( 0, $this->make_user() ) as $user_id ) {
			wp_set_current_user( $user_id );
			$text = $this->text( $this->render( $post_id, array( 'showCount' => false ) ) );

			$this->assertStringContainsString( '4.0 out of 5', $text );
			$this->assertStringNotContainsString( 'Ratings', $text );
		}
	}

	public function test_average_stars_are_filled_in_proportion_to_the_average(): void {
		$post_id = $this->make_post();
		$this->add_votes( $post_id, array( 4, 3 ) ); // 3.5 of 5 is 70%.

		$this->assertStringContainsString( '--rating-block-fill: 70%', $this->render( $post_id ) );
	}

	public function test_the_block_reads_through_summary_get_for_user(): void {
		$post_id = $this->make_post();
		$this->add_votes( $post_id, array( 1 ) );

		// If render.php ran its own query it would show the real 1.0 and 1.
		wp_cache_set(
			Summary::KEY_PREFIX . $post_id,
			array(
				'count'   => 42,
				'average' => 3.2,
			),
			Summary::CACHE_GROUP
		);

		$this->assertStringContainsString( '3.2 out of 5. Ratings: 42', $this->text( $this->render( $post_id ) ) );
	}

	public function test_the_block_and_the_summary_endpoint_agree(): void {
		$post_id = $this->make_post();
		$voter   = $this->add_votes( $post_id, array( 5, 4, 4 ) )[0];
		wp_set_current_user( $voter );

		$endpoint = $this->get_summary( $post_id )->get_data();
		$context  = $this->wrapper_context( $this->render( $post_id ) );

		$this->assertEquals( $endpoint['average'], $context['average'] );
		$this->assertSame( $endpoint['count'], $context['count'] );
		$this->assertSame( $endpoint['user_score'], $context['userScore'] );
	}

	/**
	 * Without the view script the markup is a read-only display that reads
	 * correctly as plain HTML.
	 *
	 * @dataProvider viewer_kinds
	 *
	 * @param string $viewer Which kind of viewer.
	 */
	public function test_markup_is_readable_without_javascript( string $viewer ): void {
		$post_id = $this->make_post();
		$users   = $this->add_votes( $post_id, array( 5, 4, 4 ) );

		wp_set_current_user( 'logged in' === $viewer ? $users[0] : 0 );
		$html = $this->render( $post_id, array( 'label' => 'Recipe rating' ) );
		$text = $this->text( $html );

		// The label, average and count are plain text.
		$this->assertStringContainsString( 'Recipe rating', $text );
		$this->assertStringContainsString( '4.3 out of 5. Ratings: 3', $text );

		// Nothing is hidden until a script reveals it.
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$this->assertNull( $processor->get_attribute( 'hidden' ), 'No hidden elements.' );
			$this->assertStringNotContainsString( 'display:none', (string) $processor->get_attribute( 'style' ) );
		}

		// The live region, where there is one, starts empty so nothing stale is
		// announced. A read-only block has nothing to announce and no region.
		if ( 'logged in' === $viewer ) {
			$this->assertMatchesRegularExpression( '/aria-live="polite"[^>]*><\/p>/', $html );
		} else {
			$this->assertStringNotContainsString( 'aria-live', $html );
		}

		// Any radio that is present is disabled until the script enables it, so
		// the block cannot offer a vote that nothing will send.
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'input' ) ) {
			$this->assertNotNull( $processor->get_attribute( 'disabled' ), 'Radios are disabled without the script.' );
		}
	}

	/**
	 * Both kinds of viewer.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function viewer_kinds(): array {
		return array(
			'logged out' => array( 'logged out' ),
			'logged in'  => array( 'logged in' ),
		);
	}

	public function test_interactive_markup_starts_disabled_and_not_marked_interactive(): void {
		wp_set_current_user( $this->make_user() );

		$html = $this->render( $this->make_post() );

		$this->assertSame( 5, substr_count( $html, ' disabled' ) );

		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag( array( 'class_name' => 'rating-block__choices' ) );
		$this->assertFalse( $processor->has_class( 'is-interactive' ), 'The class that enables hover is only added by the script.' );
		$this->assertTrue( $this->wrapper_context( $html )['isDisabled'] );
	}

	/**
	 * The block wrapper's data-wp-context, decoded.
	 *
	 * @param string $html Markup.
	 * @return array
	 */
	private function wrapper_context( string $html ): array {
		$processor = new WP_HTML_Tag_Processor( $html );
		$processor->next_tag();
		$raw = $processor->get_attribute( 'data-wp-context' );

		$this->assertIsString( $raw, 'The wrapper carries the interactivity context.' );

		return json_decode( $raw, true );
	}
}
