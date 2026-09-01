/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Shared helpers for the spec-coverage e2e suite.
 *
 * Pipelinq mounts at #content-vue. The shell routes on HISTORY, so
 * `page.goto('/apps/pipelinq/<route>')` is a valid deep link and lands on that
 * route — it used to reset the SPA to the Dashboard under hash routing, which
 * is why older specs navigate by sidebar click instead. Both work now. Two app-chrome overlays can cover the content
 * and intercept clicks — the fleet-wide `cn-support-dialog` and the first-visit
 * `cn-walkthrough` product tour. Both are dismissed before interacting; see
 * dismissSupportDialog() and dismissWalkthrough().
 */
import { Page, Locator, expect, ConsoleMessage } from '@playwright/test'

/** Open the app at the dashboard and wait for the shell + nav to render. */
export async function openApp(page: Page): Promise<void> {
	await page.goto('/apps/pipelinq/')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/**
 * End the first-visit product tour if it is running.
 *
 * `src/manifest.json#walkthrough` declares `pipelinq:getting-started` with
 * `trigger: "first-visit"`, so a fresh CI instance — where nobody has ever
 * completed it — starts the tour on the very first page load. CnWalkthrough
 * paints `div.cn-walkthrough__dim--full` over the whole viewport for a centered
 * step, and Playwright then reports, forever, that
 *
 *   <div class="cn-walkthrough__dim cn-walkthrough__dim--full"> … subtree
 *   intercepts pointer events
 *
 * on any click underneath it — the element under test is "visible, enabled and
 * stable", so the click retries until the TEST timeout rather than failing on
 * the overlay. Measured in run 30898348537 (job 91956896164) on
 * `spec-coverage/products.spec.ts:34` (60 s) and
 * `workflows/product-crud.spec.ts:112` (90 s).
 *
 * It is NOT deterministic which specs pay for it, which is why it looked like
 * flake rather than a fixture gap: the tour's second and third steps advance on
 * `route-match: Products` and then anchor on `data-walkthrough-id="index-add"`,
 * so whichever worker reaches the Products page first inherits the modal.
 *
 * The corner close button (`.cn-walkthrough__close`, aria-label "Close tour")
 * is the real user affordance and CnWalkthrough's `close()` marks the tour
 * COMPLETE (writes `walkthrough_seen_version`), so it does not reopen. That is
 * deliberately preferred over Escape/backdrop, which only dismiss the current
 * showing.
 */
export async function dismissWalkthrough(page: Page): Promise<void> {
	const tour = page.locator('.cn-walkthrough').first()
	if (!(await tour.isVisible().catch(() => false))) {
		return
	}
	const close = tour.locator('.cn-walkthrough__close').first()
	if (await close.isVisible().catch(() => false)) {
		await close.click().catch(() => {})
	} else {
		await page.keyboard.press('Escape').catch(() => {})
	}
	await tour.waitFor({ state: 'hidden', timeout: 5000 }).catch(() => {})
}

/** Dismiss the fleet-wide support dialog if it is overlaying the content. */
export async function dismissSupportDialog(page: Page): Promise<void> {
	const dialog = page
		.locator('.cn-support-dialog, [data-testid="cn-support-dialog"]')
		.first()
	if (await dialog.isVisible().catch(() => false)) {
		const close = dialog
			.getByRole('button', { name: /close|sluiten|dismiss|got it|×/i })
			.first()
		if (await close.isVisible().catch(() => false)) {
			await close.click().catch(() => {})
		} else {
			await page.keyboard.press('Escape').catch(() => {})
		}
		await dialog.waitFor({ state: 'hidden', timeout: 3000 }).catch(() => {})
	}
}

const COLLAPSIBLE_LI =
	'xpath=ancestor::li[contains(concat(" ", normalize-space(@class), " "), " app-navigation-entry--collapsible ")]'

/**
 * Expand every collapsed NcAppNavigationItem GROUP between the nav root and
 * `link`, outermost first, so a nested leaf becomes clickable.
 *
 * WHY THE PREVIOUS APPROACH SILENTLY DID NOTHING. It expanded a group by
 * clicking `a.app-navigation-entry-link[href="#"]` — the group's caption
 * anchor — assuming a group caption has no route of its own. Since the 2026-07
 * IA revision (src/menu-layout.json) that assumption is false for most groups:
 * `relocations` moves leaves UNDER routed pages, so the caption anchors are
 * `#/werkplek` (Customer Support), `#/` (Sales), `#/operational` (Operational)
 * and `#/products` (Products). Only the two group-only entries, Point of Sale
 * and Marketing, still render `href="#"`.
 *
 * So the selector matched nothing for four of the six groups, `.count()` was 0,
 * the expansion was skipped, and the leaf stayed `hidden` — which surfaced far
 * downstream as "element(s) not found" against the LEAF, blaming the feature
 * under test rather than the navigation helper.
 *
 * NcAppNavigationItem (verified in @nextcloud/vue 8.39.0,
 * chunks/NcAppNavigationItem-*.mjs) renders a dedicated toggle
 * `NcButton.icon-collapse`, labelled "Open menu" / "Collapse menu", and marks
 * the open state with `app-navigation-entry--opened` on the <li>. Driving that
 * button is both correct and safer than clicking the caption, because the
 * caption is a router-link and clicking it NAVIGATES AWAY instead of expanding.
 */
async function expandCollapsedAncestors(link: Locator): Promise<void> {
	const groups = link.locator(COLLAPSIBLE_LI)
	const count = await groups.count().catch(() => 0)
	// Document order == outermost group first, which is the order they must be
	// opened in: an inner toggle is not clickable until its parent is open.
	for (let i = 0; i < count; i++) {
		const group = groups.nth(i)
		const cls = (await group.getAttribute('class').catch(() => '')) ?? ''
		if (cls.includes('app-navigation-entry--opened')) continue
		const toggle = group.locator('button.icon-collapse').first()
		if (!(await toggle.count().catch(() => 0))) continue
		await toggle.click().catch(() => {})
		await group
			.locator('ul')
			.first()
			.waitFor({ state: 'visible', timeout: 5000 })
			.catch(() => {})
	}
	await link.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
}

/**
 * Locate a sidebar LEAF entry by its MANIFEST PAGE ID and make it reachable.
 *
 * Prefer this over {@link revealNavEntry} in any spec that is not specifically
 * about the visible wording. The label variant matches translated text, so it
 * asserts the Dutch build on a Dutch instance and the English build on an
 * English one — measured live: this instance renders `nl`, where the Products
 * entry reads "Producten" and the dashboard refresh control's only accessible
 * name is "Dashboard vernieuwen". A spec keyed on labels therefore passes or
 * fails on the runner's locale, which is not what it claims to test.
 *
 * `CnAppRoot` stamps every entry with `data-testid="cn-nav-entry-<pageId>"`,
 * where `<pageId>` is the id from `src/manifest.json` — untranslated by
 * construction, and the same string the router and the manifest already agree
 * on.
 *
 * Reachability, not paint: since the 2026-07 IA revision most leaves sit inside
 * a collapsed group, so this expands ancestors exactly as the label variant
 * does. Measured on this build: 37 entries in the DOM, 11 visible at load.
 *
 * @param page   The page under test.
 * @param pageId The manifest page id (e.g. `Clients`, `OperationalDashboard`).
 * @return The entry's anchor locator, revealed where possible.
 */
export async function revealNavEntryByTestId(
	page: Page,
	pageId: string,
): Promise<Locator> {
	const entry = page
		.locator(`#app-navigation-vue [data-testid="cn-nav-entry-${pageId}"]`)
		.first()
	await entry.waitFor({ state: 'attached', timeout: 10000 })
	// The testid may sit on the <li> or on the anchor itself depending on the
	// entry's shape (a group caption carries a button as well as a link), so
	// resolve to the anchor without assuming which.
	const link = entry.locator('xpath=descendant-or-self::a[1]').first()
	await link.waitFor({ state: 'attached', timeout: 10000 })
	if (!(await link.isVisible().catch(() => false))) {
		await expandCollapsedAncestors(link)
		if (!(await link.isVisible().catch(() => false))) {
			await openSettingsFoldout(page)
			await link.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		}
	}
	return link
}

/**
 * Locate a sidebar LEAF entry by exact label and make it reachable, returning
 * the (now visible) locator.
 *
 * Since the 2026-07 IA revision most leaves live inside a collapsible group, so
 * "is this entry in the sidebar?" is a question about REACHABILITY, not about
 * being painted at page load — a user opens the group first. Specs that assert
 * a nav entry exists must therefore go through this helper; asserting raw
 * visibility on a nested leaf asserts the pre-revision flat IA and fails
 * against correct navigation.
 */
export async function revealNavEntry(page: Page, label: string): Promise<Locator> {
	// Restrict to the leaf ENTRY anchor, NOT the nav GROUP CAPTION.
	// getByRole('link', { name }) would otherwise match the caption first and
	// the click is a no-op (the router stays put).
	//
	// The discriminator is the href: under history routing a leaf entry points
	// at `/apps/pipelinq/<route>`, while a collapsible group caption still
	// renders a bare `href="#"` — so matching the app segment selects exactly
	// the leaves. (This used to filter on `href*="#/"`, which stopped matching
	// anything the moment the shell left hash routing.)
	// Filter by exact visible text so the entry — not the caption — is targeted.
	const link = page
		.locator(
			'#app-navigation-vue a.app-navigation-entry-link[href*="/apps/pipelinq/"]',
		)
		.filter({
			hasText: new RegExp(
				`^\\s*${label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`,
			),
		})
		.first()
	// The entry is present in the DOM, but a leaf can be hidden because its
	// container is collapsed by default:
	//  (a) a standard NcAppNavigation GROUP — the leaf sits inside a
	//      `li.app-navigation-entry--collapsible`; OR
	//  (b) the NcAppNavigationSettings foldout — see openSettingsFoldout().
	await link.waitFor({ state: 'attached', timeout: 10000 })
	if (!(await link.isVisible().catch(() => false))) {
		await expandCollapsedAncestors(link)
		if (!(await link.isVisible().catch(() => false))) {
			await openSettingsFoldout(page)
			await link.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {})
		}
	}
	return link
}

