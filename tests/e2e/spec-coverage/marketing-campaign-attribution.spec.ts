/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for marketing-campaign-attribution,
 * phase 2 of the fleet traffic analytics programme.
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT
 * ------------------------------------
 * Four things are observable on the CI instance, which has OpenRegister and
 * Pipelinq but neither Portaliq nor a way to reach Google:
 *
 *   1. the admin settings page shows the Marketing traffic section with the
 *      campaign parameters switch and the Search Console fields;
 *   2. a service account key written through the settings API is never
 *      echoed back, only reported as "set";
 *   3. the campaign value a blast's links carry derives from its name, read
 *      through GET /api/blasts/{id}/performance, and with no portal
 *      configured that endpoint and the blast performance page both say the
 *      site half is not connected;
 *   4. the Search queries page renders its empty state.
 *
 * The decoration of a sent body happens inside the mail handed to
 * openconnector (not installed here), the site sessions need Portaliq's
 * rollups (not installed here), and the import needs Google (not reachable
 * here). Those scenarios carry `@e2e exclude` in the spec and are asserted
 * by PHPUnit.
 *
 * Every settings call rides the real admin session through
 * `page.evaluate(fetch ...)`, as marketing-email-tracking.spec.ts does.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const PORTAL_KEY = 'blast.traffic_portal'
const UTM_KEY = 'blast.utm_auto'
const SECRET_KEY = 'search.gsc.service_account_key'

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
			return { status: res.status, json, text: text.slice(0, 400) }
		},
		{ method, path, body },
	)
}

