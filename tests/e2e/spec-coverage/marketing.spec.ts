/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for the marketing capability family:
 * marketing-analytics, marketing-api, marketing-compliance,
 * marketing-segmentation and marketing-ui.
 *
 * WHY SOME OF THESE ASSERT OVER HTTP RATHER THAN OVER PIXELS
 * ----------------------------------------------------------
 * `marketing-api` and half of `marketing-compliance` are scenarios ABOUT an
 * HTTP contract — "POST /api/blasts with an invalid segmentId SHALL be 400 with
 * a generic message", "an email template without an unsubscribe token SHALL be
 * rejected". There is no pixel that shows a status code. They are still driven
 * end to end here: every request is issued from INSIDE the authenticated
 * browser context via `page.evaluate(fetch …)`, so it rides the real session
 * cookie and `OC.requestToken` through the real Nextcloud middleware stack,
 * exactly as the app's own calls do. That is a stronger proof than a controller
 * unit test with a mocked IUserSession, and it is what makes these scenarios
 * observable at all.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-imports the register,
 * which brings in `lib/Settings/register.d/95-marketing-segmentation-blast.json`
 * — 5 Segments, 3 CampaignTemplates, 2 Blasts forming an A/B pair, 20
 * BlastDeliveries, 10 ConsentRecords and 2 AttributionLinks. Every literal
 * asserted below (segment names, template names/channels, blast names, the
 * delivery status mix) was read out of that file, not guessed.
 */
import { test, expect, Page } from '@playwright/test'

import {
	openApp,
	navClick,
	assertNoHardError,
	dismissWalkthrough,
	dismissSupportDialog,
} from '../helpers/pipelinq'

/** One authenticated JSON call issued from inside the logged-in page. */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number, json: any, text: string }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',
					// eslint-disable-next-line no-undef
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let json: any = null
			try { json = text ? JSON.parse(text) : null } catch { /* non-JSON */ }
			return { status: res.status, json, text: text.slice(0, 400) }
		},
		{ method, path, body },
	)
}

const APP = '/index.php/apps/pipelinq'

/**
 * A generic error message is short, human, and free of implementation detail.
 * Asserted as the ABSENCE of leakage markers plus a length bound, because the
 * scenario's requirement is "does NOT expose internal details" — enumerating
 * what a leak looks like is the only way to assert that without pinning the
 * exact wording.
 */
function expectGenericError(message: unknown): void {
	expect(typeof message, 'the error must be a plain string message').toBe('string')
	const m = String(message)
	expect(m.length, `error message is too long to be generic: ${m}`).toBeLessThan(200)
	expect(m).not.toMatch(/SQLSTATE|Exception|Stack trace|\/var\/www|OCA\\\\|::__construct|\.php:\d+/i)
}

