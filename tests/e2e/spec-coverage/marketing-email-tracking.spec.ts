/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for marketing-email-tracking, the
 * Portaliq traffic dual-write half.
 *
 * WHAT THIS PROVES, AND WHAT IT CANNOT
 * ------------------------------------
 * `blast.traffic_portal` is an admin tunable written through the same
 * `PUT /api/settings` the admin page uses. Once it names a portal, every
 * recorded open or click is reported to Portaliq's ingest service, IF that
 * service is loadable. The CI instance installs openregister only
 * (.github/workflows/code-quality.yml pins `additional-apps`), so here the
 * probe answers "not installed" and the report is skipped. That skip path is
 * exactly what this file drives end to end: with a portal configured and no
 * Portaliq, the open pixel must still answer 200 `image/gif`, never 500, and
 * an unverified token must leave the seeded delivery untouched.
 *
 * The RECORDING half (a valid token writing `openedAt` and then one
 * `email_open` reaching `ingest()`) needs an HMAC token signed with a
 * per-instance secret that no browser can read or mint, plus Portaliq
 * present. Both are asserted by PHPUnit (tests/Unit/Service/
 * TrafficEventEmitterTest.php and TrackingLinkServiceTest.php) and the
 * matching scenarios carry an `@e2e exclude` saying so.
 *
 * Every settings call rides the real admin session through
 * `page.evaluate(fetch ...)`, as marketing.spec.ts does.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const PORTAL_KEY = 'blast.traffic_portal'
const PORTAL = 'e2e-gate19-portal'

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

/** Write one settings key and read it back through the same API. */
async function setPortal(page: Page, value: string): Promise<void> {
	const put = await api(page, 'PUT', `${APP}/api/settings`, {
		[PORTAL_KEY]: value,
	})
	expect(put.status, put.text).toBe(200)
	expect(
		put.json?.config?.[PORTAL_KEY],
		'PUT /api/settings must echo the key',
	).toBe(value)
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

test.describe('Mail opens and clicks reported to Portaliq traffic', () => {
	test.afterEach(async ({ page }) => {
		// Leave the instance as found: an empty portal keeps mail tracking
		// inside Pipelinq, which is the shipped default.
		await landOnApp(page)
		await setPortal(page, '')
	})

	// @e2e openspec/specs/marketing-email-tracking/spec.md#portaliq-absent-skips-the-report-and-the-pixel-still-answers
	test('with a portal configured and no Portaliq, the pixel still answers and an unverified token records nothing', async ({
		page,
	}) => {
		await landOnApp(page)

		// The tunable round-trips through the settings API the admin page uses.
		await setPortal(page, PORTAL)
		const read = await api(page, 'GET', `${APP}/api/settings`)
		expect(read.status, read.text).toBe(200)
		expect(read.json?.config?.[PORTAL_KEY], read.text).toBe(PORTAL)

		// One blast and one delivered-but-unopened delivery to watch.
		const { segmentId, templateId } = await seededFks(page)
		const blast = await api(page, 'POST', `${OR}/blast`, {
			name: 'E2E traffic dual-write blast',
			segmentId,
			templateId,
			channel: 'email',
			status: 'sent',
		})
		expect(blast.status, blast.text).toBeLessThan(300)
		const blastId = idOf(blast.json)
		expect(blastId, 'the minted blast must have an id').toBeTruthy()

		const delivery = await api(page, 'POST', `${OR}/blastDelivery`, {
			blastId,
			contactId: 'e2e-gate19-contact',
			status: 'delivered',
		})
		expect(delivery.status, delivery.text).toBeLessThan(300)
		const deliveryId = idOf(delivery.json)
		expect(deliveryId, 'the minted delivery must have an id').toBeTruthy()

		// The pixel with the portal configured: still a GIF, never a 500.
		// `page.request` carries no Nextcloud session state that matters here;
		// the route is `#[PublicPage]` either way.
		const pixel = await page.request.get(
			`${APP}/api/blast/track/open/e2e-gate19-not-a-real-token`,
		)
		expect(
			pixel.status(),
			'a bad token must not 500 with a portal configured',
		).toBe(200)
		expect(pixel.headers()['content-type']).toContain('image/gif')
		expect(String(pixel.headers()['cache-control'] ?? '')).toMatch(
			/no-store|no-cache/i,
		)

		// The unverified hit recorded nothing: no openedAt, status unchanged.
		const after = await api(page, 'GET', `${OR}/blastDelivery/${deliveryId}`)
		expect(after.status, after.text).toBe(200)
		expect(after.json?.status).toBe('delivered')
		expect(after.json?.openedAt ?? null).toBeFalsy()

		// A click with an unverified token is refused the same way, portal or not.
		const click = await page.request.get(
			`${APP}/api/blast/track/click/e2e-gate19-not-a-real-token`,
			{ maxRedirects: 0 },
		)
		expect(click.status()).toBeGreaterThanOrEqual(400)
		expect(click.status()).toBeLessThan(500)
	})
})
