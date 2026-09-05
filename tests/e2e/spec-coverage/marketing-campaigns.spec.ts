/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for marketing-campaigns, phase 4 of the
 * marketing programme.
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT
 * ------------------------------------
 * The CI instance runs OpenRegister and Pipelinq and nothing else: the
 * workflow pins `additional-apps` to openregister and planninq, so neither
 * portaliq nor shillinq is present. Five things are still observable:
 *
 *   1. the Marketing group reaches a Campaigns page listing the seeded
 *      campaigns;
 *   2. a campaign detail page deep-links by PATH and shows its landing-page
 *      section;
 *   3. the landing-page action answers `portaliq_missing` and says so in
 *      words, because portaliq is genuinely absent here;
 *   4. `GET /api/campaigns/{id}/report` returns the whole record in one
 *      response, with all three attribution models already in it;
 *   5. the campaign report is a card on the Reports page, not a menu entry,
 *      and renders from that one response.
 *
 * Everything else is excluded in the spec with a reason and asserted by
 * PHPUnit: link decoration happens inside a mail body, a created page needs
 * portaliq's listener, a submission needs portaliq to dispatch it, and a
 * paid-invoice close needs shillinq's register.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-reimports the
 * register, which brings in lib/Settings/register.d/98-marketing-campaigns.json:
 * three campaigns (`Webinar AI voor gemeenten`, `Open source voor provincies`,
 * `Woo-verzoeken sneller afhandelen`) and three touchpoints on the first of
 * them. Every literal asserted below was read out of that file, not guessed.
 *
 * The app is PATH-routed (history mode), never hash-routed: a deep link is
 * `/apps/pipelinq/campaigns/<id>`, and `#/campaigns/<id>` reaches nothing.
 * The Marketing group renders COLLAPSED and `nav, [role="navigation"]`
 * matches Nextcloud's own app menu first, so the Campaigns entry is reached
 * through `revealNavEntry()`, never a raw `getByRole('link')`.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
	navClick,
	openApp,
	revealNavEntry,
} from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'

/** The campaign the register fragment seeds, by its own slug. */
const SEEDED_SLUG = 'campaign-webinar-ai-voor-gemeenten'
const SEEDED_NAME = 'Webinar AI voor gemeenten'
const SEEDED_UTM = 'webinar-ai-voor-gemeenten'

/** One authenticated JSON call issued from inside the logged-in page. */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number; json: any; text: string }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',

					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let json: any = null
			try {
				json = text ? JSON.parse(text) : null
			} catch {
				/* non-JSON */
			}
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

