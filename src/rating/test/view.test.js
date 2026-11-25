/**
 * Tests for the Interactivity store's vote action.
 *
 * The Interactivity API is mocked. The action is a generator that yields
 * promises; the runtime resumes it with each result, or throws into it on a
 * rejection. run() does the same, so the tests exercise the real action code.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';

// vi.mock is hoisted above imports, so what its factory touches is hoisted too.
const mocks = vi.hoisted( () => ( { context: null, definition: null } ) );

vi.mock( '@wordpress/interactivity', () => ( {
	getContext: () => mocks.context,
	store: vi.fn( ( namespace, def ) => {
		mocks.definition = def;
		return { state: def.state };
	} ),
} ) );

const STRINGS = {
	noRatings: 'No ratings yet',
	averageOnly: '%s out of 5',
	averageWithCount: '%1$s out of 5. Ratings: %2$s',
	saved: 'You rated this %s out of 5.',
	saveFailed: 'Your rating could not be saved.',
	previousRestored: 'Your previous rating of %s out of 5 is unchanged.',
	noPreviousRating: 'No rating was recorded.',
};

/**
 * Drive a generator action the way the Interactivity runtime does.
 *
 * @param {Object} generator The action's generator.
 * @return {Promise<unknown>} The action's return value.
 */
async function run( generator ) {
	let step = generator.next();

	while ( ! step.done ) {
		try {
			step = generator.next( await step.value );
		} catch ( error ) {
			step = generator.throw( error );
		}
	}

	return step.value;
}

const jsonResponse = ( body, ok = true, status = 200 ) =>
	Promise.resolve( { ok, status, json: () => Promise.resolve( body ) } );

const change = ( score ) => ( { target: { value: String( score ) } } );

beforeEach( async () => {
	// Load the store fresh so each test starts from its definition.
	vi.resetModules();
	await import( '../view' );

	Object.assign( mocks.definition.state, {
		restUrl: 'https://example.test/wp-json/rating-block/v1/posts/',
		nonce: 'abc123',
		locale: 'en-US',
		strings: STRINGS,
	} );

	mocks.context = {
		postId: 7,
		userScore: 2,
		average: 3,
		count: 4,
		showCount: true,
		summary: '3.0 out of 5. Ratings: 4',
		message: '',
		isError: false,
		isDisabled: true,
		requestId: 0,
	};

	globalThis.fetch = vi.fn();
} );

