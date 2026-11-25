/**
 * Front end store for the rating block, using the Interactivity API.
 *
 * The server renders the block. This script enables the radio inputs and
 * handles a vote: update the stars at once, send the vote, then either show the
 * server's numbers or put the previous score back and say what went wrong.
 *
 * Asynchronous actions are generators that yield promises, as the Interactivity
 * API requires, so the store scope is kept across the awaits.
 */

/**
 * WordPress dependencies
 */
import { getContext, store } from '@wordpress/interactivity';

/**
 * Internal dependencies
 */
import {
	buildSummary,
	format,
	formatNumber,
	isSessionExpired,
} from './summary';

const { state } = store( 'rating-block/rating', {
	state: {
		/**
		 * Whether the star being processed is the viewer's score. Reads the
		 * context of that star: its own `score` and the block's `userScore`.
		 * PHP defines the same derived state for the first render.
		 *
		 * @return {boolean} True for the viewer's score.
		 */
		get isChecked() {
			const { score, userScore } = getContext();

			return score === userScore;
		},
	},
	actions: {
		/**
		 * Vote for the score of the radio that changed.
		 *
		 * @param {Event} event The change event.
		 */
		*vote( event ) {
			const context = getContext();
			const score = Number( event.target.value );
			const previous = context.userScore;

			// Only the latest request may touch the UI, so a slow earlier
			// response cannot overwrite a newer vote.
			context.requestId += 1;
			const requestId = context.requestId;

			// Optimistic update: the stars move before the server answers.
			context.userScore = score;
			// Clear first so repeating the same message is announced again.
			context.message = '';
			context.isError = false;

			try {
				const response = yield fetch(
					`${ state.restUrl }${ context.postId }/vote`,
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'Content-Type': 'application/json',
							'X-WP-Nonce': state.nonce,
						},
						body: JSON.stringify( { score } ),
					}
				);

				if ( ! response.ok ) {
					const failure = new Error( `HTTP ${ response.status }` );
					failure.status = response.status;

					try {
						const body = yield response.json();
						failure.code = body?.code;
					} catch {
						// The body is not JSON. The status is still enough to go on.
					}

					throw failure;
				}

				const data = yield response.json();

				if ( context.requestId !== requestId ) {
					return;
				}

				// The server's numbers replace the local guess.
				context.userScore = data.user_score;
				context.average = data.average;
				context.count = data.count;
				context.summary = buildSummary(
					context,
					state.strings,
					state.locale
				);
				context.message = `${ format(
					state.strings.saved,
					formatNumber( data.user_score, state.locale, 0 )
				) } ${ context.summary }`;
			} catch ( error ) {
				if ( context.requestId !== requestId ) {
					return;
				}

				// Roll back to the score from before this click.
				context.userScore = previous;
				context.isError = true;

				// An expired session or nonce cannot be fixed by voting again,
				// so say what to do instead of the generic failure.
				const reason = isSessionExpired( error?.status, error?.code )
					? state.strings.sessionExpired
					: state.strings.saveFailed;

				context.message = `${ reason } ${
					previous === null || previous === undefined
						? state.strings.noPreviousRating
						: format(
								state.strings.previousRestored,
								formatNumber( previous, state.locale, 0 )
							)
				}`;
			}
		},
	},
	callbacks: {
		/**
		 * Enable the inputs. The server renders them disabled, so without this
		 * script the block is read-only.
		 */
		init() {
			getContext().isDisabled = false;
		},
	},
} );
