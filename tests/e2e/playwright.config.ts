/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CI regression config for the shared `E2E Tests (Playwright)` job.
 *
 * WHY A SECOND CONFIG EXISTS
 * --------------------------
 * The shared workflow (ConductionNL/.github/.github/workflows/quality.yml)
 * runs the suite as:
 *
 *     CONFIG="${{ inputs.playwright-test-path }}/playwright.config.ts"
 *     if [ ! -f "$CONFIG" ] && [ -f "playwright.config.ts" ]; then
 *       CONFIG="playwright.config.ts"
 *     fi
 *     npx playwright test --config="$CONFIG"
 *
 * Note what is missing: `--project`. So whichever config it picks, EVERY
 * project in it runs. The root `playwright.config.ts` declares three:
 *
 *   chromium     — the regression suite. This is the one CI wants.
 *   docs-capture — journeydoc screenshot capture (ADR-030). Re-shoots every
 *                  tutorial screenshot; the dedicated `Journeydoc Capture`
 *                  job runs it explicitly with `--project docs-capture`.
 *   visual       — pixel-diff baselines. Its own header says the PNGs are
 *                  host-font/GPU specific and that "a CI Linux runner will not
 *                  byte-match a dev-container baseline".
 *
 * Letting the root config be picked therefore runs two projects that the repo
 * itself documents as unable to pass on a CI runner, on top of the one that
 * can. Rather than delete or weaken them, `playwright-test-path: tests/e2e` in
 * the caller makes the workflow's FIRST lookup hit this file, which declares
 * only the regression project. The root config is untouched and stays the
 * entry point for local runs, `--project docs-capture` and `--project visual`.
 *
 * The report/output paths also differ deliberately. The workflow uploads
 * `server/apps/<app>/playwright-report/` and `server/apps/<app>/test-results/`,
 * so on CI the artifacts must land at the APP ROOT, not under `tests/e2e/`.
 * With the root config's paths the upload step matched nothing and silently
 * uploaded an empty artifact (`if-no-files-found: ignore`) — a failing run
 * with no report to read, which is exactly what the 2026-08-02 development
 * run produced.
 */

import { defineConfig, devices } from '@playwright/test'
import * as path from 'path'

import { resolveBaseUrl } from './base-url'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const STORAGE_STATE = path.resolve(__dirname, '.auth', 'user.json')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		['html', { open: 'never', outputFolder: path.join(APP_ROOT, 'playwright-report') }],
		['junit', { outputFile: path.join(APP_ROOT, 'test-results', 'results.xml') }],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// Centralised and STRICT — see tests/e2e/base-url.ts. Never reintroduce a
		// `|| 'http://localhost:8080'` fallback here: that silently retargets the
		// whole suite at the SHARED dev container.
		baseURL: resolveBaseUrl(),
		storageState: STORAGE_STATE,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'chromium',
			testIgnore: ['**/docs-screenshots.spec.ts', '**/visual/**'],
			use: { ...devices['Desktop Chrome'] },
		},
	],
})
