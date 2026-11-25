/**
 * Playwright config.
 *
 * Extends the `@wordpress/scripts` default so the tests in ./specs are found.
 * The default sets testDir to './specs', which Playwright resolves against the
 * config file inside node_modules, so it finds nothing. See NOTES-upstream.md.
 */
const path = require( 'path' );
const baseConfig = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...baseConfig,
	testDir: path.join( __dirname, 'specs' ),
};