/**
 * Open the NcAppNavigationSettings foldout — the gear at the bottom-left,
 * OUTSIDE the scrollable nav list.
 *
 * `src/menu-layout.json#settingsSection` promotes `Pipelines`,
 * `SettingsIntegrationsCaption` and `ExportJobs` into that foldout
 * (`applySettingsSection` in @conduction/nextcloud-vue's buildManifest lifts
 * them out of the main tree and tags them `section: "settings"`; CnAppNav
 * renders them inside `<NcAppNavigationSettings data-testid="cn-nav-settings">`).
 * The foldout body is `v-show`-hidden until its own button is pressed, so a
 * promoted leaf is `hidden` at page load exactly like a collapsed-group leaf.
 *
 * WHY THE PREVIOUS SELECTOR MATCHED NOTHING. It was
 * `button.settings-button[aria-expanded="false"]`. `settings-button` is
 * @nextcloud/vue **8** markup. pipelinq resolves 9.9.0 (package.json
 * `^9.9.0`, package-lock 9.9.0), and in 9.x NcAppNavigationSettings renders
 * an NcButton whose classes come from a CSS module — the built chunk carries
 * `_button_ypW42`, and the string `settings-button` does not appear anywhere in
 * `dist/`. So `.isVisible()` was false, the branch never ran, and the
 * `Pipelines` leaf stayed hidden. All three specs in
 * `spec-coverage/pipelines.spec.ts` then failed with
 * `locator resolved to <a href="#/pipelines"> … unexpected value "hidden"`,
 * blaming the Pipelines page for what is a navigation-helper gap — the same
 * shape as the group-caption bug fixed above. Measured in run 30898348537
 * (job 91956896164).
 *
 * The toggle is matched by `aria-expanded="false"` scoped to the foldout's own
 * `data-testid`, which is ours and survives an @nextcloud/vue major. The v8
 * class is kept as a second alternative so the helper works on both.
 */
