// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Vitest configuration for Pipelinq frontend unit tests.
 *
 * This OFFLINE suite (no Nextcloud runtime) targets the PURE, dependency-free
 * client-side helpers that mirror server-authoritative PHP formulas and are
 * used for real-time previews / button-gating before any HTTP round-trip:
 *   • src/services/posTotals.js   — POS line + cart total / BTW computation,
 *     incl/excl price-mode extraction, the per-rate tax breakdown, refund
 *     proportions, and the nl-NL EUR formatter (mirrors PosTransactionService
 *     / PosRefundService).
 *   • the SHARED BSN validator from @conduction/nextcloud-vue — the 11-proef
 *     check + masking (mirrors BsnValidationService). It used to live here as
 *     src/services/bsnValidation.js; the algorithm now belongs to the library,
 *     which owns its tests. What bsnValidation.spec.js checks is the CONTRACT
 *     pipelinq depends on — the exported symbols, the result field names
 *     BrpContactPanel reads, and the error codes it branches on — because those
 *     are what a version bump breaks silently.
 *
 * Every module under test imports nothing, so the environment is `node` and no
 * stubs are needed. That is why the BSN spec imports the validator's STANDALONE
 * module path rather than the package root: the root pulls the whole component
 * set and fails with `No "exports" main defined in @nextcloud/vue`.
 *
 * Vitest only collects tests/vitest/**; the PHPUnit suite under tests/Unit is
 * untouched.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: [
			'tests/e2e/**',
			'tests/integration/**',
			'tests/Unit/**',
			'src/**',
			'node_modules/**',
		],
	},
	resolve: {
		alias: [{ find: '@', replacement: path.resolve(__dirname, 'src') }],
	},
}
