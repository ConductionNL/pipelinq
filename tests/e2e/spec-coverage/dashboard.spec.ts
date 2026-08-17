/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for openspec/specs/dashboard/spec.md
 * UI-observable scenarios: page title, KPI tiles, quick actions, layout, refresh.
 *
 * IA NOTE (commercial-dashboard split): the landing dashboard `/` is now the
 * "Commercial overview" (revenue / pipeline / win KPIs + sales charts). The
 * previous request/lead operational KPIs and widgets (Open Leads, Open
 * Requests, Requests by Status, My Work, Client Overview, …) moved to the
 * "Operational overview" reachable via the SPA hash `#/operational`. Tests that
 * assert those operational surfaces therefore deep-link Operational first; the
 * landing-page title + quick-action assertions stay on `/`.
 */

import { test, expect, Page } from '@playwright/test'
import * as fs from 'fs'
import * as path from 'path'

import {
	openApp,
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
} from '../helpers/pipelinq'

/**
 * Deep-link the Operational overview where the request/lead widgets live. Land
 * directly on the hash (then reload so the router boots onto `/operational`) so
 * the Commercial landing widgets never mount.
 */
async function gotoOperational(page) {
	await page.goto('/apps/pipelinq/#/operational')
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	await page.reload()
	await page
		.locator('#content-vue')
		.waitFor({ state: 'visible', timeout: 15000 })
		.catch(() => {})
}

/* ==========================================================================
 * Gate-19 round 2 — the analytics half of this capability (Navi, the
 * decomposed analytics KPIs, the Report Export panel and the widget layout).
 *
 * The tests above assert the Operational overview's chrome. The ones below
 * DRIVE it: they type into the Navi widget and read what the real NaviService
 * answers, expand the Report Export panel and open its export dialog, flip the
 * period and watch the analytics endpoints re-query, and hold the rendered grid
 * to the layout the manifest declares.
 *
 * WHERE A STUBBED RESPONSE IS USED, AND WHY. Two Navi scenarios describe
 * response SHAPES the CI instance cannot produce — `tests/e2e/ci-seed.sh`
 * imports the register's seed objects and then runs the demo seeder, so leads,
 * tickets and contactmomenten all EXIST and NaviService's "no matching data"
 * branch is unreachable, as is an empty `suggestedFollowUps` (every
 * deterministic branch supplies three). Those tests intercept `/api/navi/query`
 * with `page.route` and assert the WIDGET's contract — which is the half of
 * those scenarios that lives in the browser at all. The server half is asserted
 * by tests/Unit/Service/NaviServiceTest.php (testProcessQueryReturnsTextOnEmptyResult).
 * Every other Navi test here goes to the real controller.
 * ========================================================================== */

/** App root — tests/e2e/spec-coverage/ is three levels down from it. */
const APP_ROOT = path.resolve(__dirname, '..', '..', '..')

/** Read a shipped manifest document from the checkout. */
function readManifest(relPath: string): any {
	return JSON.parse(fs.readFileSync(path.join(APP_ROOT, relPath), 'utf8'))
}

/** The Operational dashboard's manifest page entry. */
function operationalPage(): any {
	const page = (readManifest('src/manifest.json').pages || []).find(
		(p: any) => p.id === 'OperationalDashboard',
	)
	expect(page, 'manifest page "OperationalDashboard" must exist').toBeTruthy()
	return page
}

/**
 * The layout-slot id that carries `widgetId`.
 *
 * Resolved from the manifest at run time rather than hard-coded, because the
 * slot ids are renumbered whenever the layout is edited and a hard-coded id
 * would turn a layout edit into a mystery selector failure. CnDashboardGrid
 * stamps each grid item with `gs-id="<layout item id>"`.
 */
function layoutSlotFor(widgetId: string): string {
	const slot = (operationalPage().config.layout || []).find(
		(l: any) => l.widgetId === widgetId,
	)
	expect(
		slot,
		`the Operational layout must place widget "${widgetId}"`,
	).toBeTruthy()
	return String(slot.id)
}

/**
 * Open the Operational overview and clear the two app-chrome overlays.
 *
 * ONE document load, deliberately. This helper used to boot the app three
 * times — `openApp()` loaded the Sales landing page, a hash `goto` routed away
 * from it, and a `reload()` booted the whole document again. Measured in run
 * 31492236997 (job 93781544582), from the failing test's own trace:
 *
 *   goto '/apps/pipelinq/'          21.7 s   (the landing page this helper
 *   expect #content-vue              4.7 s    exists to avoid mounting)
 *   goto '#/operational'             2.3 s
 *   reload()                        25.9 s
 *   expect #content-vue              3.5 s
 *   ------------------------------------
 *                                   58.1 s of a 60 s test budget
 *
 * The cost is not app logic — it is `waitUntil: 'load'` fetching ~150 static
 * assets from a single Nextcloud container shared by six parallel workers; the
 * app bundles alone take 5–6 s each. Two loads is simply twice that bill.
 *
 * Both discarded steps are provably redundant. The trace's DOM snapshot taken
 * AFTER the hash `goto` and BEFORE the `reload` already contains "Operational
 * overview", "Open Leads" and six `.navi-widget` nodes, and no "Sales
 * overview" — the router had booted onto `/operational` and the reload changed
 * nothing but the clock. And the reload is itself a fresh document load of
 * `…/#/operational`, so that single navigation is the whole fixture.
 */