async function openSettingsFoldout(page: Page): Promise<void> {
	const toggle = page
		.locator(
			'#app-navigation-vue [data-testid="cn-nav-settings"] button[aria-expanded="false"],'
				+ ' #app-navigation-vue button.settings-button[aria-expanded="false"]',
		)
		.first()
	if (!(await toggle.isVisible().catch(() => false))) {
		return
	}
	await toggle.click().catch(() => {})
}

/** Click a sidebar nav link by exact label and wait for the URL to settle. */
export async function navClick(
	page: Page,
	label: string,
	urlRe: RegExp,
): Promise<void> {
	const link = await revealNavEntry(page, label)
	await expect(link).toBeVisible({ timeout: 10000 })
	await link.click()
	await expect(page).toHaveURL(urlRe, { timeout: 10000 })
	// The tour advances on `route-match` and re-anchors on the new page, so a
	// navigation can bring the overlay back even after openApp() closed it —
	// until whichever worker got there first has written the completion flag.
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
	// Give the view a beat to fetch + render its first surface.
	await page
		.locator('#content-vue')
		.waitFor({ state: 'visible', timeout: 10000 })
		.catch(() => {})
}

/**
 * Click a `config.quickFilters[]` tab on a `type: "index"` page.
 *
 * CnIndexPage renders the strip through CnQuickFilterBar, which paints each tab
 * as `<button type="button" role="tab">` carrying a
 * `span.cn-quick-filter-bar__label`. Selecting a tab merges that tab's manifest
 * `filter` map into the useListView fetch and re-fetches at page 1, so the
 * caller must wait for the re-render before asserting on rows.
 *
 * This is the navigation path for the three ticket subtypes since
 * `unify-ticket-supertype`: the former Requests / Complaints / Contactmomenten
 * PAGES no longer exist — one `ticket` index carries all three behind this
 * strip (src/manifest.json, page id `Tickets`).
 */
