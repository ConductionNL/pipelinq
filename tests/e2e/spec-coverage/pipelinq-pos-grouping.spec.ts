/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/pipelinq-pos-grouping/spec.md.
 *
 * This capability is PURE NAVIGATION, so almost all of it is browser-observable
 * and almost none of it needs an exclude — the left nav either groups these
 * leaves or it does not, and a click either lands on the route or it does not.
 *
 * WHERE THE SPEC AND THE SHIPPED IA DISAGREE (reported, not fixed)
 * ---------------------------------------------------------------
 * The 2026-07 nav-ia-cleanup revision landed AFTER this spec was written and
 * retired two thirds of it. Verified in the source, not assumed:
 *
 *   * REQ-PPOS-002's "Catalog" group does not exist. `src/menu-layout.json`
 *     relocates `Products` to `Dashboard` (Sales), and the dedicated
 *     `ProductBarcodeSearch` page was DELETED — src/manifest.json:1085 says so
 *     in `_searchNote`: "The dedicated 'Barcode lookup' page is gone
 *     (nav-ia-cleanup): this index's search already matches on barcode".
 *   * REQ-PPOS-003's `PosStaffList` / `PosRoleList` are not in the gear foldout
 *     — `src/menu-layout.json#settingsSection` holds only Pipelines,
 *     SettingsIntegrationsCaption and ExportJobs. Per `_adminSettingsNote` both
 *     moved to the Nextcloud admin page (/settings/admin/pipelinq) and "their
 *     in-app pages and nav entries are gone". No page declares /pos/staff or
 *     /pos/roles.
 *   * REQ-PPOS-001 asks for FIVE children under Point of Sale including
 *     "Boekhoudkundige Afhandeling" (ZReports). `src/menu-layout.json`
 *     relocates only four ids into `PointOfSale`; `ZReports` is a routable page
 *     (/pos/z-reports) with no menu entry at all, so it is reachable by deep
 *     link but not by navigation.
 *
 * Those three are excluded in the spec with exactly that reason. What IS true
 * of the shipped IA is asserted below in full.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
	navClick,
	nextcloudErrorPage,
	openApp,
	revealNavEntry,
} from '../helpers/pipelinq.ts'

/**
 * The four leaves `src/menu-layout.json` relocates into the PointOfSale group,
 * labelled AS RENDERED, not as declared.
 *
 * `src/manifest.json` declares these menu entries with Dutch labels
 * (`Kassabon`, `Retouren`, `Kassalade`, `Kassakoppeling audit`) and CnAppNav
 * puts every one of them through `t('pipelinq', …)`. `l10n/en.json` translates
 * two of the four — `Retouren` → `Returns` and `Kassalade` → `Cash drawer` —
 * and passes the other two through unchanged. The CI instance runs in English,
 * so a lookup by the MANIFEST label matches nothing for exactly those two.
 *
 * That is what happened in run 31473685688: `Kassabon` (identity translation)
 * resolved, and the very next iteration timed out after 10s waiting for
 * `Retouren`, in both specs below. `spec-coverage/returns.spec.ts` — green in
 * the same run — already navigates to this page as `Returns`, which is the
 * corroborating measurement that the English string is what the nav paints.
 */