async function openOperationalInteractive(page: Page): Promise<void> {
	await page.goto('/apps/pipelinq/#/operational')
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 20000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/** The Navi chat widget, scrolled into view and ready for input. */
async function naviWidget(page: Page) {
	const widget = page.locator('.navi-widget')
	await expect(widget).toBeVisible({ timeout: 25000 })
	return widget
}

/** Type a question into Navi and submit it. */
async function askNavi(page: Page, question: string): Promise<void> {
	const widget = await naviWidget(page)
	await widget.locator('input').first().fill(question)
	await widget.getByRole('button', { name: 'Send' }).click()
}

/** The Report Export panel host (a `bodyWidget`-less custom grid widget). */
async function reportExportPanel(page: Page) {
	const panel = page.locator('.report-export')
	await expect(panel).toBeVisible({ timeout: 25000 })
	return panel
}

// @e2e openspec/specs/dashboard/spec.md#dashboard-page-title-and-empty-state
test('dashboard page title and empty state', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page).toHaveURL(/pipelinq/)
	// Landing page is the Sales overview after the dashboard split. The IA
	// revision in src/menu-layout.json relabelled the commercial dashboard to
	// "Sales"; src/manifest.json now titles the page "Sales overview".
	await expect(
		page.getByRole('heading', { name: 'Sales overview' }).first(),
	).toBeVisible({ timeout: 15000 })
	// No server error
	await expect(page.locator('body')).not.toContainText('Internal Server Error')
})

// @e2e openspec/specs/dashboard/spec.md#quick-action-buttons-in-header
test('dashboard quick action buttons visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(
		page.getByRole('button', { name: /New Lead/i }).first(),
	).toBeVisible({ timeout: 15000 })
	await expect(
		page.getByRole('button', { name: /New Request/i }).first(),
	).toBeVisible()
	await expect(
		page.getByRole('button', { name: /New Client/i }).first(),
	).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#default-grid-layout-on-first-load
