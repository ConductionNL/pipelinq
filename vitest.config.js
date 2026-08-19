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
 *   • src/services/bsnValidation.js — the BSN 11-proef validator + masking
 *     (mirrors BsnValidationService).
 *
 * Both modules import nothing, so the environment is `node` and no stubs are
 * needed. Vitest only collects tests/vitest/**; the PHPUnit suite under
 * tests/Unit is untouched.
 */

const path = require('path')

module.exports = {
	test: {
		environment: 'node',
		globals: false,
		include: ['tests/vitest/**/*.spec.{js,ts}'],
		exclude: ['tests/e2e/**', 'tests/integration/**', 'tests/Unit/**', 'src/**', 'node_modules/**'],
	},
	resolve: {
		alias: [
			{ find: '@', replacement: path.resolve(__dirname, 'src') },
		],
	},
}