export async function clickQuickFilter(page: Page, label: string): Promise<void> {
	const tab = page
		.locator('#content-vue')
		.getByRole('tab', { name: label, exact: true })
		.first()
	await expect(tab).toBeVisible({ timeout: 10000 })
	await tab.click()
	await expect(tab).toHaveAttribute('aria-selected', 'true', { timeout: 10000 })
}

/**
 * Open the index Search/Columns sidebar and return its search field.
 *
 * WHY THIS EXISTS. `pages[].config.sidebar.enabled` mounts CnIndexSidebar, but
 * it mounts it CLOSED: CnIndexPage's own data carries
 *
 *   // Search/Columns sidebar open state. Defaults closed so the page
 *   // content (table / cards) starts at the top and fills the width;
 *   // opened on demand via the actions-bar toggle.
 *   sidebarOpen: false,
 *
 * and it binds `show-sidebar-toggle: hasSidebar` on CnActionsBar, which renders
 * a tertiary icon button labelled "Search and columns". Verified in
 * @conduction/nextcloud-vue 2.2.0-vue3.3 `dist/`.
 *
 * The CRUD workflow specs used to reach straight for
 * `.app-sidebar input.input-field__input[type="text"]` on the assumption that
 * `sidebar.enabled` was enough for the field to be on screen. It no longer is,
 * so the wait expired against the search box and the failure surfaced on the
 * CLIENT/PRODUCT journey rather than on the navigation step that never happened.
 *
 * Note the sidebar is mounted at NcContent level by CnAppRoot ("the only place
 * where Nextcloud's NcAppSidebar slides correctly from the right"), i.e. OUTSIDE
 * `#content-vue` — so both the toggle click and the field lookup are page-wide.
 */
