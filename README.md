# Rating Block

[![CI](https://github.com/hassanhere99/rating-block-wp/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/hassanhere99/rating-block-wp/actions/workflows/ci.yml)

## What it does

A dynamic block that shows a 1 to 5 star rating for the current post, with an optional label. Logged-in users can vote, and voting again changes their vote instead of adding a second one. Logged-out visitors see the average and the number of votes, read only, with a link to log in.

![A voted block: four solid stars, the average and count, and the announcement after voting.](docs/rating-voted.png)

![The same block with JavaScript off: the viewer's vote and the average are shown, the stars are not operable.](docs/rating-no-js.png)

## Requirements

- WordPress 6.6 or later. Tested on 6.6.0 and on the latest release.
- PHP 8.1 or later. Tested on 8.1 and 8.3.
- MySQL or MariaDB, as WordPress requires. Votes are stored in a custom table.

No external services and no Composer runtime dependencies.

## Install

The repository does not contain `build/`, so the block has to be built before it is activated.

```sh
cd wp-content/plugins
git clone https://github.com/hassanhere99/rating-block-wp.git rating-block-wp
cd rating-block-wp
npm ci
npm run build
```

Activate the plugin from the Plugins screen. Activation creates the `{prefix}rating_block_votes` table. Then add the **Rating** block to a post, a template, or a Query Loop item.

Deactivating the plugin keeps the votes. Deleting it from the Plugins screen drops the table and the plugin's options (see `uninstall.php`).

There is no release zip. Install from source as above.

## Local development

Needs Node 22 (`@wordpress/scripts` 37 declares `^22.22.2` or newer), Docker, and PHP with Composer on the host for the PHP tooling.

```sh
npm ci
composer install
npm run build          # or: npm start, to rebuild on change
npm run env:start      # wp-env: http://localhost:8888, admin / password
```

`wp-env` maps the repository to `wp-content/plugins/rating-block-wp` and activates it, whatever the checkout directory is called. The test site is a separate environment on port 8889 with its own config (`.wp-env.test.json`). Its database is reinstalled by the WordPress test suite, so do not use it for development.

Coding standards:

```sh
composer lint          # PHPCS: the WordPress ruleset and PHPCompatibilityWP for PHP 8.1+
npm run lint:js
npm run lint:css
```

## Running tests

**PHPUnit needs a build first.** The block is registered from `build/`, so run `npm run build` before the PHP tests. The render tests fail with a message saying so if it is missing.

### PHP

```sh
npm run build
composer install
npm run env:test:start
npm run test:php               # single site
npm run test:php:multisite     # as a network
```

PHPUnit 9.6 runs inside the wp-env container with the WordPress test suite. The single-site run reports four skipped tests; they are the multisite-only tests, which the multisite run executes. To test another WordPress or PHP version, set `WP_ENV_CORE` (for example `WordPress/WordPress#6.6`) and `WP_ENV_PHP_VERSION` before starting the environment. Destroy and recreate the environment when changing the WordPress version: a database created by a newer WordPress cannot log in on an older one.

### JavaScript

```sh
npm run test:js
```

Vitest, for the Interactivity store: optimistic update, request, success, rollback, announcements, session expiry, and stale responses.

### End to end

```sh
npm run build
npm run env:start
npx playwright install chromium
npm run test:e2e
```

One Playwright test, using `@wordpress/e2e-test-utils-playwright`: a logged-in user inserts the block, publishes, votes on the front end, sees the count update, reloads and sees the vote persisted, then changes the vote. `npm run test:e2e` needs a project `playwright.config.js` and skips a download of every browser, for reasons written up in [NOTES-upstream.md](NOTES-upstream.md).

### CI

`.github/workflows/ci.yml` runs on every push: PHPCS; JS lint, Vitest and build; PHPUnit on WordPress 6.6.0 and latest, each on PHP 8.1 and 8.3; one multisite PHPUnit run (latest, PHP 8.3); and the e2e test on WordPress 6.6.0 and latest. npm, Composer and Playwright browser caches are used. Every job that needs the block runs `npm ci` and `npm run build` first.

## REST API

Namespace `rating-block/v1`. Authentication is WordPress cookie authentication: send the `wp_rest` nonce in the `X-WP-Nonce` header. `OPTIONS` on either route returns the response schema.

### `POST /posts/{id}/vote`

Records the current user's vote on a post, or changes it.

| Arg | Type | Rules |
|---|---|---|
| `id` (in the path) | integer | required, minimum 1 |
| `score` | integer | required, 1 to 5 |

Args are validated by the route's args schema, which WordPress does before `permission_callback`. A bad score is therefore a 400 even for a logged-out caller.

Permission: the user is logged in, has the `read` capability on the site, and the post exists, is published, is of a viewable post type, and is not password protected. Then the `rating_block_wp_post_is_rateable` filter (below) has the last word.

### `GET /posts/{id}/summary`

Returns the summary for a post. Public. Unlike voting, it also accepts an unpublished post that the current user can read, such as their own private post.

### Response

Both routes return the same shape:

```json
{ "average": 4.33, "count": 3, "user_score": 4 }
```

| Field | Type | Notes |
|---|---|---|
| `average` | number or `null` | Rounded to two decimals. `null` when `count` is 0. |
| `count` | integer | Number of votes. |
| `user_score` | integer or `null` | The current user's score. `null` when logged out or not voted. |

### Status codes

| Status | Code | When |
|---|---|---|
| 200 | | Success. A vote returns the new summary. |
| 400 | `rest_invalid_param`, `rest_missing_callback_param` | `score` missing, not an integer, or outside 1 to 5. |
| 401 | `rating_block_wp_not_logged_in` | Voting while logged out, or with a session cookie but no nonce. |
| 403 | `rest_cookie_invalid_nonce` | A nonce was sent and is wrong. Raised by core before this plugin runs. |
| 403 | `rating_block_wp_cannot_read` | Logged in without the `read` capability on this site, for example a network user who is not a member of the site on multisite. |
| 403 | `rating_block_wp_post_forbidden` | The post is not published, is not a viewable type, or is password protected. |
| 403 | `rating_block_wp_post_not_rateable` | The `rating_block_wp_post_is_rateable` filter returned false. |
| 404 | `rating_block_wp_post_not_found` | No such post. |
| 500 | `rating_block_wp_save_failed` | The database write failed. |

### Filter: `rating_block_wp_post_is_rateable`

```php
apply_filters( 'rating_block_wp_post_is_rateable', bool $is_rateable, WP_Post $post );
```

Default `true`. It runs after the built-in checks, so it can only narrow them: returning `true` does not allow a draft. It applies to voting only; summaries stay readable. The block uses the same check, so it does not offer inputs for a post the filter refuses.

```php
add_filter(
	'rating_block_wp_post_is_rateable',
	static fn( $is_rateable, $post ) => 'recipe' === $post->post_type ? $is_rateable : false,
	10,
	2
);
```

## Design decisions

### Votes are in a custom table, not post meta

`{prefix}rating_block_votes` has `id`, `post_id`, `user_id`, `score` (tinyint), `created_at`, `updated_at`, and `UNIQUE KEY (post_id, user_id)`.

The unique key is the reason for the table. "One vote per user per post" is enforced by the database, so two concurrent requests from the same user cannot both insert. With post meta there is no such constraint: it would need a meta key per user, or a serialised array in one row that every voter rewrites. Aggregating either one means scanning meta rows or unserialising a blob, and a popular post puts one row per voter into `postmeta`, which is shared with everything else on the site. The table is narrow and the aggregate is an indexed `COUNT` and `AVG`.

The cost is that a custom table is the plugin's responsibility: creating it, upgrading it, removing it, and cleaning it when posts and users go away.

The table is created with `dbDelta()` on activation and the schema version is stored in `rating_block_wp_schema_version`. A `plugins_loaded` check compares it with the code, because activation hooks do not run on plugin updates or for each site of a network-activated plugin. Bump `Schema::SCHEMA_VERSION` and change the `CREATE TABLE` to migrate. The 1 to 5 range is enforced in PHP, not with a `CHECK`, which MySQL before 8.0.16 ignores and `dbDelta()` does not manage. Timestamps have no column default and are written from PHP in UTC.

### A vote is a single upsert

`INSERT ... ON DUPLICATE KEY UPDATE`, through `$wpdb->prepare()`. The new score is passed as a second parameter rather than with `VALUES(score)`, which is deprecated in MySQL 8.0.20 and has no equivalent syntax in MariaDB.

### The summary is computed, not stored

Count and average could be kept in post meta and adjusted on every vote. They are computed from the table instead. A stored aggregate is a second copy of the data that has to be updated in step with the first, and two concurrent votes can leave it wrong (next section). The computation is an indexed `COUNT` and `AVG` over the rows of one post, which the cache below keeps off most requests.

### Invalidate on write, not write-through

A vote deletes the cached summary for the post and the next read recomputes it. A vote never writes the new aggregate into the cache.

With write-through, two concurrent votes each compute an aggregate from the rows they can see and write it. The one that finishes last overwrites the other, and the cache can hold a value that neither the table nor either request would produce. Deleting is idempotent: it does not matter which delete runs last.

A narrower race remains. A reader can compute the aggregate, a vote can commit and delete the key, and the reader can then store its older result. `Summary::CACHE_TTL` (5 minutes) bounds how long that stale value can live.

### The vote response is computed fresh

The response to a vote reads the aggregate from the table and does not read or write the cache. The vote has just changed the data, and putting that result into the cache would be the write-through described above. The response costs one aggregate query per vote, which is the write path and not the hot path.

### No transient fallback

The cache uses `wp_cache_*` only. With a persistent object cache the value is shared between requests. Without one it lasts for the request, which still saves repeat queries when a page has several rating blocks for the same post.

A transient is two rows in `wp_options`. Without an object cache a hit costs two option reads and a miss adds writes, which is more than the indexed `COUNT` and `AVG` it would replace. So there is no fallback, and uninstall has no transients to delete.

### Alternative considered: a `last_changed` salt

Core caches some queries under a key that includes a `last_changed` value (`wp_cache_get_last_changed()`), and bumps that value when the data changes. Here that would mean a per-post salt in the cache key, bumped by every vote. A reader reads the salt before computing, so a result stored under an old salt is never read again. That closes the read-side race, where the TTL only bounds it.

It was not adopted. It adds a cache read to every summary read. Without a persistent object cache the cache lasts one request and the race cannot happen. With one, the window is one aggregate query and the harm is a stale average for at most five minutes. If the TTL is ever lengthened, the salt is the thing to add.

### No `user_id` index

The unique key leads with `post_id`, so it serves every lookup by post. Nothing queries by `user_id` alone except deleting a user's votes when the user is deleted, which scans the table. That is rare, and an index on `user_id` would add write cost to every vote, which is the hot path. A separate `KEY post_id` would be redundant with the unique key and was dropped.

### Who may vote, and what anonymous voting would need

Voting needs a logged-in user, because that gives a stable identity for "one vote per user" and an authenticated request for the nonce. The user also needs the `read` capability on the site. On a single site every role has it. On multisite a user can be logged in to the network without being a member of the site, and has no role, so no `read`; this check refuses them.

Anonymous voting would need a different design, not a relaxed check:

- An identity for "one per visitor": a cookie is cleared by anyone who wants to vote twice, and an IP address is shared by whole offices and changes for mobile users. Neither gives the guarantee the unique key gives for users.
- Rate limiting per IP and per post, a store for the counters, and a way to expire them.
- Abuse protection against scripted voting, such as a challenge, and a way to discard votes in bulk.
- A privacy position on storing IP addresses or fingerprints.
- A page-caching answer: logged-out pages are cacheable, and a nonce or a visitor key in them is not.

None of this is built.

### Voting rules are shared by the endpoint and the block

`Access::check_vote()` is called by the REST permission callback and by `render.php`, so the block never offers inputs for a vote the endpoint would refuse. A test renders the block and calls the summary endpoint for every post state (published, draft, pending, private, trash, future, password protected, attachment) and viewer (logged out, subscriber, administrator), and asserts that the block renders if and only if the endpoint answers 200.

### 403 for unavailable posts matches core

A draft, private, pending, future or trashed post, a password-protected post, and a post type with no public view all answer 403 on vote. The core posts endpoint answers 403 for a post the caller cannot read, and this follows it. 404 is for a post that does not exist. The cost is that a 403 tells the caller the post exists. Returning 404 for both would hide that, and would differ from core.

### Interactivity API, not a React view script

The front end is the Interactivity API (`viewScriptModule`, the `@wordpress/interactivity` store), with no React.

- The server renders the whole block, with the state in `data-wp-context` and the interactivity state. The script hydrates markup that is already correct and readable. A React view script would render the stars on the client, so without it, or before it loads, there would be nothing to read.
- No React runtime or bundled component tree on every page that shows a rating.
- It is the mechanism WordPress ships for this, and server and client share directives.

The costs are real. Expressions in directives are limited to references and `!`, so "is this the user's star" needs derived state, defined in PHP for the first render and in JS afterwards (WordPress 6.6 and later). Asynchronous actions are generators that yield, not `async` functions. And `@wordpress/i18n` is not available as a script module on WordPress 6.6.

### The count is not pluralised ("Ratings: N")

The summary reads "4.3 out of 5. Ratings: 12", not "12 ratings". The view script rebuilds this line after a vote and cannot call `_n()`, and `@wordpress/i18n` is not available to a script module on 6.6, so the wording has to work in PHP and JS without plural forms. PHP passes the translated templates to the script in the interactivity state, so both build the line from the same strings. The alternative is to ship every plural form to the client, which was heavier than the problem.

### The post comes from block context

`block.json` declares `usesContext: ["postId"]` and `render.php` reads `$block->context['postId']`. It never reads `get_the_ID()` or the global `$post`. In a Query Loop each item has its own `postId`, and a block that read the global post would show the post the loop is running on instead. Tests render the block for two posts while a third is the global post, and through a real `core/query` block.

### What the store does when a vote fails

The stars move at once. On success the server's numbers replace the local ones, and the result is announced in an `aria-live="polite"` region. The summary is not guessed locally, because an average needs the sum of scores, which the API does not return.

On failure the previous score is restored and the error is announced in the same region. An expired session or nonce (any 401, or core's `rest_cookie_invalid_nonce`) says to reload the page; any other failure says the rating could not be saved. A counter lets only the latest request update the page, so a slow earlier failure cannot roll back a newer vote. The message is cleared before each vote so a repeated failure is announced again.

### Escaping

Output is escaped late, at the point of output: `esc_html()` for the label, `esc_attr()` and `esc_url()` for attributes and links. `get_block_wrapper_attributes()` is echoed as it is, because core runs `esc_attr()` on every value it returns. `wp_interactivity_data_wp_context()` is echoed as it is, because it hex-encodes `<`, `>`, `&` and both quote characters in its JSON. Each has a comment in `render.php`. Tests pass hostile labels and a hostile `className` to the block.

### Accessibility

When the viewer can vote, the block is a `fieldset` with a `legend`, five radios that share a name, and a label per radio that names its score. When they cannot, there are no inputs: a title, the average as a row of stars hidden from assistive technology, and the summary text. The radios are visually hidden but focusable, and the star shows a visible focus ring. Filled and empty stars are different glyphs (solid and outline), not different colours, and both take `currentColor`. The fill of the stars before the chosen one uses CSS `:has()`.

### Cleanup

`deleted_user` deletes that user's votes and invalidates the summary of each post they voted on. `before_delete_post` deletes the post's votes and its cached summary, and skips `revision` and `nav_menu_item`, which are created and pruned in bulk and cannot have votes. Trashing a post keeps its votes, so restoring it restores its rating.

### Testing choices

Store tests use Vitest, because `@wordpress/scripts` 37 moved `test-unit-js` from Jest to Vitest. Several tests were checked by breaking the behaviour they cover and confirming that they fail.

## Known limitations

- **Logged-in voting only.** There are no anonymous votes. See the section above for what they would need.
- **No rate limit beyond the unique key.** A logged-in user can change their vote as often as they like. Each change is one upsert.
- **An aggregate query on a cache miss.** Without a persistent object cache the cache lasts one request, so every page view runs one `COUNT` and `AVG` for each rated post on the page. It is indexed and narrow, and not free.
- **Network-wide user deletion cleans only the current site.** The votes table is per site and `deleted_user` fires once, so removing a user from the whole network deletes their votes on the site where the hook ran. Votes on other sites remain, and still count in those posts' averages.
- **Deleting a user scans the votes table.** There is no `user_id` index, on purpose.
- **`get_sites()` on uninstall for very large networks.** On multisite, `uninstall.php` loads every site ID and switches to each in turn to drop its table. On a very large network that is one long request, and it can run out of time or memory.
- **The block renders nothing outside a post context.** With no `postId` in block context, or for a post that does not exist or that the summary endpoint would refuse (an unreadable draft, a locked password-protected post), it outputs nothing.
- **Push-only CI, so fork PRs get no CI.** The workflow has no `pull_request` trigger, so that a branch is checked before a PR exists and branches in this repository are not run twice. A pull request from a fork runs nothing.
- **A 403 tells the caller a post exists** when it is a draft or private. This follows core.
- **Logged-in pages must not be full-page cached.** The block's HTML for a logged-in user contains their score and a REST nonce. Logged-out output is the same for every visitor and can be cached.
- **The count is not pluralised.** See above.
- **The star fill needs `:has()`.** In a browser without it (before 2023) the chosen star still shows, and the stars before it do not fill.
- **No release zip.** The plugin is installed from source with a build step.

## How this was built

The specification, the review of each phase and the design corrections are mine. The code was written with Claude Code in five reviewed phases: scaffold and schema, REST and cache, block, CI and end-to-end tests, and this README. Phases 2 to 5 were each merged through a pull request after my review. Phase 1 went to `main` directly, before that process began.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