/** Read the id off an OpenRegister object or a pipelinq API row. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** Deep-link to an app route and let the view settle. */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto(`/apps/pipelinq${route}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/** The seeded campaign, by the slug its register fragment declares. */
async function seededCampaign(page: Page): Promise<any> {
	const res = await api(page, 'GET', `${OR}/campaign?_limit=100`)
	expect(res.status, res.text).toBe(200)
	const rows: any[] = res.json?.results ?? res.json?.data ?? []
	const found = rows.find(
		(row: any) =>
			row?.utmCampaign === SEEDED_UTM || row?.['@self']?.slug === SEEDED_SLUG,
	)
	expect(
		found,
		`the seeded campaign ${SEEDED_SLUG} must exist; ci-seed.sh reimports 98-marketing-campaigns.json`,
	).toBeTruthy()
	return found
}

/* ══════════════════════════════════════════════════════════════════════════
 * The Campaigns page — src/manifest.d/78-marketing-campaigns.json, a
 * declarative type:index over `campaign` under the Marketing group.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Campaigns page', () => {
	// @e2e marketing-campaigns::the-campaigns-page-lists-the-seeded-campaign
	test('the Marketing group reaches a Campaigns page listing the seeded campaign', async ({
		page,
	}) => {
		await openApp(page)
		await revealNavEntry(page, 'Campaigns')
		await navClick(page, 'Campaigns', /\/campaigns(\?|$)/)

		await expect(
			page.getByText(SEEDED_NAME, { exact: false }).first(),
		).toBeVisible({
			timeout: 15000,
		})
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The campaign detail page — the same fragment's type:detail page, whose
 * CampaignLandingPageSection hosts the portaliq hand-off.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Campaign detail page', () => {
	// @e2e marketing-campaigns::a-campaign-deep-links-by-path-and-shows-its-landing-page-section
	test('a campaign deep-links by path and offers to create a landing page', async ({
		page,
	}) => {
		await gotoRoute(page, '/campaigns')
		const campaign = await seededCampaign(page)

		await gotoRoute(page, `/campaigns/${idOf(campaign)}`)

		await expect(page.getByTestId('campaign-landing-empty')).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByTestId('campaign-landing-create')).toBeVisible()
		await assertNoHardError(page)
	})

	// @e2e openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#portaliq-absent-is-its-own-answer
	test('with portaliq absent the action answers portaliq_missing and says so', async ({
		page,
	}) => {
		await gotoRoute(page, '/campaigns')
		const campaign = await seededCampaign(page)
		const id = idOf(campaign)

		const created = await api(
			page,
			'POST',
			`${APP}/api/campaigns/${id}/landing-page`,
			{ portal: 'open-tilburg', route: '/campagne/e2e-probe' },
		)
		expect(created.status, created.text).toBe(501)
		expect(created.json?.error).toBe('portaliq_missing')

		// Nothing was recorded on the campaign: a refusal writes nothing.
		const reread = await api(page, 'GET', `${OR}/campaign/${id}`)
		const stored = reread.json?.data ?? reread.json ?? {}
		expect(stored.landingPage?.route ?? '').toBe('')

		await gotoRoute(page, `/campaigns/${id}`)
		await page.getByTestId('campaign-landing-create').click()
		await expect(page.getByRole('alert')).toContainText('portaliq_missing', {
			timeout: 15000,
		})
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The report endpoint — GET /api/campaigns/{id}/report, one aggregate the
 * page paints from (pipelinq#1781).
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Campaign report endpoint', () => {
	// @e2e openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#the-report-page-renders-from-one-response
	test('one response carries the channels, the leads, the totals and all three models', async ({
		page,
	}) => {
		await gotoRoute(page, '/campaigns')
		const campaign = await seededCampaign(page)

		const report = await api(
			page,
			'GET',
			`${APP}/api/campaigns/${idOf(campaign)}/report`,
		)
		expect(report.status, report.text).toBe(200)

		const body = report.json ?? {}
		expect(body.campaign?.utmCampaign).toBe(SEEDED_UTM)
		for (const section of [
			'window',
			'channels',
			'engagement',
			'leads',
			'totals',
			'models',
			'cost',
		]) {
			expect(body, `the report must carry ${section}`).toHaveProperty(section)
		}
		for (const model of ['first', 'last', 'linear']) {
			expect(
				body.models,
				`switching the model must not need a second request, so ${model} arrives in this one`,
			).toHaveProperty(model)
		}

		// The seeded campaign records a linkedin spend and a budget; an
		// unrecorded cost would be null, never zero.
		expect(body.cost?.totalEur).toBeGreaterThan(0)
	})

	// @e2e openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#an-unknown-cost-is-absent-not-zero
	test('a campaign that recorded no spend reports no cost, not a cost of zero', async ({
		page,
	}) => {
		await gotoRoute(page, '/campaigns')
		const res = await api(page, 'GET', `${OR}/campaign?_limit=100`)
		const rows: any[] = res.json?.results ?? res.json?.data ?? []
		const noSpend = rows.find(
			(row: any) => row?.utmCampaign === 'open-source-voor-provincies',
		)
		expect(noSpend, 'the seeded campaign without costs must exist').toBeTruthy()

		const report = await api(
			page,
			'GET',
			`${APP}/api/campaigns/${idOf(noSpend)}/report`,
		)
		expect(report.status, report.text).toBe(200)
		expect(report.json?.cost?.totalEur).toBeNull()
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The report page — a card on the Reports page (ADR-112), never a menu
 * entry of its own, reachable by route name and by path.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Campaign report page', () => {
	// @e2e openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#the-report-page-renders-from-one-response
	test('the Reports page offers a Campaign report card that opens the report', async ({
		page,
	}) => {
		await gotoRoute(page, '/reports')
		const card = page.getByText('Campaign report', { exact: false }).first()
		await expect(card).toBeVisible({ timeout: 15000 })
		await card.click()

		await expect(page).toHaveURL(/\/reports\/campaign/, { timeout: 15000 })
		await expect(page.getByTestId('campaign-report-body')).toBeVisible({
			timeout: 15000,
		})
		await assertNoHardError(page)
	})

	// @e2e marketing-campaigns::the-report-page-shows-reach-clicks-submissions-leads-and-cost
	test('the report shows reach, clicks, submissions, leads, value and cost', async ({
		page,
	}) => {
		await gotoRoute(page, '/reports/campaign')

		const tiles = page.getByTestId('campaign-report-tiles')
		await expect(tiles).toBeVisible({ timeout: 15000 })
		for (const label of [
			'Clicks',
			'Submissions',
			'Leads',
			'Attributed value',
			'Cost',
		]) {
			await expect(tiles).toContainText(label)
		}

		await expect(page.getByTestId('campaign-report-channels')).toBeVisible()
		await expect(page.getByTestId('campaign-report-model-rows')).toBeVisible()
		await expect(page.getByTestId('campaign-report-leads')).toBeVisible()
		await assertNoHardError(page)
	})

	// @e2e marketing-campaigns::the-report-page-has-no-navigation-entry-of-its-own
	test('the report has no Marketing menu entry of its own (ADR-112)', async ({
		page,
	}) => {
		await openApp(page)
		await revealNavEntry(page, 'Campaigns')

		const entries = page.locator(
			'#app-navigation-vue a.app-navigation-entry-link[href*="/apps/pipelinq/"]',
		)
		await expect(entries.filter({ hasText: /^Campaign report$/ })).toHaveCount(0)
	})
})
