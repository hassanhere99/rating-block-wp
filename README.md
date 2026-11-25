# Rating Block

[![CI](https://github.com/hassanhere99/rating-block-wp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/hassanhere99/rating-block-wp/actions/workflows/ci.yml)

## What it does

A dynamic block that shows a 1 to 5 star rating for the current post, with an optional label. Logged-in users can vote, and voting again changes their vote instead of adding a second one. Logged-out visitors see the average and the number of votes, read only, with a link to log in.

![A voted block: four solid stars, the average and count, and the announcement after voting.](docs/rating-voted.png)

![The same block with JavaScript off: the viewer's vote and the average are shown, the stars are not operable.](docs/rating-no-js.png)

## Requirements

- WordPress 6.6 or later. Tested on 6.6.0 and the latest release.
- PHP 8.1 or later. Tested on 8.1 and 8.3.
- No external services and no Composer runtime dependencies.

## Install

`build/` is not committed, so build the block first. There is no release zip.

```sh
cd wp-content/plugins
git clone https://github.com/hassanhere99/rating-block-wp.git rating-block-wp
cd rating-block-wp && npm ci && npm run build
```

Activation creates the `{prefix}rating_block_votes` table. Deleting the plugin drops it.

## Local development

Needs Node 22, Docker, PHP and Composer.

```sh
npm ci && composer install && npm run build
npm run env:start      # wp-env, http://localhost:8888, admin / password
composer lint          # PHPCS
npm run lint:js && npm run lint:css
```

The test environment (`.wp-env.test.json`, port 8889) is separate, because the test suite reinstalls its database.

## Running tests

**PHPUnit needs a build first**: the block is registered from `build/`.

```sh
npm run build && composer install && npm run env:test:start
npm run test:php              # single site
npm run test:php:multisite    # as a network
npm run test:js               # Vitest, the Interactivity store
npm run env:start && npx playwright install chromium && npm run test:e2e
```

The single-site run skips four network-only tests. Set `WP_ENV_CORE` or `WP_ENV_PHP_VERSION` to test other versions, and recreate the environment when changing WordPress. CI (`.github/workflows/ci.yml`, every push) runs PHPCS, JS lint, Vitest, PHPUnit on WordPress 6.6.0 and latest across PHP 8.1 and 8.3, one multisite run, and the e2e test. The e2e setup works around two `@wordpress/scripts` problems, written up in [NOTES-upstream.md](NOTES-upstream.md).

## REST API

Namespace `rating-block/v1`, cookie authentication with the `wp_rest` nonce in `X-WP-Nonce`. `OPTIONS` returns the response schema.

- `POST /posts/{id}/vote` with `score`, an integer from 1 to 5. The user must be logged in with `read` on the site, and the post published, viewable and not password protected.
- `GET /posts/{id}/summary`. Public. Also accepts an unpublished post the current user can read.

Both return `{ "average": 4.33, "count": 3, "user_score": 4 }`. `average` is rounded to two decimals and is `null` when `count` is 0. `user_score` is `null` when logged out or not voted.

| Status | Code | When |
|---|---|---|
| 400 | `rest_invalid_param`, `rest_missing_callback_param` | Bad or missing `score`, checked before permissions. |
| 401 | `rating_block_wp_not_logged_in` | Voting while logged out. |
| 401 / 403 | `rating_block_wp_post_forbidden` | Post not available: 401 logged out, 403 logged in. |
| 403 | `rest_cookie_invalid_nonce` | Wrong nonce, from core. |
| 403 | `rating_block_wp_cannot_read` | No `read` capability on this site. |
| 403 | `rating_block_wp_post_not_rateable` | The filter refused. |
| 404 | `rating_block_wp_post_not_found` | No such post. |
| 500 | `rating_block_wp_save_failed` | The write failed. |

Filter `rating_block_wp_post_is_rateable( bool $is_rateable, WP_Post $post )`, default `true`. It runs after the built-in checks, so it can only narrow them. Voting only; the block honours it.

## Design decisions

Reasoning and trade-offs are in [docs/DESIGN.md](docs/DESIGN.md).

- [Votes are in a custom table, not post meta](docs/DESIGN.md#votes-are-in-a-custom-table-not-post-meta): a unique key enforces one vote per user.
- [A vote is a single upsert](docs/DESIGN.md#a-vote-is-a-single-upsert): one `INSERT ... ON DUPLICATE KEY UPDATE`.
- [The summary is computed, not stored](docs/DESIGN.md#the-summary-is-computed-not-stored): a stored aggregate can drift.
- [Invalidate on write, not write-through](docs/DESIGN.md#invalidate-on-write-not-write-through): a vote deletes the cache entry.
- [The vote response is computed fresh](docs/DESIGN.md#the-vote-response-is-computed-fresh): it never writes the cache.
- [No transient fallback](docs/DESIGN.md#no-transient-fallback): it costs more than the query.
- [Alternative considered: a `last_changed` salt](docs/DESIGN.md#alternative-considered-a-last_changed-salt): closes a race, costs a cache read.
- [No `user_id` index](docs/DESIGN.md#no-user_id-index): it would slow every vote.
- [Who may vote, and what anonymous voting would need](docs/DESIGN.md#who-may-vote-and-what-anonymous-voting-would-need): logged in, with `read`.
- [Voting rules are shared by the endpoint and the block](docs/DESIGN.md#voting-rules-are-shared-by-the-endpoint-and-the-block): the page never offers a refused vote.
- [Unavailable posts answer with core's status: 401 or 403](docs/DESIGN.md#unavailable-posts-answer-with-cores-status-401-or-403): `rest_authorization_required_code()`.
- [Interactivity API, not a React view script](docs/DESIGN.md#interactivity-api-not-a-react-view-script): server-rendered, hydrated, no React.
- [The count is not pluralised ("Ratings: N")](docs/DESIGN.md#the-count-is-not-pluralised-ratings-n): the script cannot call `_n()`.
- [The post comes from block context](docs/DESIGN.md#the-post-comes-from-block-context): correct inside a Query Loop.
- [What the store does when a vote fails](docs/DESIGN.md#what-the-store-does-when-a-vote-fails): rollback, announcement, latest request wins.
- [Escaping](docs/DESIGN.md#escaping): late, with each exception explained.
- [Accessibility](docs/DESIGN.md#accessibility): fieldset, radios, glyph stars.
- [Cleanup](docs/DESIGN.md#cleanup): user and post deletion remove votes.
- [Testing choices](docs/DESIGN.md#testing-choices): Vitest; mutation-checked tests.

## Known limitations

- Logged-in voting only. [Anonymous voting](docs/DESIGN.md#who-may-vote-and-what-anonymous-voting-would-need) needs a different design.
- No rate limit beyond the unique key.
- An aggregate query on a cache miss: without a persistent object cache, one per rated post per request.
- Deleting a user from the whole network cleans only the current site.
- `uninstall.php` loads every site with `get_sites()`, one long request on a very large network.
- The block renders nothing outside a post context, or for a post the summary endpoint refuses.
- CI is push-only, so a pull request from a fork gets no CI.
- Logged-in pages must not be full-page cached: they carry the user's score and a nonce.
- The fill of the stars before the chosen one needs CSS `:has()`.

## How this was built

The specification, the review of each phase and the design corrections are mine. The code was written with Claude Code in five reviewed phases: scaffold and schema, REST and cache, block, CI and end-to-end tests, and this README. Phases 2 to 5 were each merged through a pull request after my review. Phase 1 went to `main` directly, before that process began.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
