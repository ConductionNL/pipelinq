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
import { resolveBaseUrl } from './base-url.ts'

const APP_ROOT = path.resolve(__dirname, '..', '..')
const STORAGE_STATE = path.resolve(__dirname, '.auth', 'user.json')

export default defineConfig({
	testDir: __dirname,
	globalSetup: path.resolve(__dirname, 'global-setup.ts'),
	timeout: 60_000,
	expect: { timeout: 15_000 },
	// ── PARALLELISM ─────────────────────────────────────────────────────────
	// Measured on this branch, same suite, CI runner:
	//   workers: 1, fullyParallel: false  → 53.9 min suite / 56.9 min job
	//   workers: 4, fullyParallel: false  → 30.9 min job  (file-level)
	//   workers: 6, fullyParallel: true   → this config   (test-level)
	//
	// The shared workflow caps this job at `timeout-minutes: 45`
	// (ConductionNL/.github c7c12f7), and the fleet budget is 20 min, so
	// file-level parallelism is not enough on its own: with 44 spec files of
	// very uneven size the tail is bounded by the longest FILE, not the longest
	// test. `fullyParallel: true` schedules individual TESTS, which is what
	// gets this suite under budget.
	//
	// WHY TEST-LEVEL SPLITTING IS SAFE HERE. Unlike scholiq, pipelinq's specs
	// are NOT all read-only — 3 of the 44 do real CRUD against OpenRegister:
	//
	//   workflows/client-crud.spec.ts   — creates/edits/deletes `client`
	//   workflows/product-crud.spec.ts  — creates/edits/deletes `product`
	//   workflows/pos-money.spec.ts     — creates `product`, `posTransaction`,
	//                                     `posTransactionLine`
	//
	// Each was checked for state shared BETWEEN its tests, which is what
	// test-level splitting would break:
	//   • client-crud and product-crud declare
	//     `test.describe.configure({ mode: 'serial' })`. Playwright honours
	//     serial mode under fullyParallel — the whole block runs in ONE worker,
	//     in order — so their multi-step journeys stay intact.
	//   • pos-money has no serial block, and does not need one: each of its
	//     three tests constructs its OWN `const fx = new FixtureSession(page)`
	//     and cleans up within the test. Nothing crosses a test boundary.
	//
	// Cross-FILE interference was checked and is absent:
	//   • Fixtures are name-scoped per run (`E2E-DEEP-<runId>`, fixtures.ts) and
	//     cleanup deletes only tracked ids, so no worker can delete another's.
	//   • No read-only spec asserts a data count. The only `toHaveCount(n>0)`
	//     assertions are structural chrome (1 h1, 1 skip-link, 4 KPI cards).
	//     Specs over the same collections (products, client-management,
	//     pos-transaction-core, barcode-lookup) assert shell state only — a
	//     heading, no "Internal Server Error", and `emptyState.or(dataTable)`,
	//     which tolerates both an empty and a populated list.
	//   • The one shared mutable artefact, the auth `storageState`, is written
	//     once by `global-setup.ts` before any worker starts; workers only read.
	//
	// Worker count is 6, not 4: the runner is 4-vCPU but these tests are
	// I/O-bound on the PHP server, so oversubscribing wins. 6 is deliberately
	// held at or below the server's own `PHP_CLI_SERVER_WORKERS: 8` so the
	// browsers cannot queue behind a saturated `php -S`.
	fullyParallel: true,
	retries: process.env.CI ? 1 : 0,
	workers: process.env.CI ? 6 : 1,
	// ── WHY A globalTimeout LIVES HERE ──────────────────────────────────────
	// The shared workflow caps this job at `timeout-minutes: 45`. That cap is a
	// CANCELLATION, and a cancelled job is not a verdict: Playwright never prints
	// a tally, the `if: failure()` trace upload does not run, and `gh pr checks`
	// renders the cancellation as a plain "fail" — indistinguishable from a real
	// one. A run that hits the cap therefore tells you nothing at all.
	//
	// A globalTimeout INSIDE the config makes Playwright stop itself first and
	// exit with a reported tally and its artifacts, so the run still says
	// something. 38 minutes leaves ~7 minutes of the job budget for the reporter
	// and both upload steps.
	globalTimeout: 38 * 60 * 1000,
	reporter: [
		[
			'html',
			{
				open: 'never',
				outputFolder: path.join(APP_ROOT, 'playwright-report'),
			},
		],
		[
			'junit',
			{ outputFile: path.join(APP_ROOT, 'test-results', 'results.xml') },
		],
		['list'],
	],
	outputDir: path.join(APP_ROOT, 'test-results'),

	use: {
		// Centralised and STRICT — see tests/e2e/base-url.ts. Never reintroduce a
		// `|| 'http://localhost:8080'` fallback here: that silently retargets the
		// whole suite at the SHARED dev container.
		baseURL: resolveBaseUrl(),
		storageState: STORAGE_STATE,
		// `on-first-retry` writes a trace only for the SECOND attempt. Every
		// failure that reproduces identically on retry is fine, but a failure
		// that does NOT reproduce — the ones worth a trace — leaves no record of
		// the attempt that actually failed. `retain-on-failure` traces every
		// attempt and keeps the ones that failed.
		trace: 'retain-on-failure',
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
