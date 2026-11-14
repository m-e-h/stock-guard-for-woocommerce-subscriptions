import { defineConfig, devices } from '@playwright/test';

const port = process.env.SGFWS_E2E_PORT || '9400';
const baseURL = `http://127.0.0.1:${ port }`;

export default defineConfig( {
	testDir: './specs',
	outputDir: '../../.cache/e2e-results',
	// Every spec shares one Playground site; keep them serial.
	workers: 1,
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	timeout: 120_000,
	expect: { timeout: 15_000 },
	reporter: process.env.CI ? [ [ 'list' ], [ 'html', { open: 'never', outputFolder: '../../.cache/e2e-report' } ] ] : 'list',
	use: {
		baseURL,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		navigationTimeout: 60_000,
		actionTimeout: 20_000,
	},
	projects: [
		{
			name: 'chromium',
			use: { ...devices[ 'Desktop Chrome' ] },
		},
	],
	webServer: {
		command: 'node tests/e2e/bin/start-playground.mjs',
		cwd: '../..',
		// Returns 503 until the blueprint has activated WooCommerce, Subscriptions, and Stock Guard.
		url: `${ baseURL }/?rest_route=/sgfws-e2e/v1/ready`,
		reuseExistingServer: ! process.env.CI,
		timeout: 600_000,
		stdout: 'pipe',
		stderr: 'pipe',
	},
} );
