import { describe, expect, it } from 'vitest';
import {
	buildSummary,
	format,
	formatNumber,
	isSessionExpired,
} from '../summary';

const strings = {
	noRatings: 'No ratings yet',
	averageOnly: '%s out of 5',
	averageWithCount: '%1$s out of 5. Ratings: %2$s',
};

describe( 'format', () => {
	it( 'fills sequential placeholders', () => {
		expect( format( '%s of %s', 'a', 'b' ) ).toBe( 'a of b' );
	} );

	it( 'fills positional placeholders in any order', () => {
		expect( format( '%2$s then %1$s', 'a', 'b' ) ).toBe( 'b then a' );
	} );
} );

describe( 'formatNumber', () => {
	it( 'formats to a fixed number of digits', () => {
		expect( formatNumber( 4, 'en-US', 1 ) ).toBe( '4.0' );
		expect( formatNumber( 1234, 'en-US', 0 ) ).toBe( '1,234' );
	} );

	it( 'uses the locale decimal separator', () => {
		expect( formatNumber( 4.5, 'de-DE', 1 ) ).toBe( '4,5' );
	} );

	it( 'falls back for a locale Intl rejects', () => {
		expect( formatNumber( 4.5, 'not a locale!', 1 ) ).toBe( '4.5' );
	} );
} );

describe( 'buildSummary', () => {
	it( 'says there are no ratings when the average is null', () => {
		const text = buildSummary(
			{ average: null, count: 0, showCount: true },
			strings,
			'en-US'
		);

		expect( text ).toBe( 'No ratings yet' );
		expect( text ).not.toMatch( /0/ );
	} );

	it( 'does the same when the count is hidden', () => {
		expect(
			buildSummary(
				{ average: null, count: 0, showCount: false },
				strings,
				'en-US'
			)
		).toBe( 'No ratings yet' );
	} );

	it( 'shows average and count', () => {
		expect(
			buildSummary(
				{ average: 4.33, count: 3, showCount: true },
				strings,
				'en-US'
			)
		).toBe( '4.3 out of 5. Ratings: 3' );
	} );

	it( 'leaves out the count when it is hidden', () => {
		expect(
			buildSummary(
				{ average: 4.33, count: 3, showCount: false },
				strings,
				'en-US'
			)
		).toBe( '4.3 out of 5' );
	} );
} );

describe( 'isSessionExpired', () => {
	it( 'is true for any 401', () => {
		expect( isSessionExpired( 401, undefined ) ).toBe( true );
		expect( isSessionExpired( 401, 'anything' ) ).toBe( true );
	} );

	it( 'is true for the nonce check that core runs first', () => {
		expect( isSessionExpired( 403, 'rest_cookie_invalid_nonce' ) ).toBe(
			true
		);
	} );

	it( 'is false for other failures', () => {
		expect(
			isSessionExpired( 403, 'rating_block_wp_post_forbidden' )
		).toBe( false );
		expect( isSessionExpired( 500, 'rating_block_wp_save_failed' ) ).toBe(
			false
		);
		expect( isSessionExpired( undefined, undefined ) ).toBe( false );
	} );
} );
