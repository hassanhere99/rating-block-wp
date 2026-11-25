import { defineConfig } from 'vitest/config';

// Unit tests live under src/. Vitest's default also matches *.spec.* anywhere,
// which would pick up the Playwright specs in ./specs.
export default defineConfig( {
	test: {
		include: [ 'src/**/*.test.{js,jsx}' ],
	},
} );