describe( 'vote action', () => {
	it( 'updates the stars before the server answers', () => {
		globalThis.fetch.mockReturnValue( new Promise( () => {} ) ); // Never settles.

		const generator = mocks.definition.actions.vote( change( 5 ) );
		generator.next(); // Runs up to the first yield, the fetch.

		expect( mocks.context.userScore ).toBe( 5 );
		expect( mocks.context.message ).toBe( '' );
		expect( mocks.context.isError ).toBe( false );
	} );

	it( 'posts the score with the REST nonce', async () => {
		globalThis.fetch.mockReturnValue(
			jsonResponse( { average: 4, count: 4, user_score: 5 } )
		);

		await run( mocks.definition.actions.vote( change( 5 ) ) );

		expect( globalThis.fetch ).toHaveBeenCalledTimes( 1 );
		const [ url, options ] = globalThis.fetch.mock.calls[ 0 ];
		expect( url ).toBe(
			'https://example.test/wp-json/rating-block/v1/posts/7/vote'
		);
		expect( options.method ).toBe( 'POST' );
		expect( options.headers[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
		expect( JSON.parse( options.body ) ).toEqual( { score: 5 } );
	} );

	it( 'shows the numbers the server returned and announces the result', async () => {
		globalThis.fetch.mockReturnValue(
			jsonResponse( { average: 4.25, count: 4, user_score: 5 } )
		);

		await run( mocks.definition.actions.vote( change( 5 ) ) );

		expect( mocks.context.userScore ).toBe( 5 );
		expect( mocks.context.average ).toBe( 4.25 );
		expect( mocks.context.count ).toBe( 4 );
		expect( mocks.context.summary ).toBe( '4.3 out of 5. Ratings: 4' );
		expect( mocks.context.isError ).toBe( false );
		expect( mocks.context.message ).toBe(
			'You rated this 5 out of 5. 4.3 out of 5. Ratings: 4'
		);
	} );

	it( 'takes the first ever vote from no rating and no average', async () => {
		mocks.context.userScore = null;
		mocks.context.average = null;
		mocks.context.count = 0;
		mocks.context.summary = 'No ratings yet';
		globalThis.fetch.mockReturnValue(
			jsonResponse( { average: 3, count: 1, user_score: 3 } )
		);

		await run( mocks.definition.actions.vote( change( 3 ) ) );

		expect( mocks.context.summary ).toBe( '3.0 out of 5. Ratings: 1' );
	} );

	describe( 'when the request fails', () => {
		const failures = {
			'an error status': () => jsonResponse( {}, false, 500 ),
			'a network error': () => Promise.reject( new Error( 'offline' ) ),
			'a response that is not JSON': () =>
				Promise.resolve( {
					ok: true,
					status: 200,
					json: () => Promise.reject( new SyntaxError( 'bad json' ) ),
				} ),
		};

		it.each( Object.keys( failures ) )(
			'rolls back to the previous score after %s',
			async ( name ) => {
				globalThis.fetch.mockReturnValue( failures[ name ]() );

				await run( mocks.definition.actions.vote( change( 5 ) ) );

				expect( mocks.context.userScore ).toBe( 2 );
				// The shared numbers were never changed.
				expect( mocks.context.average ).toBe( 3 );
				expect( mocks.context.count ).toBe( 4 );
				expect( mocks.context.summary ).toBe(
					'3.0 out of 5. Ratings: 4'
				);
			}
		);

		it( 'announces the error and the restored score', async () => {
			globalThis.fetch.mockReturnValue( jsonResponse( {}, false, 500 ) );

			await run( mocks.definition.actions.vote( change( 5 ) ) );

			expect( mocks.context.isError ).toBe( true );
			expect( mocks.context.message ).toBe(
				'Your rating could not be saved. Your previous rating of 2 out of 5 is unchanged.'
			);
		} );

		it( 'clears the selection when there was no previous rating', async () => {
			mocks.context.userScore = null;
			globalThis.fetch.mockReturnValue( jsonResponse( {}, false, 403 ) );

			await run( mocks.definition.actions.vote( change( 4 ) ) );

			expect( mocks.context.userScore ).toBeNull();
			expect( mocks.context.isError ).toBe( true );
			expect( mocks.context.message ).toBe(
				'Your rating could not be saved. No rating was recorded.'
			);
		} );

		it( 'announces a repeated failure again by clearing the message first', () => {
			mocks.context.message = 'Your rating could not be saved. x';
			mocks.context.isError = true;
			globalThis.fetch.mockReturnValue( new Promise( () => {} ) );

			mocks.definition.actions.vote( change( 4 ) ).next();

			expect( mocks.context.message ).toBe( '' );
			expect( mocks.context.isError ).toBe( false );
		} );
	} );

	it( 'lets only the latest request update the page', async () => {
		let resolveFirst;
		globalThis.fetch
			.mockReturnValueOnce(
				new Promise( ( resolve ) => {
					resolveFirst = resolve;
				} )
			)
			.mockReturnValueOnce(
				jsonResponse( { average: 4, count: 4, user_score: 4 } )
			);

		const first = run( mocks.definition.actions.vote( change( 5 ) ) );
		await run( mocks.definition.actions.vote( change( 4 ) ) );

		expect( mocks.context.userScore ).toBe( 4 );

		// The slow first request now fails. It must not roll back the newer vote.
		resolveFirst( {
			ok: false,
			status: 500,
			json: () => Promise.resolve( {} ),
		} );
		await first;

		expect( mocks.context.userScore ).toBe( 4 );
		expect( mocks.context.isError ).toBe( false );
	} );
} );

describe( 'derived state isChecked', () => {
	it( 'is true for the star that matches the user score', () => {
		mocks.context = { score: 4, userScore: 4 };
		expect( mocks.definition.state.isChecked ).toBe( true );

		mocks.context = { score: 3, userScore: 4 };
		expect( mocks.definition.state.isChecked ).toBe( false );
	} );

	it( 'is false for every star when there is no vote', () => {
		mocks.context = { score: 1, userScore: null };
		expect( mocks.definition.state.isChecked ).toBe( false );
	} );
} );

describe( 'init callback', () => {
	it( 'enables the inputs', () => {
		expect( mocks.context.isDisabled ).toBe( true );

		mocks.definition.callbacks.init();

		expect( mocks.context.isDisabled ).toBe( false );
	} );
} );
