/**
 * Single source of truth for the Nextcloud instance the e2e suite targets.
 *
 * WHY THIS EXISTS
 * ---------------
 * Two apps in this fleet were found running their e2e suites against the SHARED
 * dev container on :8080 — one of them the LOGIN specs, so every run fired
 * failed logins and brute-force lockouts into somebody else's instance. The
 * mechanism was a `?? 'http://localhost:8080'` fallback exactly like the one
 * this module replaces: nothing errors, the suite just silently retargets.
 *
 * The resolver is therefore STRICT — no literal fallback, ever. An unset
 * environment is a hard failure, not a default.
 *
 * ⚠️ It must, however, accept the name CI actually exports. The shared quality
 * workflow sets **`BASE_URL`**, not `PLAYWRIGHT_BASE_URL`. Adopting a
 * `PLAYWRIGHT_BASE_URL`-only resolver during its Vue 3 migration is what left
 * openconnector's E2E job hard-failing on every run since with
 * `Error: PLAYWRIGHT_BASE_URL is not set.` Accept both.
 *
 * `NEXTCLOUD_URL` is kept because this repo's own docs and the docs-capture
 * project already document it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

/**
 * Resolve the base URL of the Nextcloud instance under test.
 *
 * @throws {Error} When none of the accepted variables is set.
 * @return {string} The base URL, without a trailing slash.
 */
export function resolveBaseUrl(): string {
	const url = process.env.PLAYWRIGHT_BASE_URL
		?? process.env.BASE_URL
		?? process.env.NEXTCLOUD_URL
		?? process.env.NC_BASE_URL
	if (!url) {
		throw new Error(
			'No target instance configured. Set PLAYWRIGHT_BASE_URL (or BASE_URL, '
			+ 'which is what the shared CI workflow exports) to the Nextcloud '
			+ 'instance this suite should run against. There is deliberately no '
			+ 'default — a localhost:8080 fallback silently retargets the suite at '
			+ 'the SHARED dev container.',
		)
	}
	return url.replace(/\/+$/, '')
}