/** Read the id off an OpenRegister object or a pipelinq API row. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** Land on the app so `OC.requestToken` exists for the fetch helper. */
async function landOnApp(page: Page): Promise<void> {
	await page.goto(`/apps/pipelinq`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/** Deep-link to an app route and let the view settle. */
async function gotoRoute(page: Page, route: string): Promise<void> {
	await page.goto(`/apps/pipelinq${route}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/** Write settings keys and read them back through the same API. */
async function putSettings(page: Page, data: Record<string, string>): Promise<any> {
	const put = await api(page, 'PUT', `${APP}/api/settings`, data)
	expect(put.status, put.text).toBe(200)
	return put.json?.config ?? {}
}

/**
 * The seeded Segment + CampaignTemplate a Blast fixture has to point at:
 * `blast` declares `required: [name, segmentId, templateId, channel]`.
 */
async function seededFks(
	page: Page,
): Promise<{ segmentId: string; templateId: string }> {
	const segments = await api(page, 'GET', `${APP}/api/segments`)
	expect(segments.status, segments.text).toBe(200)
	const segRows: any[] = segments.json?.data ?? segments.json ?? []
	const segmentId = idOf(segRows[0])
	expect(segmentId, 'a seeded Segment id is required to mint a Blast').toBeTruthy()

	const templates = await api(page, 'GET', `${APP}/api/templates`)
	expect(templates.status, templates.text).toBe(200)
	const tplRows: any[] = templates.json?.data ?? templates.json ?? []
	const email = tplRows.find((t: any) => t.channel === 'email') ?? tplRows[0]
	const templateId = idOf(email)
	expect(
		templateId,
		'a seeded CampaignTemplate id is required to mint a Blast',
	).toBeTruthy()

	return { segmentId, templateId }
}

test.describe('Marketing traffic settings', () => {
	test.afterEach(async ({ page }) => {
		// Leave the instance as found: parameters on, no portal, no key.
		await landOnApp(page)
		await putSettings(page, {
			[UTM_KEY]: 'true',
			[PORTAL_KEY]: '',
			[`${SECRET_KEY}_clear`]: 'true',
		})
	})

	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#admin-settings-show-the-utm-toggle-and-search-console-fields
	test('the admin settings page shows the campaign parameters switch and the Search Console fields', async ({
		page,
	}) => {
		await page.goto('/settings/admin/pipelinq')
		await expect(page.getByTestId('marketing-utm-auto')).toBeVisible({
			timeout: 20000,
		})
		await expect(page.getByTestId('marketing-traffic-portal')).toBeVisible()
		await expect(page.getByTestId('marketing-gsc-properties')).toBeVisible()
		await expect(page.getByTestId('marketing-gsc-key')).toBeVisible()
		await expect(page.getByTestId('marketing-traffic-save')).toBeVisible()
		// The switch reflects the shipped default: on. NcCheckboxRadioSwitch
		// forwards non-prop attributes to its <input>, so the test id may sit
		// on the input itself or on a wrapper around it.
		await expect(
			page
				.locator(
					'input[type="checkbox"][data-testid="marketing-utm-auto"], [data-testid="marketing-utm-auto"] input[type="checkbox"]',
				)
				.first(),
		).toBeChecked()
	})

	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#the-key-is-never-echoed-back
	test('a service account key written through the settings API is reported as set and never echoed back', async ({
		page,
	}) => {
		await landOnApp(page)

		const before = await api(page, 'GET', `${APP}/api/settings`)
		expect(before.status, before.text).toBe(200)
		expect(before.json?.config?.[`${SECRET_KEY}_set`]).toBe('false')
		expect(before.json?.config).not.toHaveProperty(SECRET_KEY)

		const fakeKey = JSON.stringify({
			type: 'service_account',
			client_email: 'e2e-gate19@example-project.iam.gserviceaccount.com',
			private_key:
				'-----BEGIN PRIVATE KEY-----\ne2e-not-a-real-key\n-----END PRIVATE KEY-----\n',
			token_uri: 'https://oauth2.googleapis.com/token',
		})
		const written = await putSettings(page, {
			[SECRET_KEY]: fakeKey,
			'search.gsc.properties': 'https://example.org/',
		})
		expect(written[`${SECRET_KEY}_set`]).toBe('true')
		expect(written).not.toHaveProperty(SECRET_KEY)
		expect(written['search.gsc.properties']).toBe('https://example.org/')

		const after = await api(page, 'GET', `${APP}/api/settings`)
		expect(after.status, after.text).toBe(200)
		expect(after.json?.config?.[`${SECRET_KEY}_set`]).toBe('true')
		expect(after.json?.config).not.toHaveProperty(SECRET_KEY)
		expect(after.text).not.toContain('e2e-not-a-real-key')

		// The read endpoint reports the email, never the key.
		const status = await api(
			page,
			'GET',
			`${APP}/api/marketing/search-queries?limit=1`,
		)
		expect(status.status, status.text).toBe(200)
		expect(status.json?.serviceAccountEmail).toBe(
			'e2e-gate19@example-project.iam.gserviceaccount.com',
		)
		expect(status.json?.configured).toBe(true)
		expect(status.text).not.toContain('e2e-not-a-real-key')

		// Clearing removes it again.
		const cleared = await putSettings(page, { [`${SECRET_KEY}_clear`]: 'true' })
		expect(cleared[`${SECRET_KEY}_set`]).toBe('false')
	})
})

test.describe('Campaign performance without a portal', () => {
	test.afterEach(async ({ page }) => {
		await landOnApp(page)
		await putSettings(page, { [PORTAL_KEY]: '' })
	})

	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#the-campaign-value-derives-from-the-blast-name
	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#no-portal-configured-reports-not-connected
	test('the performance read derives the campaign from the blast name and says the site half is not connected', async ({
		page,
	}) => {
		await landOnApp(page)
		await putSettings(page, { [PORTAL_KEY]: '' })

		const stamp = Date.now()
		const { segmentId, templateId } = await seededFks(page)
		const blast = await api(page, 'POST', `${OR}/blast`, {
			name: `Spring newsletter ${stamp}`,
			segmentId,
			templateId,
			channel: 'email',
			status: 'sent',
			totals: { sent: 3, delivered: 3, opened: 2, clicked: 1 },
		})
		expect(blast.status, blast.text).toBeLessThan(300)
		const blastId = idOf(blast.json)
		expect(blastId, 'the minted blast must have an id').toBeTruthy()

		const perf = await api(
			page,
			'GET',
			`${APP}/api/blasts/${blastId}/performance`,
		)
		expect(perf.status, perf.text).toBe(200)
		expect(perf.json?.blastId).toBe(blastId)
		expect(perf.json?.campaign).toBe(`spring-newsletter-${stamp}`)
		expect(perf.json?.connected).toBe(false)
		expect(perf.json?.reason).toBe('no_portal')
		expect(perf.json?.site).toBeNull()
		expect(perf.json?.email?.opened).toBe(2)
		expect(perf.json?.email?.clicked).toBe(1)
		expect(perf.json?.deals).toHaveProperty('dealCount')
		expect(perf.json?.window?.from).toMatch(/^\d{4}-\d{2}-\d{2}$/)

		// An explicit window is honoured.
		const windowed = await api(
			page,
			'GET',
			`${APP}/api/blasts/${blastId}/performance?from=2026-08-01&to=2026-08-31`,
		)
		expect(windowed.status, windowed.text).toBe(200)
		expect(windowed.json?.window).toEqual({
			from: '2026-08-01',
			to: '2026-08-31',
		})

		// An unknown blast is a 404, never a 500.
		const missing = await api(
			page,
			'GET',
			`${APP}/api/blasts/e2e-gate19-ghost/performance`,
		)
		expect(missing.status).toBe(404)
	})

	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#no-portal-configured-reports-not-connected
	test('the blast performance page says "Not connected to a portal" in the Attribution tab', async ({
		page,
	}) => {
		// The dashboard fans out one attribution request per seeded blast
		// before it paints; marketing.spec.ts waits 20 s for the same table,
		// and the throwaway rig is slower than CI.
		test.setTimeout(120000)
		await landOnApp(page)
		await putSettings(page, { [PORTAL_KEY]: '' })

		await gotoRoute(page, '/blasts/performance')
		const dash = page.locator('.performance-dashboard')
		await expect(dash).toBeVisible({ timeout: 15000 })
		await dash.getByRole('tab', { name: /Attribution/i }).click()

		const block = page.getByTestId('campaign-traffic')
		await expect(block).toBeVisible({ timeout: 60000 })
		await expect(block).toContainText('Site traffic from this campaign')
		await expect(page.getByTestId('campaign-traffic-unconnected')).toContainText(
			'Not connected to a portal',
			{ timeout: 60000 },
		)
	})
})

test.describe('Search queries page', () => {
	// @e2e openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#empty-state-without-data
	test('renders its empty state when nothing has been imported', async ({
		page,
	}) => {
		await gotoRoute(page, '/marketing/search-queries')
		await expect(
			page.getByRole('heading', { name: 'Search queries' }),
		).toBeVisible({
			timeout: 15000,
		})
		const empty = page.getByTestId('search-queries-empty')
		await expect(empty).toBeVisible({ timeout: 15000 })
		await expect(empty).toContainText('No search data yet')
		await expect(empty).toContainText('Search Console')
		await expect(empty).toContainText('Marketing traffic')
	})
})