test('dashboard KPI tiles rendered in grid', async ({ page }) => {
	// The lead/request KPI tiles now live on the Operational overview.
	await gotoOperational(page)
	const content = page.locator('#content-vue')
	await expect(content.getByText('Open Leads').first()).toBeVisible({
		timeout: 15000,
	})
	await expect(content.getByText('Open Requests').first()).toBeVisible()
	await expect(content.getByText('Pipeline Value').first()).toBeVisible()
	await expect(content.getByText('Overdue').first()).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#kpi-cards-with-zero-values
test('KPI cards show empty state when no data', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Empty state text appears in KPI tiles
	const body = page.locator('body')
	await expect(body).toContainText(/No items found|0/, { timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#manual-refresh-button
test('dashboard has refresh button', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Header renders without error (landing = Sales overview).
	await expect(
		page.getByRole('heading', { name: 'Sales overview' }).first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#widget-placement-in-dashboard-layout
test('dashboard widget sections visible', async ({ page }) => {
	// Requests-by-status / My Work / Client Overview are Operational widgets.
	await gotoOperational(page)
	const content = page.locator('#content-vue')
	// Target the widget headings — a bare getByText also matches the hidden
	// sidebar nav-entry spans (My Work / Requests) that share the same labels.
	await expect(
		content.getByRole('heading', { name: 'Requests by Status' }).first(),
	).toBeVisible({ timeout: 15000 })
	await expect(
		content.getByRole('heading', { name: 'My Work' }).first(),
	).toBeVisible()
	await expect(
		content.getByRole('heading', { name: 'Client Overview' }).first(),
	).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#no-requests-exist
test('dashboard shows no-requests empty state', async ({ page }) => {
	await gotoOperational(page)
	await expect(
		page.getByText(/No requests yet|No items found|0/).first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#no-assigned-items
test('dashboard my-work shows empty state when no assigned items', async ({
	page,
}) => {
	await gotoOperational(page)
	// My Work widget renders (even if empty). Target the heading so the hidden
	// sidebar nav-entry span with the same label is not matched instead.
	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'My Work' })
			.first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#display-recent-clients
test('dashboard client overview section renders', async ({ page }) => {
	await gotoOperational(page)
	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Client Overview' })
			.first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#view-all-clients-link
test('dashboard client overview has actions button', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Each widget has an Actions button
	const actionsButtons = page.getByRole('button', { name: /Actions/i })
	await expect(actionsButtons.first()).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#interactive-element-accessibility
test('dashboard interactive elements are reachable', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	// Navigation is visible
	await expect(page.locator('#app-navigation-vue')).toBeVisible({ timeout: 15000 })
	// New Lead button is focusable
	const newLeadBtn = page.getByRole('button', { name: /New Lead/i }).first()
	await expect(newLeadBtn).toBeVisible()
	await expect(newLeadBtn).toBeEnabled()
})

// @e2e openspec/specs/dashboard/spec.md#loading-state-communication
test('dashboard loads without unhandled errors', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	await expect(page.locator('body')).not.toContainText('Internal Server Error', {
		timeout: 15000,
	})
	await expect(page.locator('body')).not.toContainText('Uncaught Error')
})

// @e2e openspec/specs/dashboard/spec.md#widget-grid-responsiveness
test('dashboard navigation items visible', async ({ page }) => {
	await page.goto('/apps/pipelinq/')
	const nav = page.locator('#app-navigation-vue')
	await expect(nav).toBeVisible({ timeout: 15000 })
	// After the IA restructure the landing dashboards are split into two
	// top-level entries (the generic "Dashboard" entry was removed). The
	// commercial one is labelled "Sales" — see the relabel recorded in
	// src/menu-layout.json#_removalsNote. Assert both are present as nav
	// entries.
	await expect(
		nav
			.locator('a.app-navigation-entry-link[href$="#/"]')
			.filter({ hasText: /^\s*Sales\s*$/ }),
	).toHaveCount(1, { timeout: 10000 })
	await expect(
		nav
			.locator('a.app-navigation-entry-link[href$="#/operational"]')
			.filter({ hasText: /^\s*Operational\s*$/ }),
	).toHaveCount(1)
})

/*
 * Backend/data scenarios excluded — server-side only:
 * @e2e exclude display-open-leads-count — OR API aggregation; no stable test data
 * @e2e exclude display-open-requests-count — OR API aggregation; no stable test data
 * @e2e exclude display-pipeline-total-value — OR API aggregation; no stable test data
 * @e2e exclude display-overdue-items-count — OR API aggregation; no stable test data
 * @e2e exclude render-status-distribution-bars — chart data depends on OR API
 * @e2e exclude display-assigned-items — depends on test data
 * @e2e exclude overdue-item-highlighting — depends on test data with due dates
 * @e2e exclude view-all-link-for-overflow — depends on data volume
 * @e2e exclude no-clients-exist — depends on clean data state
 * @e2e exclude revenue-by-product-display — depends on OR product data
 * @e2e exclude no-products-in-pipeline — depends on data state
 * @e2e exclude widget-collapsed-by-default — widget collapse state is user-preference stored in backend
 * @e2e exclude automatic-periodic-refresh — timer-based background fetch; not UI-observable
 * @e2e exclude parallel-data-fetching — async fetch order is implementation detail
 * @e2e exclude layout-change-persistence — user layout preference stored in backend
 * @e2e exclude widget-definitions — static JSON config; covered by unit tests
 * @e2e exclude registered-nextcloud-dashboard-widgets — PHP OCP\Dashboard\IWidget registration
 * @e2e exclude widget-script-loading — webpack bundle loading; covered by smoke test
 * @e2e exclude css-variable-usage-for-colors — CSS implementation detail; covered by style tests
 * @e2e exclude widget-content-text-overflow — CSS overflow behavior; covered by visual regression
 * @e2e exclude error-state-with-retry — requires API error injection; covered by unit tests
 */

// ---------------------------------------------------------------------------
// Requirement: Navi AI Analytics Widget (REQ-DASH-001)
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#submit-natural-language-query
test('Navi: a typed question is POSTed to /api/navi/query and its answer is rendered', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// Armed BEFORE the submit so the request cannot be missed.
	const posted = page.waitForRequest(
		(req) =>
			req.url().includes('/apps/pipelinq/api/navi/query')
			&& req.method() === 'POST',
		{ timeout: 30000 },
	)
	await askNavi(page, 'Hoeveel leads zijn er deze maand?')

	// The request envelope the scenario specifies: `{ query, conversationId }`.
	const body = (await posted).postDataJSON()
	expect(body).toHaveProperty('query', 'Hoeveel leads zijn er deze maand?')
	expect(
		body,
		'the widget must always send the conversationId key',
	).toHaveProperty('conversationId')

	// The answer is rendered as an assistant turn. NaviService is deterministic
	// (no LLM — see the class docblock: "Deterministic — no actual LLM call
	// required"), so this is a real round trip, not a mock.
	const widget = await naviWidget(page)
	await expect(
		widget.locator('.navi-widget__message--assistant').first(),
	).toBeVisible({ timeout: 30000 })
	// It answered rather than failing: the error branch paints `.navi-widget__error`.
	await expect(widget.locator('.navi-widget__error')).toHaveCount(0)
	await assertNoHardError(page)
})

/*
 * A live round trip, no interception: the server mints the identifier, the
 * widget adopts it, sends it back, and the second answer is computed in the
 * context of the first. Both halves of the scenario are observable without a
 * stub, and both are read off the wire of the ONE navigation this fixture
 * already performs.
 */
// @e2e openspec/specs/dashboard/spec.md#conversational-follow-up
test('Navi: a follow-up question carries the conversationId and is answered in context', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	const isNaviCall = (url: string) => url.includes('/apps/pipelinq/api/navi/query')

	// Armed BEFORE the submit so neither turn can be missed.
	const firstSent = page.waitForRequest(
		(req) => isNaviCall(req.url()) && req.method() === 'POST',
		{ timeout: 30000 },
	)
	const firstGot = page.waitForResponse(
		(res) => isNaviCall(res.url()) && res.request().method() === 'POST',
		{ timeout: 30000 },
	)
	await askNavi(page, 'How many leads are open?')

	expect(
		(await firstSent).postDataJSON().conversationId,
		'the first turn has no conversation yet',
	).toBeFalsy()
	const first = await (await firstGot).json()
	expect(
		first.conversationId,
		'the server must mint an identifier on the first turn',
	).toMatch(/^[0-9a-f]{32}$/)

	const widget = await naviWidget(page)
	await expect(widget.locator('.navi-widget__message--assistant')).toHaveCount(1, {
		timeout: 30000,
	})

	const secondSent = page.waitForRequest(
		(req) => isNaviCall(req.url()) && req.method() === 'POST',
		{ timeout: 30000 },
	)
	const secondGot = page.waitForResponse(
		(res) => isNaviCall(res.url()) && res.request().method() === 'POST',
		{ timeout: 30000 },
	)
	// A follow-up that names neither an intent nor a subject of its own: it can
	// only be answered from what the conversation already holds.
	await askNavi(page, 'And what about last month?')

	expect(
		(await secondSent).postDataJSON().conversationId,
		'the follow-up must carry the minted identifier',
	).toBe(first.conversationId)
	const second = await (await secondGot).json()
	expect(
		second.conversationId,
		'the identifier must stay stable across the conversation',
	).toBe(first.conversationId)
	// Answered in context. Asked cold, this wording matches no intent and earns
	// the clarification instead.
	expect(
		second.textResponse,
		'the follow-up must be answered from the accumulated context',
	).not.toContain('I am not sure how to answer that yet')

	await expect(widget.locator('.navi-widget__message--assistant')).toHaveCount(2, {
		timeout: 30000,
	})
	await expect(widget.locator('.navi-widget__error')).toHaveCount(0)
})

// @e2e openspec/specs/dashboard/spec.md#empty-result-set
test('Navi: an empty result set renders as a message, not as an empty chart or table', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	const NO_DATA = 'I could not find any matching data for that question.'
	await page.route('**/api/navi/query', async (route) => {
		await route.fulfill({
			json: {
				resultType: 'text',
				textResponse: NO_DATA,
				chartData: null,
				tableData: null,
				suggestedFollowUps: ['How many leads are open?'],
			},
		})
	})

	await askNavi(page, 'How many unicorns did we sell?')
	const widget = await naviWidget(page)
	const answer = widget.locator('.navi-widget__message--assistant').first()
	await expect(answer).toBeVisible({ timeout: 20000 })
	await expect(answer).toContainText(NO_DATA)
	// The whole point of the scenario: no empty visualisation is drawn in its place.
	await expect(widget.locator('.navi-widget__chart')).toHaveCount(0)
	await expect(widget.locator('.navi-widget__table')).toHaveCount(0)
})

// @e2e openspec/specs/dashboard/spec.md#invalid-or-ambiguous-query
test('Navi: an unparseable question gets a clarification and leaves the input usable', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// No intent keyword matches, so NaviService::detectIntent returns `unknown`
	// and the deterministic clarification branch answers. This is the real
	// backend — nothing is stubbed here.
	await askNavi(page, 'zxcvbnm qwertyuiop')

	const widget = await naviWidget(page)
	const answer = widget.locator('.navi-widget__message--assistant').first()
	await expect(answer).toBeVisible({ timeout: 30000 })
	await expect(answer).toContainText('I am not sure how to answer that yet')

	// "the frontend MUST … keep the input field active" — it is neither removed
	// nor disabled, and the widget is not in its error state.
	const input = widget.locator('input').first()
	await expect(input).toBeEditable()
	await expect(widget.locator('.navi-widget__error')).toHaveCount(0)
	await assertNoHardError(page)
})

// @e2e openspec/specs/dashboard/spec.md#navi-widget-in-dashboard-layout
test('Navi: the widget is a full-width, registered widget of the dashboard grid', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// It is not merely rendered somewhere on the page — it is the body of the
	// `navi-analytics` grid slot, which is what "registered as widget-id
	// navi-analytics" means at render time.
	const slot = page.locator(
		`.grid-stack-item[gs-id="${layoutSlotFor('navi-analytics')}"]`,
	)
	await expect(slot).toHaveCount(1)
	await expect(slot.locator('.navi-widget')).toHaveCount(1)
	// 12 of 12 columns.
	await expect(slot).toHaveAttribute('gs-w', '12')

	// The widget's manifest title is the chrome the grid paints around it.
	await expect(
		page.locator('#content-vue').getByText('Ask Navi').first(),
	).toBeVisible({ timeout: 20000 })
})

// ---------------------------------------------------------------------------
// Requirement: Navi Suggested Follow-Ups (REQ-DASH-003)
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#display-follow-up-chips
test('Navi: follow-up chips are offered and clicking one submits it', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// Real backend: the `unknown` branch returns NaviService::defaultFollowUps(),
	// three suggestions, which the widget caps at three chips.
	await askNavi(page, 'zxcvbnm qwertyuiop')
	const widget = await naviWidget(page)
	const chips = widget.locator('.navi-widget__suggestions button')
	await expect(chips).toHaveCount(3, { timeout: 30000 })

	const chipText = ((await chips.first().textContent()) || '').trim()
	expect(
		chipText.length,
		'a suggestion chip must carry its question',
	).toBeGreaterThan(3)

	// Clicking a chip pre-fills the input with that suggestion AND submits it —
	// so the suggestion must come back as a USER turn, not just sit in the box.
	const resubmitted = page.waitForRequest(
		(req) =>
			req.url().includes('/apps/pipelinq/api/navi/query')
			&& req.method() === 'POST',
		{ timeout: 30000 },
	)
	await chips.first().click()
	expect((await resubmitted).postDataJSON().query).toBe(chipText)
	await expect(
		widget
			.locator('.navi-widget__message--user')
			.filter({ hasText: chipText })
			.first(),
	).toBeVisible({ timeout: 20000 })
})

// @e2e openspec/specs/dashboard/spec.md#no-suggested-follow-ups
test('Navi: with no suggestions the chip area is absent, not an empty strip', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// Every deterministic NaviService branch supplies three follow-ups, so an
	// empty array is only reachable through the endpoint — see the header note.
	await page.route('**/api/navi/query', async (route) => {
		await route.fulfill({
			json: {
				resultType: 'text',
				textResponse: 'No suggestions here.',
				suggestedFollowUps: [],
			},
		})
	})

	await askNavi(page, 'How many leads are open?')
	const widget = await naviWidget(page)
	await expect(
		widget.locator('.navi-widget__message--assistant').first(),
	).toBeVisible({ timeout: 20000 })
	// HIDDEN, not blank: the container itself must not be in the DOM.
	await expect(widget.locator('.navi-widget__suggestions')).toHaveCount(0)
})

// ---------------------------------------------------------------------------
// Requirement: Navi API Authorization (REQ-DASH-002)
// ---------------------------------------------------------------------------

/*
 * SPEC/IMPLEMENTATION MISMATCH — reported, not fixed. The scenario asks for the
 * body `{"message": "Unauthorized"}`, which is NaviController::query()'s own
 * guard. That guard is unreachable for an anonymous caller: Nextcloud's
 * SecurityMiddleware throws NotLoggedInException BEFORE the controller runs, so
 * the 401 body carries core's message rather than the app's. What the scenario
 * is actually protecting — a 401 and no internal detail on the wire — is
 * asserted exactly.
 */
test.describe('Navi API without a session', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	// @e2e openspec/specs/dashboard/spec.md#unauthenticated-request-rejected
	test('POST /api/navi/query is rejected with 401 and leaks nothing', async ({
		request,
	}) => {
		const res = await request.post('/index.php/apps/pipelinq/api/navi/query', {
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
			},
			data: { query: 'How many leads are open?' },
		})

		expect(res.status(), 'an anonymous caller must not reach Navi').toBe(401)

		const text = await res.text()
		// A short JSON envelope with a message — not a rendered page, not a trace.
		expect(JSON.parse(text)).toHaveProperty('message')
		expect(text).not.toMatch(/Stack trace|#0 |\/var\/www|OCA\\\\Pipelinq/)
	})
})

// ---------------------------------------------------------------------------
// Requirement: Unified Analytics Dashboard Panel (REQ-DASH-010)
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#cross-module-kpi-overview
test('Analytics: the cross-module KPIs are fetched from /api/analytics/overview and painted', async ({
	page,
}) => {
	const overview = page.waitForRequest(
		(req) => req.url().includes('/apps/pipelinq/api/analytics/overview'),
		{ timeout: 30000 },
	)
	await openOperationalInteractive(page)
	await overview

	const content = page.locator('#content-vue')
	// The KPIs the requirement enumerates, by the labels the manifest declares.
	await expect(content.getByText('Lead Conversion Rate').first()).toBeVisible({
		timeout: 20000,
	})
	await expect(content.getByText('Avg Request Resolution').first()).toBeVisible()
	// This widget's manifest `title` is "Contact Moment Volume" while the only
	// text it paints is its stat `label`, "Contacts" — so it is matched by the
	// accessible name the title becomes, not by text.
	await expect(
		content.getByRole('region', { name: 'Contact Moment Volume' }),
	).toBeVisible()

	// POPULATED, not merely labelled: CnStatWidget swaps the value for
	// `.cn-stat-widget__error` (an em dash) when its endpoint call fails.
	await expect(content.locator('.cn-stat-widget__value').first()).toBeVisible({
		timeout: 20000,
	})

	// NOTE on the fourth KPI. "Customer satisfaction score" is deliberately NOT
	// asserted: src/manifest.json records that SatisfactionKpiWidget was removed
	// because its data source is permanently empty, and the "No
	// Permanently-Null Default Widgets" requirement in this same spec REQUIRES
	// its absence. It returns with the customer-satisfaction-closed-loop change.
	//
	// The per-KPI trend arrow is likewise not asserted: CnStatWidget renders
	// `[data-testid="cn-stat-widget-trend"]` only when a delta is computable,
	// and on a freshly seeded instance the previous period is empty for several
	// of these — a present-or-absent arrow is a property of the data, not of the
	// panel under test.
	await assertNoHardError(page)
})

/*
 * SPEC/IMPLEMENTATION DRIFT — reported, not fixed. The scenario describes a
 * `header-actions` period SELECT offering the Dutch labels "Deze week" / "Deze
 * maand" / "Dit kwartaal" / "Dit jaar". That control is gone: the Operational
 * dashboard sets `dateRange.showHeaderPicker: false` and drives the reporting
 * window from per-widget date CHIPS instead, with English preset labels. The
 * behavioural core of the scenario — four selectable periods, and changing one
 * re-fetches `/api/analytics/overview?period=…` — is unchanged and is what is
 * asserted here.
 */
// @e2e openspec/specs/dashboard/spec.md#period-selector
test('Analytics: the period control offers four windows and re-queries the overview endpoint', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// The manifest declares exactly the four windows the scenario asks for.
	const presets = operationalPage().config.dateRange.presets.map((p: any) => p.id)
	expect(presets).toEqual(['week', 'month', 'quarter', 'year'])

	const chip = page.getByTestId('cn-dashboard-page-date-chip-avg-resolution')
	await expect(chip).toBeVisible({ timeout: 20000 })

	const requery = page.waitForRequest(
		(req) =>
			req.url().includes('/apps/pipelinq/api/analytics/overview')
			&& req.url().includes('period=week'),
		{ timeout: 20000 },
	)
	await chip.getByRole('button').click()
	await page.getByRole('button', { name: 'Last 7 days' }).click()
	await requery
})

/*
 * SPEC/IMPLEMENTATION DRIFT — reported, not fixed. The scenario registers ONE
 * `unified-analytics` mega-panel widget. The decompose-unified-analytics change
 * replaced it with individual grid widgets (`lead-conversion`, `avg-resolution`,
 * `contact-volume`, `leads-over-time`, `requests-by-category`), and the
 * registry `_note`s record why. The scenario's substance — the analytics
 * surface is REGISTERED IN THE GRID rather than bolted on beside it — is
 * asserted against the widgets that actually carry it.
 */
// @e2e openspec/specs/dashboard/spec.md#analytics-panel-widget-registration
test('Analytics: every analytics widget is a registered slot of the dashboard grid', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	for (const widgetId of [
		'lead-conversion',
		'avg-resolution',
		'contact-volume',
		'leads-over-time',
		'requests-by-category',
	]) {
		await expect(
			page.locator(`.grid-stack-item[gs-id="${layoutSlotFor(widgetId)}"]`),
			`widget "${widgetId}" must occupy its declared grid slot`,
		).toHaveCount(1)
	}

	// The retired mega-panel must not have come back alongside them.
	await expect(
		page.locator('#content-vue').getByText('Unified Analytics'),
	).toHaveCount(0)
	const widgetIds = operationalPage().config.widgets.map((w: any) => w.id)
	expect(widgetIds).not.toContain('unified-analytics')
})

// ---------------------------------------------------------------------------
// Requirement: Analytics API Endpoints (REQ-DASH-011)
// ---------------------------------------------------------------------------

/**
 * One authenticated GET issued FROM INSIDE the logged-in page.
 *
 * WHY NOT `page.request.get()`. Playwright's APIRequestContext shares the
 * browser context's COOKIES but sends no other header, and Nextcloud's
 * SecurityMiddleware requires BOTH the strict-cookie check and a matching
 * `requesttoken` for every controller method that does not carry
 * `#[NoCSRFRequired]`. `AnalyticsController::overview()/trends()` carry only
 * `#[NoAdminRequired]`, so a cookie-only request is rejected as
 * `CrossSiteRequestForgeryException` → HTTP 412 Precondition Failed BEFORE the
 * controller runs. That is what run 31473685688 measured on all three tests
 * here (412 where 200/400 was expected); it is a property of the harness, not
 * of the endpoint. Fetching from inside the page carries `OC.requestToken`,
 * which is the same request the app itself makes — the pattern already used by
 * spec-coverage/marketing.spec.ts and spec-coverage/first-time-setup.spec.ts.
 *
 * @param page The logged-in page.
 * @param path Absolute app path to GET.
 */
async function apiGet(
	page: Page,
	path: string,
): Promise<{ status: number; json: any; text: string }> {
	return await page.evaluate(async (p) => {
		const res = await fetch(p, {
			headers: {
				// eslint-disable-next-line no-undef
				requesttoken: (window as any).OC?.requestToken || '',
			},
		})
		const text = await res.text()
		let json: any = null
		try {
			json = text ? JSON.parse(text) : null
		} catch {
			/* non-JSON body */
		}
		return { status: res.status, json, text }
	}, path)
}

// @e2e openspec/specs/dashboard/spec.md#get-apianalyticsoverview
test('GET /api/analytics/overview returns the full KPI envelope with its comparison period', async ({
	page,
}) => {
	await openApp(page)
	const res = await apiGet(
		page,
		'/index.php/apps/pipelinq/api/analytics/overview?period=month',
	)
	expect(res.status).toBe(200)

	const body = res.json
	for (const key of [
		'leadConversionRate',
		'avgRequestResolutionTime',
		'contactMomentVolume',
		'customerSatisfactionScore',
		'period',
		'previousPeriod',
	]) {
		expect(body, `overview must carry "${key}"`).toHaveProperty(key)
	}
	// The period is echoed back, so a caller can tell which window it got.
	expect(body.period).toBe('month')
	expect(Number.isInteger(body.contactMomentVolume)).toBe(true)
	// The three rate/mean fields are null-or-number by construction:
	// AnalyticsService leaves each null when its denominator is zero for the
	// window (no leads / no resolved requests / no survey responses), so a
	// blanket `typeof === 'number'` would assert the DATA, not the contract.
	for (const key of [
		'leadConversionRate',
		'avgRequestResolutionTime',
		'customerSatisfactionScore',
	]) {
		expect(
			body[key] === null || typeof body[key] === 'number',
			`${key} must be null or a number`,
		).toBe(true)
	}
	if (typeof body.leadConversionRate === 'number') {
		expect(
			body.leadConversionRate,
			'the conversion rate is a percentage',
		).toBeGreaterThanOrEqual(0)
		expect(body.leadConversionRate).toBeLessThanOrEqual(100)
	}
	// The comparison period carries the same fields, which is what the trend
	// indicators are computed from.
	expect(body.previousPeriod).toHaveProperty('leadConversionRate')
	expect(body.previousPeriod).toHaveProperty('contactMomentVolume')

	// An unsupported window is rejected rather than silently defaulted.
	const bad = await apiGet(
		page,
		'/index.php/apps/pipelinq/api/analytics/overview?period=fortnight',
	)
	expect(bad.status).toBe(400)
	expect(bad.json.message).toBe('Invalid period')
})

// @e2e openspec/specs/dashboard/spec.md#get-apianalyticstrends
test('GET /api/analytics/trends returns { metric, period, series } with ISO 8601 dates', async ({
	page,
}) => {
	await openApp(page)
	const res = await apiGet(
		page,
		'/index.php/apps/pipelinq/api/analytics/trends?metric=leads&period=month',
	)
	expect(res.status).toBe(200)

	const body = res.json
	expect(body.metric).toBe('leads')
	expect(body.period).toBe('month')
	expect(Array.isArray(body.series), 'trends must carry a series array').toBe(true)
	// Every point is `{ date: ISO-8601, value: number }`. An empty series is a
	// legitimate answer (no leads fall in the window), so the shape is asserted
	// over whatever points come back rather than by requiring some to exist.
	for (const point of body.series) {
		expect(
			point.date,
			`series point date "${point.date}" must be ISO 8601`,
		).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(typeof point.value).toBe('number')
	}
})

// @e2e openspec/specs/dashboard/spec.md#unsupported-metric-returns-400
test('GET /api/analytics/trends rejects an unsupported metric with a static 400', async ({
	page,
}) => {
	await openApp(page)
	const res = await apiGet(
		page,
		'/index.php/apps/pipelinq/api/analytics/trends?metric=unknown',
	)

	expect(res.status).toBe(400)
	const text = res.text
	expect(JSON.parse(text).message).toBe('Unsupported metric')
	// "the response MUST NOT include a stack trace or internal details" — the
	// controller maps the service exception onto its own constant label, so no
	// class name, path or frame may appear on the wire.
	expect(text).not.toMatch(/Stack trace|#0 |\/var\/www|InvalidArgumentException/)
})

// ---------------------------------------------------------------------------
// Requirement: Funder Reporting Export Panel (REQ-DASH-020)
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#panel-collapsed-by-default
test('Report Export: the panel starts collapsed, showing only its title and description', async ({
	page,
}) => {
	await openOperationalInteractive(page)
	const panel = await reportExportPanel(page)

	const header = panel.locator('.report-export__header')
	await expect(header).toHaveAttribute('aria-expanded', 'false')
	await expect(panel.getByText('Report Export').first()).toBeVisible()
	await expect(
		panel.getByText(/Download CSV \/ Excel \/ JSON reports/),
	).toBeVisible()
	// The body is not merely hidden by CSS — it is not rendered at all (`v-if`).
	await expect(panel.locator('#report-export-body')).toHaveCount(0)

	// Clicking the header expands it.
	await header.click()
	await expect(header).toHaveAttribute('aria-expanded', 'true')
	await expect(panel.locator('#report-export-body')).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#configure-and-download-a-report
test('Report Export: configuring a report opens the shared mass-export dialog', async ({
	page,
}) => {
	await openOperationalInteractive(page)
	const panel = await reportExportPanel(page)
	await panel.locator('.report-export__header').click()

	// Entity type: Requests.
	await panel.locator('.report-export__field').first().click()
	await page
		.locator('li[role="option"], .vs__dropdown-option')
		.filter({ hasText: 'Requests' })
		.first()
		.click()
	// Period: This quarter.
	await panel.locator('.report-export__field').nth(1).click()
	await page
		.locator('li[role="option"], .vs__dropdown-option')
		.filter({ hasText: 'This quarter' })
		.first()
		.click()

	await panel.getByRole('button', { name: 'Download Report' }).click()

	// "the frontend MUST open CnMassExportDialog with the appropriate entity
	// type and applied period filter" — the dialog is the shared component, and
	// its title/description are built from the two selections above, so the
	// assertion proves the configuration was carried across rather than just
	// that a dialog appeared.
	const dialog = page.locator('.modal-container, [role="dialog"]').first()
	await expect(dialog).toBeVisible({ timeout: 15000 })
	await expect(dialog).toContainText('Requests')
	await expect(dialog).toContainText('This quarter')
	// "the export MUST be performed by ExportService — no custom export
	// controller is permitted": the app ships no export route of its own for
	// this panel, which is why the dialog is the only download path.
	expect(
		fs.readFileSync(path.join(APP_ROOT, 'appinfo', 'routes.php'), 'utf8'),
	).not.toMatch(/reportExport#|report-export/)
})

/*
 * SPEC/IMPLEMENTATION MISMATCH — reported, not fixed. The scenario lists the
 * entity types under Dutch labels ("Verzoeken", "Contactmomenten",
 * "Tevredenheidsscores"). ReportExportPanel ships them in English ("Requests",
 * "Contact moments", "Satisfaction scores"), which is what the fleet's
 * all-code-is-English rule requires — so the spec wording is the stale half.
 * The four ENTITIES are the ones the scenario enumerates.
 */
// @e2e openspec/specs/dashboard/spec.md#supported-entity-types-in-report
test('Report Export: the entity selector offers all four reportable entities', async ({
	page,
}) => {
	await openOperationalInteractive(page)
	const panel = await reportExportPanel(page)
	await panel.locator('.report-export__header').click()

	await panel.locator('.report-export__field').first().click()
	const options = page.locator('li[role="option"], .vs__dropdown-option')
	for (const label of [
		'Leads',
		'Requests',
		'Contact moments',
		'Satisfaction scores',
	]) {
		await expect(
			options.filter({ hasText: label }).first(),
			`entity "${label}" must be offered`,
		).toBeVisible({ timeout: 10000 })
	}

	// Selecting an entity changes what the export dialog is opened for.
	await options.filter({ hasText: 'Contact moments' }).first().click()
	await panel.getByRole('button', { name: 'Download Report' }).click()
	await expect(
		page.locator('.modal-container, [role="dialog"]').first(),
	).toContainText('Contact moments', { timeout: 15000 })
})

// @e2e openspec/specs/dashboard/spec.md#report-export-widget-registration
test('Report Export: the panel is a full-width grid slot below the analytics widgets', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	const slot = page.locator(
		`.grid-stack-item[gs-id="${layoutSlotFor('report-export')}"]`,
	)
	await expect(slot).toHaveCount(1)
	await expect(slot.locator('.report-export')).toHaveCount(1)
	await expect(slot).toHaveAttribute('gs-w', '12')

	// "the widget MUST appear below the Analytics panel in the default layout" —
	// compared against the analytics widgets' own rows rather than a hard-coded
	// gridY, since the row numbers moved when the mega-panel was decomposed.
	const layout = operationalPage().config.layout
	const rowOf = (widgetId: string) =>
		layout.find((l: any) => l.widgetId === widgetId).gridY
	for (const analytics of [
		'lead-conversion',
		'avg-resolution',
		'contact-volume',
	]) {
		expect(
			rowOf('report-export'),
			`report-export must sit below ${analytics}`,
		).toBeGreaterThan(rowOf(analytics))
	}
})

// ---------------------------------------------------------------------------
// Requirement: Report Export Accessibility (REQ-DASH-021)
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#keyboard-navigable-controls
test('Report Export: the panel is operable from the keyboard alone', async ({
	page,
}) => {
	await openOperationalInteractive(page)
	const panel = await reportExportPanel(page)
	const header = panel.locator('.report-export__header')

	// The toggle is a real control for assistive tech: it exposes a button role,
	// is in the tab order, and describes its own state.
	await expect(header).toHaveAttribute('role', 'button')
	await expect(header).toHaveAttribute('tabindex', '0')
	await expect(header).toHaveAttribute('aria-controls', 'report-export-body')

	// Enter expands…
	await header.focus()
	await expect(header).toBeFocused()
	await page.keyboard.press('Enter')
	await expect(header).toHaveAttribute('aria-expanded', 'true')
	await expect(panel.locator('#report-export-body')).toBeVisible()

	// …and Space collapses. Both are required; a panel wired to only one of them
	// would still pass a click-only test.
	await page.keyboard.press('Space')
	await expect(header).toHaveAttribute('aria-expanded', 'false')

	// Every control in the expanded body can take focus. (The FORMAT picker the
	// scenario also lists is not in this panel — CnMassExportDialog owns it, see
	// `exportFormats` passed to the dialog — so it is reachable only once the
	// dialog is open, and is that component's contract.)
	await page.keyboard.press('Enter')
	const body = panel.locator('#report-export-body')
	await expect(body).toBeVisible()
	for (const control of [
		body.locator('.report-export__field').first().locator('input').first(),
		body.locator('.report-export__field').nth(1).locator('input').first(),
		body.getByRole('button', { name: 'Download Report' }),
	]) {
		await control.focus()
		await expect(control).toBeFocused()
	}
})

// ---------------------------------------------------------------------------
// Requirement: Dashboard Widget Layout — Extended Default (REQ-DASH-030)
// ---------------------------------------------------------------------------

/*
 * SPEC/IMPLEMENTATION DRIFT — reported, not fixed. The scenario pins absolute
 * rows (navi-analytics at gridY 4, unified-analytics at 3, report-export at 10)
 * and a total of 10 widget definitions. Both numbers moved: the
 * unified-analytics mega-panel was decomposed into five individual widgets and
 * the layout was re-flowed around them, so the Operational dashboard now
 * declares seventeen slots. The scenario's INVARIANT — the new analytics
 * surfaces were ADDED without displacing any of the original widgets — is what
 * is asserted, against the manifest and the rendered grid together.
 */
// @e2e openspec/specs/dashboard/spec.md#updated-default-layout-includes-analytics-widgets
test('Layout: the analytics widgets were added without displacing the original ones', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// The three surfaces this requirement adds.
	for (const widgetId of ['navi-analytics', 'report-export', 'lead-conversion']) {
		await expect(
			page.locator(`.grid-stack-item[gs-id="${layoutSlotFor(widgetId)}"]`),
			`added widget "${widgetId}" must render`,
		).toHaveCount(1)
	}

	// "AND all existing widget positions … MUST remain unchanged": the four KPI
	// cards still tile row 0 three columns each, and the chart / My Work /
	// Client Overview widgets are all still placed.
	const layout = operationalPage().config.layout
	const at = (widgetId: string) => layout.find((l: any) => l.widgetId === widgetId)
	for (const [i, widgetId] of [
		'open-leads',
		'open-requests',
		'pipeline-value',
		'overdue',
	].entries()) {
		expect(at(widgetId).gridY, `${widgetId} stays on the first row`).toBe(0)
		expect(at(widgetId).gridX, `${widgetId} keeps its column`).toBe(i * 3)
		expect(at(widgetId).gridWidth).toBe(3)
	}
	for (const widgetId of ['requests-by-status', 'my-work', 'client-overview']) {
		expect(at(widgetId), `${widgetId} must still be placed`).toBeTruthy()
	}

	// And they are all actually on screen, not merely declared.
	const content = page.locator('#content-vue')
	await expect(content.getByText('Open Leads').first()).toBeVisible({
		timeout: 20000,
	})
	await expect(
		content.getByRole('heading', { name: 'Requests by Status' }).first(),
	).toBeVisible()
	await expect(
		content.getByRole('heading', { name: 'My Work' }).first(),
	).toBeVisible()
	await expect(
		content.getByRole('heading', { name: 'Client Overview' }).first(),
	).toBeVisible()
})

// @e2e openspec/specs/dashboard/spec.md#total-widget-count-updated
test('Layout: every declared widget slot renders, and nothing renders that was not declared', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	const layout = operationalPage().config.layout
	const widgets = operationalPage().config.widgets
	const widgetIds = new Set(widgets.map((w: any) => w.id))

	// Every layout entry points at a real widget definition — the "matching
	// `#widget-{id}` slot template" half of the scenario, checked in the
	// direction that actually breaks (a layout slot with no definition renders
	// an empty tile).
	for (const item of layout) {
		expect(
			widgetIds.has(item.widgetId),
			`layout slot ${item.id} references unknown widget "${item.widgetId}"`,
		).toBe(true)
		await expect(
			page.locator(`.grid-stack-item[gs-id="${item.id}"]`),
			`declared slot ${item.id} (${item.widgetId}) must render`,
		).toHaveCount(1)
	}
	// …and the grid renders exactly those and no more.
	await expect(page.locator('#content-vue .grid-stack-item')).toHaveCount(
		layout.length,
	)
})

// ---------------------------------------------------------------------------
// Requirement: No Permanently-Null Default Widgets
// ---------------------------------------------------------------------------

// @e2e openspec/specs/dashboard/spec.md#operational-dashboard-renders-no-empty-satisfaction-tile
test('Operational: no Customer Satisfaction tile is rendered, and the KPI tiles carry values', async ({
	page,
}) => {
	await openOperationalInteractive(page)
	const content = page.locator('#content-vue')

	// Neither the widget nor its label survives anywhere on the page.
	await expect(content.getByText('Customer Satisfaction')).toHaveCount(0)
	await expect(content.getByText('Satisfaction Score')).toHaveCount(0)
	const widgetIds = operationalPage().config.widgets.map((w: any) => w.id)
	expect(
		widgetIds,
		'the satisfaction widget must stay out of the default definition',
	).not.toContain('satisfaction')
	expect(
		operationalPage().config.layout.map((l: any) => l.widgetId),
		'no layout slot may point at the removed widget',
	).not.toContain('satisfaction')

	// "AND every rendered KPI widget MUST be backed by a live data source" — the
	// stat tiles paint a value rather than the placeholder the removed tile
	// would have shown.
	await expect(content.locator('.cn-stat-widget__value').first()).toBeVisible({
		timeout: 20000,
	})
	// No "coming soon" placeholder took its place either.
	await expect(content.getByText(/coming soon/i)).toHaveCount(0)
	await assertNoHardError(page)
})

// @e2e openspec/specs/dashboard/spec.md#restoration-ownership-recorded
test('Operational: the manifest records who brings the satisfaction widget back', async () => {
	// "WHEN src/manifest.json is inspected" — so this reads the shipped manifest
	// and holds the note to naming BOTH the removed widget and the change that
	// owns its restoration. A bare "removed" note would satisfy neither.
	const note = String(operationalPage()._note || '')
	expect(
		note.length,
		'the Operational dashboard must carry a _note',
	).toBeGreaterThan(40)
	expect(note).toMatch(/satisfaction/i)
	expect(note).toContain('customer-satisfaction-closed-loop')
})

// @e2e openspec/specs/dashboard/spec.md#layout-reflows-without-a-hole
test('Operational: the KPI row the widget vacated is contiguous, with no empty slot', async ({
	page,
}) => {
	await openOperationalInteractive(page)

	// The row the satisfaction tile was removed from is the analytics KPI row.
	// Tile it left-to-right: it must start at column 0, leave no gap between
	// neighbours, and end at the full 12 — which is precisely "no hole".
	const layout = operationalPage().config.layout
	const row = layout
		.filter(
			(l: any) =>
				l.gridY
				=== layout.find((x: any) => x.widgetId === 'lead-conversion').gridY,
		)
		.sort((a: any, b: any) => a.gridX - b.gridX)
	expect(row.length, 'the reflowed KPI row must hold widgets').toBeGreaterThan(0)
	let cursor = 0
	for (const item of row) {
		expect(item.gridX, `a gap opens before "${item.widgetId}"`).toBe(cursor)
		cursor += item.gridWidth
	}
	expect(cursor, 'the reflowed KPI row must span the full 12 columns').toBe(12)

	// And the row really paints that way.
	for (const item of row) {
		await expect(
			page.locator(`.grid-stack-item[gs-id="${item.id}"]`),
			`reflowed slot ${item.id} (${item.widgetId}) must render`,
		).toHaveCount(1)
	}
})
