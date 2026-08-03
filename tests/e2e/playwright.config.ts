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
	// ── PARALLELISM: FILE-LEVEL, NOT TEST-LEVEL ─────────────────────────────
	// Single-worker, the suite measured 53.9 min (run 30804140274). The shared
	// workflow caps this job at `timeout-minutes: 45`, so from that cap's merge
	// (ConductionNL/.github c7c12f7) onwards a single-worker run is CANCELLED at
	// the boundary — and a cancelled job returns no verdict at all, which is
	// strictly worse than a red one.
	//
	// scholiq solved the same problem with `fullyParallel: true` + `workers: 4`,
	// justified by every spec being read-only against a pre-seeded dataset.
	// THAT ARGUMENT DOES NOT HOLD HERE and this config deliberately does not
	// copy it. 3 of the 44 specs perform real CRUD against OpenRegister:
	//
	//   workflows/client-crud.spec.ts   — creates/edits/deletes `client`
	//   workflows/product-crud.spec.ts  — creates/edits/deletes `product`
	//   workflows/pos-money.spec.ts     — creates `product`, `posTransaction`,
	//                                     `posTransactionLine`
	//
	// `fullyParallel: true` splits the tests INSIDE a file across workers.
	// client-crud and product-crud declare `test.describe.configure({ mode:
	// 'serial' })` and would survive that; pos-money.spec.ts does NOT, and it
	// shares a module-level `fx` FixtureSession across its three tests — split
	// across workers, each worker re-initialises `fx` and the later tests lose
	// the objects the earlier ones tracked. That is cross-worker interference
	// that presents exactly as flake.
	//
	// `fullyParallel: false` + `workers: 4` parallelises at the FILE level:
	// every spec file runs start-to-finish inside ONE worker, in declaration
	// order, so all three CRUD journeys stay intact and self-cleaning.
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
	//     once by `global-setup.ts` before any worker starts.
	//   • `PHP_CLI_SERVER_WORKERS: 8` is already set on the `php -S` server in
	//     the shared workflow, so it can serve 4 concurrent browsers.
	//
	// Work is well spread — the largest single file is 2.9 min — so 4 workers
	// are not bounded by one long file.
	fullyParallel: false,
	retries: process.env.CI ? 1 : 0,
	workers: 4,
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