const POS_CHILDREN: Array<{ label: string; url: RegExp }> = [
	{ label: 'Kassabon', url: /#\/pos$/ },
	{ label: 'Returns', url: /#\/pos\/refunds/ },
	{ label: 'Cash drawer', url: /#\/pos\/shifts/ },
	{ label: 'Kassakoppeling audit', url: /#\/kassakoppeling\/audit/ },
]

/** Is `label` painted as a TOP-LEVEL nav entry (not nested in a group)? */
async function isFlatTopLevelEntry(page: Page, label: string): Promise<boolean> {
	// A top-level entry's <li> is a direct child of the nav's root <ul>. A
	// grouped leaf sits one <ul> deeper, inside its group's collapsible <li>.
	const top = page.locator(
		'#app-navigation-vue > ul > li > a.app-navigation-entry-link, '
			+ '#app-navigation-vue nav > ul > li > a.app-navigation-entry-link',
	)
	const match = top.filter({ hasText: new RegExp(`^\\s*${label}\\s*$`) })
	return (await match.count()) > 0
}

// @e2e openspec/specs/pipelinq-pos-grouping/spec.md#point-of-sale-group-renders-with-its-runtime-children
test('the till runtime leaves are grouped under one "Point of Sale" entry', async ({
	page,
}) => {
	await openApp(page)

	// A single top-level "Point of Sale" entry, and it is a GROUP (no route of
	// its own — src/manifest.json declares it with children and no route).
	const group = page
		.locator('#app-navigation-vue')
		.getByText('Point of Sale', { exact: true })
	await expect(group).toHaveCount(1)

	// Each runtime child is reachable through the group…
	for (const { label } of POS_CHILDREN) {
		const leaf = await revealNavEntry(page, label)
		await expect(
			leaf,
			`"${label}" must be reachable under Point of Sale`,
		).toBeVisible({ timeout: 10000 })
	}

	// …and none of them is ALSO painted as a flat top-level entry, which is the
	// half of the scenario that the grouping actually changed.
	for (const { label } of POS_CHILDREN) {
		expect(
			await isFlatTopLevelEntry(page, label),
			`"${label}" must not also appear as a flat top-level nav entry`,
		).toBe(false)
	}

	await assertNoHardError(page)
})

// @e2e openspec/specs/pipelinq-pos-grouping/spec.md#children-navigate-to-their-existing-routes
test('every Point of Sale child navigates to its existing route', async ({
	page,
}) => {
	test.setTimeout(90000)
	await openApp(page)

	for (const { label, url } of POS_CHILDREN) {
		await navClick(page, label, url)
		await expect(
			nextcloudErrorPage(page),
			`"${label}" landed on Nextcloud error chrome`,
		).toHaveCount(0)
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	}

	await assertNoHardError(page)
})

/*
 * REQ-PPOS-004 — the regroup must not have moved a single page. Deep-linking
 * each route is the direct proof, and it is the one assertion in this capability
 * that survives the nav-ia-cleanup unchanged: a page that lost its MENU entry
 * (ZReports) must still resolve, which is precisely what "menu[] restructuring
 * only" means.
 *
 * `/pos/staff`, `/pos/roles` and `/products-barcode` are deliberately absent
 * from this list — those pages no longer exist at all (see the header). Naming
 * them here would assert a route the product retired on purpose.
 */
// @e2e openspec/specs/pipelinq-pos-grouping/spec.md#deep-links-resolve-after-regrouping
test('every regrouped POS and product route still resolves by deep link', async ({
	page,
}) => {
	test.setTimeout(120000)
	await openApp(page)

	const routes = [
		'/pos',
		'/pos/refunds',
		'/pos/shifts',
		'/pos/z-reports',
		'/products',
		'/kassakoppeling/audit',
	]

	for (const route of routes) {
		await page.goto(`/apps/pipelinq/${route}`)
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		// The route resolved to a real page component: the app shell is mounted,
		// Nextcloud served no error chrome, and the SPA did not bounce elsewhere.
		await expect(
			page.locator('#content-vue'),
			`${route} did not mount`,
		).toBeVisible({ timeout: 20000 })
		await expect(
			nextcloudErrorPage(page),
			`${route} produced Nextcloud error chrome`,
		).toHaveCount(0)
		// Asserted with a PREDICATE, not a pattern. Building a regex by escaping
		// only `/` leaves `.`, `?`, `+` and friends live as metacharacters — a
		// latent false pass, because `.` matching any character would let a
		// redirect to a similar-looking path satisfy the assertion (CodeQL
		// js/incomplete-sanitization). It also says the right thing for hash
		// history: what must survive the mount is the HASH, and comparing the
		// whole URL string would pass on a path-shaped match while vue-router had
		// quietly redirected to `/`.
		await expect(page, `${route} was redirected away`).toHaveURL((u) =>
			new URL(String(u)).hash.endsWith(route),
		)
		await expect(page.locator('#content-vue')).not.toContainText(
			'Internal Server Error',
		)
	}
})