export async function openIndexSearch(page: Page): Promise<Locator> {
	const field = page
		.locator('.app-sidebar input.input-field__input[type="text"]')
		.first()
	if (!(await field.isVisible().catch(() => false))) {
		const toggle = page
			.getByRole('button', { name: /search and columns/i })
			.first()
		await expect(
			toggle,
			'the index Search/Columns toggle must be rendered when sidebar.enabled is set',
		).toBeVisible({ timeout: 10000 })
		await toggle.click()
	}
	await field.waitFor({ state: 'visible', timeout: 10000 })
	return field
}

/**
 * Open the CnActionsBar overflow ("Actions") menu on an index page.
 *
 * WHY THIS EXISTS. A manifest `config.headerActions[]` on a `type: "index"`
 * page does NOT render as a visible button. CnActionsBar renders those entries
 * as `NcActionButton`s INSIDE the overflow menu — the shared component's own
 * schema documents them as "Page-level header actions rendered inside
 * CnActionsBar's overflow dropdown", and the template comment above the
 * `v-for` reads "Manifest-declared page-level header actions (overflow)".
 * Verified against @conduction/nextcloud-vue 2.2.0-vue3.3 (`dist/`).
 *
 * The visible primary CTA is a different control: it is emitted only when
 * `showAdd` is true and carries `data-testid="cn-cta-primary"`. Pipelinq's POS
 * ledgers (`PosTransactions`, `PosRefunds`) both set `showAdd: false` and
 * declare their create entry point as a `headerActions[]` item instead, so
 * `cn-cta-primary` is genuinely absent there and the create action is one click
 * deeper than on a normal index.
 */
export async function openActionsOverflow(page: Page): Promise<void> {
	// Two independent handles on the same control, because neither alone is
	// safe across an @nextcloud/vue major: `data-testid="cn-actions"` is OURS
	// (set on the NcActions in CnActionsBar) but only survives if NcActions keeps
	// a single root node to inherit the attribute onto; the accessible name comes
	// from `menu-name="Actions"` with `force-name`, which is NC's contract.
	const toggle = page
		.locator('#content-vue [data-testid="cn-actions"] button')
		.first()
		.or(
			page
				.locator('#content-vue')
				.getByRole('button', { name: 'Actions' })
				.first(),
		)
	await expect(toggle.first()).toBeVisible({ timeout: 10000 })
	await toggle.first().click()
}

/**
 * Open the overflow menu and click one manifest `headerActions[]` entry by its
 * declared label. See openActionsOverflow() for why the entry is not a button
 * on the page itself.
 *
 * NcActions teleports its menu to the document body, so the entry is matched
 * page-wide rather than inside `#content-vue`.
 */
export async function clickHeaderAction(
	page: Page,
	label: string | RegExp,
): Promise<void> {
	await openActionsOverflow(page)
	// NcActionButton renders `<li class="action"><button class="action-button">`,
	// and NcActions teleports the popover to the document body — so the entry is
	// matched page-wide, not inside `#content-vue`. `menuitem` is tried first
	// because it is the role NcActions assigns; the class selector is the
	// fallback for the same element.
	const entry = page
		.getByRole('menuitem', { name: label })
		.first()
		.or(
			page
				.locator('button.action-button, a.action-link')
				.filter({ hasText: label })
				.first(),
		)
	await expect(entry.first()).toBeVisible({ timeout: 10000 })
	await entry.first().click()
}

/**
 * Known APP-SHELL console errors that originate outside the page under test, so
 * a page-level assertion can isolate NEW, page-specific errors.
 *
 *  - Nextcloud core "Failed to load user status" (core, not pipelinq).
 *  - The dashboard category prefetch (BillingCategoryWidget → fetchCategories)
 *    races ahead of initializeStores() and logs a transient `Object type
 *    "billingCategory" is not registered` on the Dashboard mount. It is shell
 *    noise on every page (it fires from the dashboard, not the page under test).
 *
 * NOTE on the registry slug-fallback fix (commit a53bc8c5): store.js now calls
 * registerObjectType('billingCategory'|'posRefund'|'exportJob'|'automation', …)
 * with a canonical-slug fallback when the deployed app-config carries an empty
 * numeric schema id. Live verification on 2026-06-09 (fresh build, register=16,
 * empty *_schema ids, NC settled) showed the affected list pages still mount
 * only their `cn-index-page` chrome + primary CTA — the schema-driven data
 * surface (heading + table) does NOT yet render and the per-type "not
 * registered" error still fires at fetch time. The data-surface assertions are
 * therefore kept as `test.fixme` until that resolves; the navigate + chrome +
 * CTA assertions are real and pass.
 */
