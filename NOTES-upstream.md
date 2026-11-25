# Notes for upstream

Problems found in WordPress tooling while building this plugin, with a
workaround for each. None is hidden in the code: each workaround is commented
where it is used and points here.

## 1. `wp-scripts test-e2e` does not find tests in `./specs`

**Versions**

- `@wordpress/scripts` 37.2.0
- `@playwright/test` 1.65.1
- `@wordpress/e2e-test-utils-playwright` 3.3.0
- Node 22.19.0, npm 10.9.3, macOS 15

**Expected**

The README for `test-e2e` says Playwright "looks for JavaScript or TypeScript
files with `.test` or `.spec` suffix in the project root-level `/specs`
folder". A project with `specs/example.spec.js` and no Playwright config should
run it.

**Actual**

```
$ npx wp-scripts test-e2e
Error: No tests found
```

**Minimal reproduction**

1. `npm init -y && npm i -D @wordpress/scripts@37.2.0 @playwright/test@1.65.1 @wordpress/e2e-test-utils-playwright@3.3.0`
2. Create `specs/example.spec.js` with one test, for example
   `import { test, expect } from '@playwright/test'; test('x', () => expect(1).toBe(1));`
3. Make sure there is no `playwright.config.js` or `.ts` in the project root.
4. `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npx wp-scripts test-e2e --list`

Result: `Total: 0 tests in 0 files`.

**Cause**

With no project config, `scripts/test-playwright.js` passes
`--config node_modules/@wordpress/scripts/config/playwright.config.js`. That
file sets `testDir: './specs'`. Playwright resolves `testDir` against the
directory of the config file, so it looks in
`node_modules/@wordpress/scripts/config/specs`, which does not exist. The
default config only works if the project supplies its own config.

**Workaround**

`playwright.config.js` in the project root spreads the default config and sets
an absolute `testDir`:

```js
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = { ...baseConfig, testDir: path.join( __dirname, 'specs' ) };
```

**Related report, not the same bug**

[WordPress/gutenberg#55419](https://github.com/WordPress/gutenberg/issues/55419)
("Scripts: Default Playwright config file doesn't work", closed) is the same
family of problem but a different cause. It was reported against
`@wordpress/scripts` 26.14.0, where the config path used the wrong file
extension, so the default config "does not exist". It was fixed in
[#55453](https://github.com/WordPress/gutenberg/pull/55453). Here the config
loads and runs; it is `testDir` that is wrong. I found no open issue for this
symptom, and `packages/scripts/config/playwright.config.js` on `trunk` still
sets `testDir: './specs'`.

**Possible fix**

Have the default config compute `testDir` from the project:
`path.join( process.cwd(), 'specs' )`, or pass the directory on the command line
when the default config is used.

## 2. `wp-scripts test-e2e` downloads every Playwright browser

Not a bug, but it surprised me and is not mentioned in the README.

`scripts/test-playwright.js` runs `playwright install` with no arguments before
every test run unless `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD` is set. That fetches
Chromium, Firefox and WebKit (about 300 MB) even though the default config only
defines a Chromium project.

**Workaround**

`npm run test:e2e` sets `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1`. Chromium is
installed once, explicitly (`npx playwright install --with-deps chromium` in
CI), and cached.

**Possible fix**

Install only the browsers used by the projects in the config, or document the
variable in the `test-e2e` section.
