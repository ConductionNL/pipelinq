import { defineConfig, devices } from '@playwright/test'
import path from 'path'

const STORAGE_STATE = path.join(__dirname, 'tests/e2e/.auth/user.json')

export default defineConfig({
	testDir: './tests/e2e',
	timeout: 30000,
	expect: { timeout: 10000 },
	fullyParallel: false,
	retries: 1,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: 'tests/e2e/playwright-report' }],
		['junit', { outputFile: 'tests/e2e/test-results/results.xml' }],
	],
	outputDir: 'tests/e2e/test-results',
	globalSetup: './tests/e2e/global-setup.ts',

	use: {
		baseURL: process.env.NEXTCLOUD_URL || 'http://localhost:8080',
		storageState: STORAGE_STATE,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