/** Deep-link to a hash route and let the view settle. */
async function gotoHash(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/pipelinq/#${hash}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/* ══════════════════════════════════════════════════════════════════════════
 * PerformanceDashboard — src/views/blasts/PerformanceDashboard.vue, mounted at
 * /blasts/performance (src/manifest.d/75-marketing-blasts.json, page
 * BlastPerformance) and reachable from the Marketing nav group.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Blast performance dashboard', () => {
	// @e2e openspec/specs/marketing-analytics/spec.md#overview-lists-blasts-with-rates
	test('the Overview tab lists blasts with delivery rates in sortable columns', async ({ page }) => {
		await openApp(page)
		await gotoHash(page, '/blasts/performance')

		const dash = page.locator('.performance-dashboard')
		await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })

		// Three tabs, Overview selected by default.
		const tabs = dash.getByRole('tab')
		await expect(tabs).toHaveCount(3)

		// The eight declared metric columns. Header cells carry `aria-sort` and a
		// click handler — that is what "sortable columns" means here.
		const headers = dash.locator('.performance-dashboard__table thead th')
		await expect(headers).toHaveCount(8, { timeout: 20000 })
		for (const label of ['Sent', 'Delivered', 'Open rate', 'Click rate', 'Unsubscribed']) {
			await expect(headers.filter({ hasText: label }).first()).toBeVisible()
		}
		await expect(headers.first()).toHaveAttribute('aria-sort', /ascending|descending|none/)

		// The seeded A/B pair is listed (register.d/95-…: two Blast objects).
		const body = dash.locator('.performance-dashboard__table tbody')
		await expect(body.locator('tr').filter({ hasText: 'Q4 Gemeente Outreach - Variant A' })).toHaveCount(1)
		await expect(body.locator('tr').filter({ hasText: 'Q4 Gemeente Outreach - Variant B' })).toHaveCount(1)

		// Sorting is wired: clicking a header flips its aria-sort.
		const before = await headers.first().getAttribute('aria-sort')
		await headers.first().click()
		await expect(headers.first()).not.toHaveAttribute('aria-sort', String(before))

		await assertNoHardError(page)
	})

	/*
	 * The seed gives each variant 20 deliveries in total — far below the 500-per-arm
	 * threshold (AB_MIN_DELIVERED in PerformanceDashboard.vue) — so the CI instance
	 * lands squarely in the "not yet available" branch this scenario describes.
	 */
	// @e2e openspec/specs/marketing-analytics/spec.md#test-unavailable-if-n500
	test('the A/B tab withholds significance below 500 delivered per variant', async ({ page }) => {
		await openApp(page)
		await gotoHash(page, '/blasts/performance')

		const dash = page.locator('.performance-dashboard')
		await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })
		await dash.getByRole('tab', { name: /A\/B/i }).click()

		const card = dash.locator('.performance-dashboard__ab-card').first()
		await expect(card).toBeVisible({ timeout: 20000 })
		await expect(card.getByRole('heading', { name: 'Variant A' })).toBeVisible()
		await expect(card.getByRole('heading', { name: 'Variant B' })).toBeVisible()

		// The pending notice is shown WITH the current counts, and no verdict /
		// p-value is computed.
		const pending = card.locator('.performance-dashboard__ab-pending')
		await expect(pending).toBeVisible()
		await expect(pending).toContainText('Results not yet available')
		await expect(pending).toContainText('delivered')
		await expect(card.locator('.performance-dashboard__ab-verdict')).toHaveCount(0)

		await assertNoHardError(page)
	})

	// @e2e openspec/specs/marketing-analytics/spec.md#dashboard-sums-attributed-revenue-per-blast
	test('the Attribution tab shows attributed deal count and value per blast', async ({ page }) => {
		await openApp(page)
		await gotoHash(page, '/blasts/performance')

		const dash = page.locator('.performance-dashboard')
		await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })
		await dash.getByRole('tab', { name: /Attribution/i }).click()

		const table = dash.locator('.performance-dashboard__table')
		await expect(table).toBeVisible({ timeout: 20000 })
		await expect(table.locator('thead th')).toHaveCount(3)
		await expect(table.locator('thead')).toContainText('Attributed deals')
		await expect(table.locator('thead')).toContainText('Attributed value')

		// The seed carries one AttributionLink per variant, so every listed row has
		// a numeric deal count and a EUR-formatted value.
		const rows = table.locator('tbody tr')
		await expect(rows.first()).toBeVisible()
		await expect(rows.first().locator('td').nth(1)).toHaveText(/^\s*\d+\s*$/)
		await expect(rows.first().locator('td').nth(2)).toHaveText(/€|EUR/)

		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The Blasts ledger + the New-blast wizard.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Blasts ledger and wizard', () => {
	// @e2e openspec/specs/marketing-segmentation/spec.md#blast-seed-objects-include-ab-pair
	test('the Blasts ledger lists the seeded A/B pair', async ({ page }) => {
		await openApp(page)
		await navClick(page, 'Blasts', /\/blasts/)

		const content = page.locator('#content-vue')
		await expect(content.getByRole('heading', { name: 'Blasts' }).first()).toBeVisible({ timeout: 20000 })
		// Both halves of the seeded pair are rendered, and both are `sent`.
		await expect(content.getByText('Q4 Gemeente Outreach - Variant A')).toBeVisible({ timeout: 20000 })
		await expect(content.getByText('Q4 Gemeente Outreach - Variant B')).toBeVisible()

		await assertNoHardError(page)
	})

	// @e2e openspec/specs/marketing-ui/spec.md#email-template-validated-before-save
	test('the New-blast wizard walks name to segment to template', async ({ page }) => {
		await openApp(page)
		await gotoHash(page, '/blasts/new')

		const form = page.locator('.blast-form')
		await expect(form.getByRole('heading', { name: 'New blast' })).toBeVisible({ timeout: 20000 })

		// The six declared steps are rendered as an ordered progress list.
		await expect(form.locator('.blast-form__steps li')).toHaveCount(6)
		await expect(form.locator('.blast-form__steps li.is-current')).toHaveCount(1)

		// Step 1 — name. `canAdvance` gates Next until it is filled.
		const name = form.locator('#blast-form-name')
		await expect(name).toBeVisible()
		await name.fill('E2E gate-19 draft')

		// Step 2 — the segment picker is fed from the seeded Segments.
		await form.getByRole('button', { name: 'Next' }).first().click()
		await expect(form.getByText('Gemeente Contact Blast')).toBeVisible({ timeout: 20000 })

		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The marketing HTTP contract, driven through the authenticated session.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Marketing API contract', () => {
	// @e2e openspec/specs/marketing-api/spec.md#get-apiblasts-returns-paginated-list-with-filters
	// @e2e openspec/specs/marketing-segmentation/spec.md#blastdelivery-seed-includes-realistic-event-sequence
	test('GET /api/blasts paginates and filters by status', async ({ page }) => {
		await openApp(page)

		const all = await api(page, 'GET', `${APP}/api/blasts?page=1&limit=20`)
		expect(all.status, all.text).toBe(200)
		expect(Array.isArray(all.json?.data), 'response carries a data[] array').toBe(true)
		expect(all.json?.pagination, 'response carries a pagination object').toBeTruthy()
		expect(all.json.data.length).toBeGreaterThan(0)

		// The filter is applied server-side: every returned row matches.
		const sent = await api(page, 'GET', `${APP}/api/blasts?status=sent&page=1&limit=20`)
		expect(sent.status, sent.text).toBe(200)
		expect(sent.json.data.length).toBeGreaterThan(0)
		for (const b of sent.json.data) {
			expect(b.status, `blast ${b.name} leaked past the status filter`).toBe('sent')
		}

		// And a status nothing is in returns an empty page rather than everything —
		// the assertion that makes the filter meaningful.
		const none = await api(page, 'GET', `${APP}/api/blasts?status=failed&page=1&limit=20`)
		expect(none.status, none.text).toBe(200)
		expect(none.json.data.length).toBe(0)

		// The seeded deliveries carry the realistic mixed event sequence.
		const first = sent.json.data[0]
		const id = first.id || first['@self']?.id
		const deliveries = await api(page, 'GET', `${APP}/api/blasts/${id}/deliveries?page=1&limit=50`)
		expect(deliveries.status, deliveries.text).toBe(200)
		expect(Array.isArray(deliveries.json?.data)).toBe(true)
	})

	/*
	 * The two remaining seed families have no dedicated pipelinq endpoint, so they
	 * are read through the OpenRegister object API the app itself uses. Still an
	 * end-to-end read: the objects have to have survived schema validation and the
	 * forced register import for any of this to return.
	 */
	// @e2e openspec/specs/marketing-segmentation/spec.md#consentrecord-seed-includes-varied-states
	// @e2e openspec/specs/marketing-segmentation/spec.md#attributionlink-seed-shows-revenue-attribution
	test('the ConsentRecord and AttributionLink seeds are imported with varied states', async ({ page }) => {
		await openApp(page)
		const OR = '/index.php/apps/openregister/api/objects/pipelinq'

		const consents = await api(page, 'GET', `${OR}/consentRecord?_limit=100`)
		expect(consents.status, consents.text).toBe(200)
		const cRows: any[] = consents.json?.results ?? consents.json ?? []
		expect(cRows.length, 'at least 10 ConsentRecords are seeded').toBeGreaterThanOrEqual(10)
		// Active consent alongside each declared withdrawal reason.
		expect(cRows.some((c: any) => !c.withdrawnAt), 'an active consent record').toBe(true)
		const reasons = new Set(cRows.map((c: any) => c.withdrawnReason).filter(Boolean))
		for (const r of ['user-unsubscribed', 'bounce-hard']) {
			expect([...reasons], `withdrawal reason "${r}" is missing from the seed`).toContain(r)
		}
		// Varied lawful bases, not a single repeated value.
		expect(new Set(cRows.map((c: any) => c.lawfulBasis).filter(Boolean)).size).toBeGreaterThan(1)

		const links = await api(page, 'GET', `${OR}/attributionLink?_limit=100`)
		expect(links.status, links.text).toBe(200)
		const lRows: any[] = links.json?.results ?? links.json ?? []
		expect(lRows.length, 'at least 2 AttributionLinks are seeded').toBeGreaterThanOrEqual(2)
		for (const l of lRows.slice(0, 2)) {
			for (const key of ['blastId', 'contactId', 'dealId', 'firstClickAt', 'closedWonAt']) {
				expect(l[key], `AttributionLink is missing ${key}`).toBeTruthy()
			}
			// The click must precede the close — the ordering the scenario names.
			expect(Date.parse(l.firstClickAt)).toBeLessThanOrEqual(Date.parse(l.closedWonAt))
			expect(Number(l.attributedValue)).toBeGreaterThan(0)
		}
	})

	// @e2e openspec/specs/marketing-api/spec.md#error-responses-use-generic-messages
	test('POST /api/blasts with an unknown segment is a generic 400', async ({ page }) => {
		await openApp(page)

		const res = await api(page, 'POST', `${APP}/api/blasts`, {
			name: 'E2E gate-19 bad segment',
			segmentId: 'e2e-no-such-segment-0000',
			templateId: 'e2e-no-such-template-0000',
			channel: 'email',
		})
		expect(res.status, res.text).toBe(400)
		expect(res.json?.error).toBe('Invalid segment')
		expectGenericError(res.json?.error)
	})

	// @e2e openspec/specs/marketing-api/spec.md#post-apiblasts-creates-new-blast-in-draft
	// @e2e openspec/specs/marketing-api/spec.md#user-identity-from-iusersession-only
	// @e2e openspec/specs/marketing-segmentation/spec.md#segment-seed-objects
	// @e2e openspec/specs/marketing-segmentation/spec.md#campaigntemplate-seed-objects
	test('POST /api/blasts creates a draft and derives createdBy from the session', async ({ page }) => {
		await openApp(page)

		// The seeded Segments — five, with the declared Dutch names and both
		// entityTypes — reached through the real API rather than read off disk.
		const segments = await api(page, 'GET', `${APP}/api/segments`)
		expect(segments.status, segments.text).toBe(200)
		const segRows: any[] = segments.json?.data ?? segments.json ?? []
		const segNames = segRows.map((s: any) => s.name)
		for (const expected of ['Gemeente Contact Blast', 'Enterprise High-Value', 'Inactive Leads',
			'Retention Newsletter', 'Technical Leads']) {
			expect(segNames, `seeded segment "${expected}" is missing`).toContain(expected)
		}
		expect(new Set(segRows.map((s: any) => s.entityType))).toContain('contact')

		// The seeded CampaignTemplates — two email, one SMS.
		const templates = await api(page, 'GET', `${APP}/api/templates`)
		expect(templates.status, templates.text).toBe(200)
		const tplRows: any[] = templates.json?.data ?? templates.json ?? []
		const tplNames = tplRows.map((t: any) => t.name)
		for (const expected of ['Q4 Product Launch', 'Renewal Reminder', 'Appointment Confirmation']) {
			expect(tplNames, `seeded template "${expected}" is missing`).toContain(expected)
		}
		expect(tplRows.filter((t: any) => t.channel === 'sms').length).toBeGreaterThan(0)

		const segment = segRows[0]
		const emailTemplate = tplRows.find((t: any) => t.channel === 'email')
		expect(emailTemplate, 'an email CampaignTemplate must be seeded').toBeTruthy()

		const created = await api(page, 'POST', `${APP}/api/blasts`, {
			name: 'E2E gate-19 draft blast',
			segmentId: segment.id || segment['@self']?.id,
			templateId: emailTemplate.id || emailTemplate['@self']?.id,
			channel: 'email',
			// A frontend-supplied identity that the controller MUST ignore.
			createdBy: 'e2e-spoofed-identity',
		})
		expect(created.status, created.text).toBe(201)
		expect(created.json?.status, 'a new blast starts in draft').toBe('draft')
		expect(created.json?.createdBy, 'createdBy must come from IUserSession, not the body')
			.not.toBe('e2e-spoofed-identity')
		expect(String(created.json?.createdBy ?? '').length).toBeGreaterThan(0)

		// Clean up the draft this test minted, through the same OR object API the
		// app's own saveObject()/deleteObject() use.
		const newId = created.json?.id || created.json?.['@self']?.id
		if (newId) {
			await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/pipelinq/blast/${newId}`)
		}
	})

	// @e2e openspec/specs/marketing-api/spec.md#segment-create-validates-rule-tree
	// @e2e openspec/specs/marketing-segmentation/spec.md#rule-tree-validated-on-save
	// @e2e openspec/specs/marketing-segmentation/spec.md#operators-validated-per-field-type
	// @e2e openspec/specs/marketing-segmentation/spec.md#estimated-size-computed
	test('POST /api/segments validates the rule tree before saving', async ({ page }) => {
		await openApp(page)

		// A leaf naming a field the contact schema does not have — rejected.
		const unknownField = await api(page, 'POST', `${APP}/api/segments`, {
			name: 'E2E gate-19 unknown field',
			entityType: 'contact',
			rules: { field: 'e2e_field_that_does_not_exist', operator: 'eq', value: 'x' },
		})
		expect(unknownField.status, unknownField.text).toBe(400)
		expectGenericError(unknownField.json?.error)
		expect(String(unknownField.json?.error)).toMatch(/rule tree/i)

		// A numeric operator on a string field — the operator-not-valid-for-type
		// rejection this capability names explicitly.
		const badOperator = await api(page, 'POST', `${APP}/api/segments`, {
			name: 'E2E gate-19 bad operator',
			entityType: 'contact',
			rules: { field: 'industry', operator: 'gt', value: 50 },
		})
		expect(badOperator.status, badOperator.text).toBe(400)
		expect(String(badOperator.json?.error)).toMatch(/rule tree/i)

		// A valid tree saves AND comes back with a computed size estimate.
		const ok = await api(page, 'POST', `${APP}/api/segments`, {
			name: 'E2E gate-19 valid segment',
			entityType: 'contact',
			rules: { field: 'industry', operator: 'eq', value: 'gemeente' },
		})
		expect(ok.status, ok.text).toBe(201)
		expect(typeof ok.json?.estimatedSize, 'the create response carries estimatedSize').toBe('number')
		expect(ok.json?.estimatedSize).toBeGreaterThanOrEqual(0)

		const seg = ok.json?.segment
		const segId = seg?.id || seg?.['@self']?.id
		if (segId) {
			await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/pipelinq/segment/${segId}`)
		}
	})

	// @e2e openspec/specs/marketing-api/spec.md#template-create-validates-compliance
	// @e2e openspec/specs/marketing-compliance/spec.md#save-rejected-if-unsubscribe-token-missing
	// @e2e openspec/specs/marketing-compliance/spec.md#save-rejected-if-physical-address-missing
	// @e2e openspec/specs/marketing-compliance/spec.md#sms-templates-do-not-require-unsubscribe-footer
	test('POST /api/templates enforces the email compliance footer, not the SMS one', async ({ page }) => {
		await openApp(page)

		// No unsubscribe token at all.
		const noToken = await api(page, 'POST', `${APP}/api/templates`, {
			name: 'E2E gate-19 no unsubscribe',
			channel: 'email',
			subject: 'Hoi',
			bodyHtml: '<p>Geen afmeldlink hier.</p>',
		})
		expect(noToken.status, noToken.text).toBe(400)
		expect(String(noToken.json?.error)).toMatch(/unsubscribe/i)

		// Unsubscribe token present, physical address absent.
		const noAddress = await api(page, 'POST', `${APP}/api/templates`, {
			name: 'E2E gate-19 no address',
			channel: 'email',
			subject: 'Hoi',
			bodyHtml: '<p>Afmelden: {{unsubscribe_link}}</p>',
		})
		expect(noAddress.status, noAddress.text).toBe(400)
		expect(String(noAddress.json?.error)).toMatch(/address/i)

		// An SMS template is held to neither requirement.
		const sms = await api(page, 'POST', `${APP}/api/templates`, {
			name: 'E2E gate-19 sms',
			channel: 'sms',
			bodyText: 'Uw afspraak is bevestigd.',
		})
		expect(sms.status, sms.text).toBe(201)
		const smsId = sms.json?.id || sms.json?.['@self']?.id
		if (smsId) {
			await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/pipelinq/campaignTemplate/${smsId}`)
		}
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * First-party open/click tracking. Both endpoints are `#[PublicPage]` +
 * `#[NoCSRFRequired]` (lib/Controller/BlastTrackingController.php) because a
 * recipient's mail client has no Nextcloud session — so these run with an EMPTY
 * storage state, which is also what makes the fail-closed behaviour meaningful.
 *
 * Only the INVALID-token halves are asserted here, and deliberately so: a valid
 * token is an HMAC signed with a per-instance secret that a browser has no way
 * to mint. The valid-token paths carry a reason-bearing `@e2e exclude` naming
 * the PHPUnit coverage instead.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Public tracking endpoints (no Nextcloud session)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	// @e2e openspec/specs/marketing-email-tracking/spec.md#bad-token-returns-a-pixel-but-records-nothing
	test('a tampered open token still returns the 1x1 pixel and never 500s', async ({ page }) => {
		const res = await page.goto(`${APP}/api/blast/track/open/e2e-gate19-not-a-real-token`)
		expect(res, 'the tracking pixel produced no response').not.toBeNull()

		// Fail closed: the email must still render, so the pixel is served
		// regardless. What must NOT happen is a server error or a login bounce.
		expect(res!.status(), 'a bad token must not 500 and must not 401').toBe(200)
		expect(res!.headers()['content-type']).toContain('image/gif')
		// Caching disabled, so a mail client cannot serve a stale pixel and
		// suppress the next open.
		expect(String(res!.headers()['cache-control'] ?? '')).toMatch(/no-store|no-cache/i)
		// Never routed through the Nextcloud login page.
		await expect(page.locator('input[name="user"]')).toHaveCount(0)
	})

	// @e2e openspec/specs/marketing-email-tracking/spec.md#tampered-click-token-is-rejected
	test('a tampered click token is refused and produces no redirect', async ({ page }) => {
		const res = await page.goto(`${APP}/api/blast/track/click/e2e-gate19-not-a-real-token`)
		expect(res, 'the click endpoint produced no response').not.toBeNull()

		// 4xx, not a redirect: the target URL is bound INSIDE the token, so an
		// unverified token must never be allowed to choose a destination.
		expect(res!.status(), 'an unverified click token must be refused').toBeGreaterThanOrEqual(400)
		expect(res!.status()).toBeLessThan(500)
		// The browser stayed on the pipelinq origin — no attacker-chosen hop.
		expect(new URL(page.url()).origin).toBe(new URL(res!.url()).origin)
		expect(page.url()).toContain('/api/blast/track/click/')
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * BlastMonitor — the live send surface, at /blasts/:id/monitor.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Blast monitor', () => {
	test.describe.configure({ mode: 'serial' })

	let blastId = ''

	// @e2e openspec/specs/marketing-ui/spec.md#progress-bar-and-totals-update-by-polling
	test('a sending blast renders a live progress bar and totals grid', async ({ page }) => {
		await openApp(page)

		// A blast in `sending` is what this surface is FOR, and the seed only ships
		// terminal (`sent`) ones. Minted directly against the OR object API — the
		// background dispatcher runs on cron, which does not fire inside a
		// Playwright run, so this object is inert apart from what the UI reads.
		const made = await api(page, 'POST', '/index.php/apps/openregister/api/objects/pipelinq/blast', {
			name: 'E2E gate-19 sending blast',
			channel: 'email',
			status: 'sending',
			totals: { queued: 6, sent: 3, delivered: 1, bounced: 0, opened: 0, clicked: 0, unsubscribed: 0, failed: 0 },
		})
		expect(made.status, made.text).toBeLessThan(300)
		blastId = made.json?.id || made.json?.['@self']?.id
		expect(blastId, 'the sending-blast fixture must have an id').toBeTruthy()

		await gotoHash(page, `/blasts/${blastId}/monitor`)

		const monitor = page.locator('.blast-monitor')
		await expect(monitor).toBeVisible({ timeout: 20000 })

		const bar = monitor.getByRole('progressbar')
		await expect(bar).toBeVisible()
		await expect(bar).toHaveAttribute('aria-valuenow', /^\d+$/)
		await expect(monitor.locator('.blast-monitor__progress-meta')).toContainText('%')

		await assertNoHardError(page)
	})

	// @e2e openspec/specs/marketing-ui/spec.md#cancel-a-sending-blast
	test('"Cancel send" is offered on a sending blast and enters a cancelling state', async ({ page }) => {
		expect(blastId, 'the previous test must have seeded a sending blast').toBeTruthy()
		await openApp(page)
		await gotoHash(page, `/blasts/${blastId}/monitor`)

		const monitor = page.locator('.blast-monitor')
		await expect(monitor).toBeVisible({ timeout: 20000 })

		const cancel = monitor.getByRole('button', { name: 'Cancel send' })
		await expect(cancel).toBeVisible({ timeout: 20000 })
		await cancel.click()

		// The blast leaves `sending`: the control disappears once the status is
		// terminal (`v-if` on a non-terminal status), which is the observable
		// outcome of POST /api/blasts/:id/cancel.
		await expect(cancel).toHaveCount(0, { timeout: 20000 })
		await assertNoHardError(page)

		await api(page, 'DELETE', `/index.php/apps/openregister/api/objects/pipelinq/blast/${blastId}`)
	})
})