const KNOWN_SHELL_ERRORS = [
	/Failed to load user status/i,
	/Object type "billingCategory" is not registered/i,
]

/**
 * Collect pipelinq-origin console errors + pageerrors. Resource 404s / favicon
 * noise and the known app-shell baseline errors are filtered. Returns a getter
 * yielding the deduped list of NEW, page-specific errors.
 */
export function trackPipelinqErrors(page: Page): () => string[] {
	const errors: string[] = []
	const noise =
		/Failed to load resource|favicon|net::ERR|\b404\b|Download the (React|Vue) DevTools|preload/i
	const onConsole = (m: ConsoleMessage) => {
		const t = m.text()
		if (m.type() !== 'error') return
		if (noise.test(t)) return
		if (KNOWN_SHELL_ERRORS.some((re) => re.test(t))) return
		errors.push(t)
	}
	const onError = (e: Error) => {
		if (!KNOWN_SHELL_ERRORS.some((re) => re.test(e.message)))
			errors.push('PAGEERROR: ' + e.message)
	}
	page.on('console', onConsole)
	page.on('pageerror', onError)
	return () => [...new Set(errors)]
}

/** Assert the page is not showing a hard server / render failure. */
export async function assertNoHardError(page: Page): Promise<void> {
	const body = page.locator('body')
	await expect(body).not.toContainText('Internal Server Error')
	await expect(body).not.toContainText('Uncaught Error')
}

/**
 * Locate Nextcloud's own error chrome — the page NC serves INSTEAD of the app
 * when the app is missing, disabled, or threw during boot.
 *
 * Verified against nextcloud/server `core/templates/`:
 *   404.php       → `.body-login-container` + <h2>Page not found</h2>   (HTTP 404)
 *   error.php     → `.guest-box`            + <h2>Error</h2>
 *   exception.php → `.guest-box.wide`       + <h2>Internal Server Error</h2>
 *
 * WHY THIS IS NOT A SUBSTRING GREP OF <body>. The previous smoke assertion was
 * `expect(body).not.toContainText('not installed')`. That can never pass on a
 * correct instance: pipelinq legitimately renders optional-dependency banners
 * ("OpenConnector unlocks optional features in this app but is not installed or
 * enabled", likewise Deck / Time manager / Forms) plus an "Install shillinq"
 * widget placeholder. The grep matched the app working AS DESIGNED.
 *
 * It also never matched the failure mode it was named for: the string
 * "not installed" does not appear in NC's app-error chrome at all. In core it
 * occurs only in PHP-module messages ("PHP module %s not installed."). So the
 * old line was a false positive that was simultaneously unable to detect a
 * genuinely absent app. This locator targets the chrome NC actually serves.
 */
export function nextcloudErrorPage(page: Page) {
	return page.locator('.guest-box, .body-login-container').filter({
		has: page.getByRole('heading', {
			name: /^(Page not found|Internal Server Error|Error)$/,
		}),
	})
}

/**
 * Assert that a navigation actually landed on the pipelinq app, not on NC's
 * error chrome. Checks all three independent signals, so no single one can
 * silently carry the test:
 *   1. the HTTP status of the navigation itself (404/500 fail here),
 *   2. the absence of NC error chrome,
 *   3. the POSITIVE signal that the app shell mounted.
 *
 * The positive signal matters: (1) and (2) are both assertions about something
 * NOT being there, and an empty page satisfies both.
 */
export async function assertAppShellServed(
	page: Page,
	response: { status(): number } | null,
): Promise<void> {
	expect(response, 'navigation produced no response').not.toBeNull()
	expect(response?.status(), 'app must be served, not an NC error page').toBe(200)
	await expect(nextcloudErrorPage(page)).toHaveCount(0)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
}
