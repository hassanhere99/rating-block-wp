/**
 * WordPress dependencies
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

test.describe( 'Rating block', () => {
	test( 'a logged-in user votes on the front end and the vote persists', async ( {
		admin,
		editor,
		page,
	} ) => {
		// Insert the block in the editor and publish.
		await admin.createNewPost( { title: 'Rating block e2e' } );
		await editor.insertBlock( {
			name: 'rating-block/rating',
			attributes: { label: 'How was it?' },
		} );
		const postId = await editor.publishPost();
		expect( postId ).toBeTruthy();

		// Front end, logged in. A new post has no votes.
		await page.goto( `/?p=${ postId }` );
		const block = page.locator( '.wp-block-rating-block-rating' );
		const summary = block.locator( '.rating-block__summary' );
		const status = block.locator( '.rating-block__status' );
		const radios = block.getByRole( 'radio' );

		await expect(
			block.getByRole( 'group', { name: 'How was it?' } )
		).toBeVisible();
		await expect( summary ).toHaveText( 'No ratings yet' );
		await expect( radios ).toHaveCount( 5 );

		// The inputs are disabled until the view script has hydrated.
		await expect( radios.first() ).toBeEnabled();

		// Vote 4.
		await block.locator( '.rating-block__star' ).nth( 3 ).click();

		await expect( radios.nth( 3 ) ).toBeChecked();
		await expect( status ).toHaveText(
			'You rated this 4 out of 5. 4.0 out of 5. Ratings: 1'
		);
		await expect( status ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( summary ).toHaveText( '4.0 out of 5. Ratings: 1' );

		// Reload: the vote comes back from the server.
		await page.reload();
		await expect( radios.first() ).toBeEnabled();
		await expect( radios.nth( 3 ) ).toBeChecked();
		await expect( block.locator( 'input:checked' ) ).toHaveCount( 1 );
		await expect( summary ).toHaveText( '4.0 out of 5. Ratings: 1' );

		// Voting again changes the vote; it does not add a second one.
		await block.locator( '.rating-block__star' ).nth( 1 ).click();
		await expect( summary ).toHaveText( '2.0 out of 5. Ratings: 1' );
	} );
} );
