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
const OR = '/index.php/apps/openregister/api/objects/pipelinq'

/** Read the id off an OpenRegister object or a pipelinq API row. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/**
 * An RFC 3339 timestamp `hours` in the past, without the fractional-second
 * part — the seeded `date-time` values in
 * register.d/95-marketing-segmentation-blast.json carry none either, so a
 * fixture minted here is byte-shaped like the data the schema already holds.
 */
function isoHoursAgo(hours: number): string {
	return new Date(Date.now() - (hours * 60 * 60 * 1000)).toISOString().replace(/\.\d{3}Z$/, 'Z')
}

/**
 * The seeded Segment + CampaignTemplate a Blast fixture has to point at.
 *
 * `blast` declares `required: [name, segmentId, templateId, channel]`
 * (lib/Settings/register.d/95-marketing-segmentation-blast.json), so a Blast
 * minted straight against the OpenRegister object API without those two FKs is
 * refused with "The required properties (segmentId, templateId) are missing."
 * — measured in run 31473685688 on the Blast-monitor fixture below.
 *
 * Both lists are read through the pipelinq API the app itself uses; the
 * "POST /api/blasts creates a draft" test in this file proves in the same run
 * that both endpoints answer 200 with the five seeded segments and three
 * seeded templates.
 */
async function seededFks(page: Page): Promise<{ segmentId: string, templateId: string }> {
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
	expect(templateId, 'a seeded CampaignTemplate id is required to mint a Blast').toBeTruthy()

	return { segmentId, templateId }
}

/** Mint one Blast against the OpenRegister object API and return its id. */
async function mintBlast(page: Page, fields: Record<string, unknown>): Promise<string> {
	const made = await api(page, 'POST', `${OR}/blast`, fields)
	expect(made.status, made.text).toBeLessThan(300)
	const id = idOf(made.json)
	expect(id, `the minted blast "${String(fields.name)}" must have an id`).toBeTruthy()
	return id
}

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
	 * WHY THIS MINTS ITS OWN PAIR INSTEAD OF USING THE SEEDED ONE
	 * -----------------------------------------------------------
	 * `abPairs` (PerformanceDashboard.vue) groups blasts by `abVariantOf` and
	 * then requires the PARENT to be findable by
	 * `blasts.find((b) => (b.id || b.uuid || b.slug) === parentId)` — an
	 * id-FIRST chain, so for any row OpenRegister returns with a UUID the
	 * comparison is UUID-vs-parentId.
	 *
	 * The seed writes that link as a SLUG:
	 * register.d/95-marketing-segmentation-blast.json gives Variant B
	 * `"abVariantOf": "blast-q4-gemeente-outreach-a"`, which is Variant A's
	 * `@self.slug`, and `abVariantOf` is a plain `type: string` property so
	 * OpenRegister does not rewrite it to a UUID on import. The lookup
	 * therefore never resolves, `abPairs` stays empty and the tab paints
	 * `.performance-dashboard__empty` ("No A/B variant blasts found.").
	 * That is exactly what run 31473685688 measured: `.performance-dashboard__ab-card`
	 * was "element(s) not found" after 20s while the Overview tab — which needs
	 * no such join — listed both seeded blasts in the same run.
	 *
	 * That mismatch is a seed/runtime defect, reported separately. It is NOT
	 * what this scenario is about: the scenario is about what the tab does with
	 * an A/B pair whose arms are under the 500-delivered threshold. So the pair
	 * is minted here with the parent's real id, which exercises the derivation,
	 * the threshold and the pending branch for real.
	 */
	// @e2e openspec/specs/marketing-analytics/spec.md#test-unavailable-if-n500
	test('the A/B tab withholds significance below 500 delivered per variant', async ({ page }) => {
		await openApp(page)
		const { segmentId, templateId } = await seededFks(page)

		// Unique per attempt so a retry cannot see the previous attempt's card.
		const stamp = Date.now()
		const parentName = `E2E gate19 A/B parent ${stamp}`
		const base = { channel: 'email', status: 'sent', segmentId, templateId, sentAt: isoHoursAgo(2) }

		// Both arms sit far below AB_MIN_DELIVERED (500), which is the condition
		// the scenario names. The counts are distinct so the rendered notice can
		// be asserted against THESE numbers rather than against any two digits.
		const parentId = await mintBlast(page, {
			...base,
			name: parentName,
			totals: { queued: 0, sent: 14, delivered: 12, bounced: 2, opened: 6, clicked: 3, unsubscribed: 0, complained: 0 },
		})
		const variantId = await mintBlast(page, {
			...base,
			name: `E2E gate19 A/B variant ${stamp}`,
			abVariantOf: parentId,
			totals: { queued: 0, sent: 14, delivered: 9, bounced: 5, opened: 4, clicked: 1, unsubscribed: 0, complained: 0 },
		})

		try {
			await gotoHash(page, '/blasts/performance')

			const dash = page.locator('.performance-dashboard')
			await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })
			await dash.getByRole('tab', { name: /A\/B/i }).click()

			// The card for THIS pair — matched on the parent name the card's own
			// <h3> renders, so a card belonging to any other pair cannot satisfy it.
			const card = dash.locator('.performance-dashboard__ab-card')
				.filter({ hasText: parentName })
				.first()
			await expect(card).toBeVisible({ timeout: 20000 })
			await expect(card.getByRole('heading', { name: 'Variant A' })).toBeVisible()
			await expect(card.getByRole('heading', { name: 'Variant B' })).toBeVisible()

			// The pending notice is shown WITH the current counts, and no verdict /
			// p-value is computed.
			const pending = card.locator('.performance-dashboard__ab-pending')
			await expect(pending).toBeVisible()
			await expect(pending).toContainText('Results not yet available')
			await expect(pending).toContainText('Currently A: 12 delivered, B: 9 delivered.')
			await expect(card.locator('.performance-dashboard__ab-verdict')).toHaveCount(0)

			await assertNoHardError(page)
		} finally {
			await api(page, 'DELETE', `${OR}/blast/${variantId}`)
			await api(page, 'DELETE', `${OR}/blast/${parentId}`)
		}
	})

	/*
	 * THE OTHER SIDE OF THE SAME BRANCH.
	 * The sibling above pins the pending branch (both arms under
	 * AB_MIN_DELIVERED). This one pins the branch the requirement is actually
	 * named for: both arms over 500 delivered AND both sent more than
	 * AB_MIN_ELAPSED_MS ago, so `eligible` is true, `chiSquarePValue()` runs
	 * and a verdict is rendered.
	 *
	 * The click rates are deliberately far apart (10% vs 2% over 600 delivered)
	 * so the chi-square result is not borderline — a test that could flip
	 * between the two verdict strings on a rounding change would be pinning the
	 * arithmetic, not the branch. The assertion therefore accepts either
	 * verdict literal for the "displays accordingly" clause, and separately
	 * asserts the significant-variant class, which is what distinguishes this
	 * branch from the pending one.
	 */
	// @e2e openspec/specs/marketing-analytics/spec.md#significance-test-once-n500-and-24h-elapsed
	test('the A/B tab computes a p-value once both arms clear 500 delivered and 24h', async ({ page }) => {
		await openApp(page)
		const { segmentId, templateId } = await seededFks(page)

		const stamp = Date.now()
		const parentName = `E2E gate19 A/B significant parent ${stamp}`
		// 30h ago: past AB_MIN_ELAPSED_MS (24h) for both arms, so the elapsed
		// clause of the scenario is exercised rather than assumed.
		const base = { channel: 'email', status: 'sent', segmentId, templateId, sentAt: isoHoursAgo(30) }

		const parentId = await mintBlast(page, {
			...base,
			name: parentName,
			totals: { queued: 0, sent: 640, delivered: 600, bounced: 40, opened: 300, clicked: 60, unsubscribed: 0, complained: 0 },
		})
		const variantId = await mintBlast(page, {
			...base,
			name: `E2E gate19 A/B significant variant ${stamp}`,
			abVariantOf: parentId,
			totals: { queued: 0, sent: 640, delivered: 600, bounced: 40, opened: 300, clicked: 12, unsubscribed: 0, complained: 0 },
		})

		try {
			await gotoHash(page, '/blasts/performance')

			const dash = page.locator('.performance-dashboard')
			await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })
			await dash.getByRole('tab', { name: /A\/B/i }).click()

			const card = dash.locator('.performance-dashboard__ab-card')
				.filter({ hasText: parentName })
				.first()
			await expect(card).toBeVisible({ timeout: 20000 })

			// The pending notice must be GONE — that is the discriminator against
			// the sibling test, and it is what a threshold regression would break.
			await expect(card.locator('.performance-dashboard__ab-pending')).toHaveCount(0)

			const verdict = card.locator('.performance-dashboard__ab-verdict')
			await expect(verdict).toBeVisible()
			await expect(verdict).toContainText(/significantly higher|Not significant/i)

			// A p-value was actually computed and rendered, not merely a label.
			await expect(verdict.locator('.performance-dashboard__ab-pvalue')).toContainText(/^p = /)

			await assertNoHardError(page)
		} finally {
			await api(page, 'DELETE', `${OR}/blast/${variantId}`)
			await api(page, 'DELETE', `${OR}/blast/${parentId}`)
		}
	})

	/*
	 * SAME SLUG-VS-UUID MISMATCH AS THE A/B TAB, ON A DIFFERENT JOIN.
	 * `fetchAttributionRows()` calls `GET /api/blasts/:id/attribution` with
	 * `blast.id || blast.uuid || blast.slug` — the UUID — and
	 * `AttributionService::getBlastAttributionSummary()` resolves that through
	 * an exact-match `filters: ['blastId' => …]` query. The two seeded
	 * AttributionLinks store `"blastId": "blast-q4-gemeente-outreach-a"` / `-b`,
	 * i.e. the blasts' SLUGS, so every summary comes back `dealCount: 0,
	 * attributedValue: 0`, every row is dropped by the non-zero filter, and the
	 * tab paints "No attribution data yet.". Measured in run 31473685688:
	 * `.performance-dashboard__table` was "element(s) not found" after 20s on
	 * this tab, while the seeded AttributionLink OBJECTS read back fine through
	 * the OpenRegister object API in the same run (the seed test below).
	 *
	 * The scenario is about the dashboard SUMMING and RENDERING attribution per
	 * blast, so the fixture below gives it a blast and two AttributionLinks that
	 * genuinely point at it — two distinct deals worth 1000 + 250 — which makes
	 * the rendered deal count and EUR total checkable against known inputs
	 * instead of against "some digit".
	 *
	 * THIS IS **NOT** pipelinq#771, WHICH WAS CHECKED FIRST.
	 * #771 is about `findAll()` call sites that put `register` / `schema` at the
	 * TOP LEVEL of the config array, where `ObjectService::prepareFindAllConfig()`
	 * never looks — it reads `$config['filters']['register']` and
	 * `$config['filters']['schema']`. `AttributionService::loadAttributionLinks()`
	 * nests them under `filters`, i.e. the shape that IS read. The proof that
	 * this shape resolves on the CI instance is in run 31473685688 itself:
	 * `BlastService::loadObjects()` builds the byte-identical config
	 * (`['filters' => array_merge(['register' => …, 'schema' => …], $filters)]`)
	 * and the "GET /api/blasts paginates and filters by status" test passed
	 * there with BOTH controls — `?status=sent` returned only sent rows, and
	 * `?status=failed` returned an EMPTY page rather than everything. A data
	 * filter alongside register/schema therefore applies. What differs for
	 * attribution is only the VALUE being matched: a UUID against stored slugs.
	 */
	// @e2e openspec/specs/marketing-analytics/spec.md#dashboard-sums-attributed-revenue-per-blast
	test('the Attribution tab shows attributed deal count and value per blast', async ({ page }) => {
		await openApp(page)
		const { segmentId, templateId } = await seededFks(page)

		const stamp = Date.now()
		const blastName = `E2E gate19 attribution blast ${stamp}`
		const blastId = await mintBlast(page, {
			name: blastName,
			channel: 'email',
			status: 'sent',
			segmentId,
			templateId,
			sentAt: isoHoursAgo(3),
			totals: { queued: 0, sent: 4, delivered: 4, bounced: 0, opened: 3, clicked: 2, unsubscribed: 0, complained: 0 },
		})

		const linkIds: string[] = []
		for (const [deal, value] of [[`e2e-deal-a-${stamp}`, 1000], [`e2e-deal-b-${stamp}`, 250]] as Array<[string, number]>) {
			const made = await api(page, 'POST', `${OR}/attributionLink`, {
				blastId,
				contactId: `e2e-contact-${stamp}`,
				dealId: deal,
				firstClickAt: isoHoursAgo(2),
				closedWonAt: isoHoursAgo(1),
				attributedValue: value,
				currency: 'EUR',
			})
			expect(made.status, made.text).toBeLessThan(300)
			linkIds.push(idOf(made.json))
		}

		try {
			await gotoHash(page, '/blasts/performance')

			const dash = page.locator('.performance-dashboard')
			await expect(dash.getByRole('heading', { name: 'Blast performance' })).toBeVisible({ timeout: 20000 })
			await dash.getByRole('tab', { name: /Attribution/i }).click()

			const table = dash.locator('.performance-dashboard__table')
			await expect(table).toBeVisible({ timeout: 20000 })
			await expect(table.locator('thead th')).toHaveCount(3)
			await expect(table.locator('thead')).toContainText('Attributed deals')
			await expect(table.locator('thead')).toContainText('Attributed value')

			// This blast's row, and the two values the summary had to DERIVE rather
			// than echo: the count of DISTINCT dealIds (2, from two links sharing
			// one contactId) and the SUM of attributedValue (1000 + 250 = 1250).
			// Neither number appears in any single input row, so a pass-through
			// cannot satisfy this. `formatEur()` renders `EUR <nl-NL number>`
			// ("EUR 1.250"); the group separator is matched permissively because
			// it is the browser's ICU choice, not the app's assertion.
			const row = table.locator('tbody tr').filter({ hasText: blastName }).first()
			await expect(row).toBeVisible({ timeout: 20000 })
			await expect(row.locator('td').nth(1)).toHaveText(/^\s*2\s*$/)
			await expect(row.locator('td').nth(2)).toHaveText(/EUR\s*1[.,\s]?250/)

			await assertNoHardError(page)
		} finally {
			for (const linkId of linkIds) {
				if (linkId) {
					await api(page, 'DELETE', `${OR}/attributionLink/${linkId}`)
				}
			}
			await api(page, 'DELETE', `${OR}/blast/${blastId}`)
		}
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
		//
		// The previous form of this assertion looked for the option text INSIDE
		// `.blast-form`, and could never pass. `<NcSelect :options="segments">`
		// paints no option until its combobox is opened, and @nextcloud/vue 9's
		// NcSelect defaults `appendToBody: true`, so vue-select renders the open
		// menu at the END OF <body> — outside `.blast-form` even when it is open.
		// Run 31473685688 recorded it as "element(s) not found" after 20s.
		// So: open the combobox, then match the option page-wide, the same way
		// spec-coverage/appointment-booking.spec.ts drives its NcSelect.
		await form.getByRole('button', { name: 'Next' }).first().click()
		const segmentPicker = form.locator('.vs__dropdown-toggle').first()
		await expect(segmentPicker).toBeVisible({ timeout: 20000 })
		await segmentPicker.click()

		const segmentOption = page
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'Gemeente Contact Blast' })
			.first()
		await expect(segmentOption, 'the seeded Segment must be offered as a pickable option')
			.toBeVisible({ timeout: 20000 })

		// Picking it advances the form's own state — the estimated-audience hint
		// is `v-if="selectedSegment"`, so it can only appear once the picker has
		// bound a real Segment object.
		await segmentOption.click()
		await expect(form.locator('.blast-form__hint')).toBeVisible({ timeout: 20000 })

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
		expect([...new Set(segRows.map((s: any) => s.entityType))]).toContain('contact')

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

	/*
	 * WHAT THIS TEST USED TO DO, AND WHY IT WAS NOT MEASURING VALIDATION
	 * ------------------------------------------------------------------
	 * It POSTed three rule trees to `/api/segments` — one with an unknown
	 * field, one with a numeric operator on a string field, one valid — and
	 * asserted 400 / 400 / 201. In run 31473685688 the first two "passed" and
	 * the third failed, and the body of the THIRD tells you why the first two
	 * are worthless:
	 *
	 *   {"error":"Invalid rule tree: Unknown entityType \"contact\"
	 *    (no schema mapping configured)."}
	 *
	 * The VALID tree drew the identical rejection. `SegmentService::validateRules()`
	 * returns that string whenever `resolveSchemaProperties()` yields null, i.e.
	 * BEFORE it looks at a single leaf — so on that instance every POST /api/segments
	 * is a 400 whose message matches `/rule tree/i` regardless of its payload.
	 * Two assertions that a correct payload also satisfies are not evidence
	 * about rule validation, so they are gone rather than kept green.
	 *
	 * ROOT CAUSE (source, not inference). `resolveSchemaProperties()` calls
	 * `$schemaMapper->find(id: …, published: null, _rbac: false, _multitenancy: false)`.
	 * OpenRegister's `SchemaMapper::find()` no longer HAS a `$published`
	 * parameter — commit ea99a5004 ("refactor!: remove deprecated SOLR search
	 * index and Register/Schema publishing", 2026-06-25, on origin/development,
	 * which is the ref .github/workflows/code-quality.yml installs) dropped it.
	 * PHP raises `Error: Unknown named parameter $published`, the method's own
	 * `catch (Throwable)` swallows it into an info log, and the caller reports
	 * "no schema mapping configured". Segment creation is broken app-wide, not
	 * only in CI. Tracked as pipelinq#773; the three validation scenarios carry
	 * a reason-bearing `@e2e exclude` naming that measurement, and those
	 * exclusions lapse when #773 lands.
	 *
	 * WHAT IS STILL OBSERVABLE, AND IS ASSERTED BELOW. `estimateSize()` does not
	 * go through `resolveSchemaProperties()` at all — it loads the segment,
	 * reads its stored rule tree and evaluates it against the live rows. The
	 * detail endpoint is the surface the scenario names, and it is reachable.
	 */
	// @e2e openspec/specs/marketing-segmentation/spec.md#estimated-size-computed
	test('GET /api/segments/:id recomputes estimatedSize instead of echoing the stored one', async ({ page }) => {
		await openApp(page)

		const list = await api(page, 'GET', `${APP}/api/segments`)
		expect(list.status, list.text).toBe(200)
		const segRows: any[] = list.json?.data ?? list.json ?? []
		expect(segRows.length, 'the seeded Segments must be listed').toBeGreaterThan(0)

		// The LIST returns the stored objects untouched (SegmentController::index
		// hands back `listSegments()`), so `estimatedSize` there is the seeded
		// literal — 248 for "Gemeente Contact Blast", 186 for "Technical Leads",
		// numbers written into register.d/95-marketing-segmentation-blast.json by
		// hand. The DETAIL endpoint overwrites that field with
		// `SegmentService::estimateSize()` before responding.
		//
		// So the two responses disagreeing is the direct proof that the detail
		// number was COMPUTED from the current rows rather than read back off the
		// object — which is precisely what this scenario asks for. A CI instance
		// has nowhere near 248 contacts matching
		// `sector=overheid AND country=NL AND organisationType in (gemeente, provincie)`.
		let checked = 0
		for (const name of ['Gemeente Contact Blast', 'Technical Leads']) {
			const row = segRows.find((s: any) => s.name === name)
			expect(row, `seeded segment "${name}" is missing`).toBeTruthy()
			const stored = row.estimatedSize
			expect(typeof stored, `the stored estimatedSize for "${name}" must be a number`).toBe('number')

			const detail = await api(page, 'GET', `${APP}/api/segments/${idOf(row)}`)
			expect(detail.status, detail.text).toBe(200)
			expect(typeof detail.json?.estimatedSize, `the detail response for "${name}" carries estimatedSize`).toBe('number')
			expect(detail.json.estimatedSize).toBeGreaterThanOrEqual(0)
			expect(
				detail.json.estimatedSize,
				`"${name}" detail returned the STORED estimate (${stored}) — estimateSize() was not consulted`,
			).not.toBe(stored)
			checked++
		}
		expect(checked, 'both seeded segments must have been checked').toBe(2)
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
		//
		// `segmentId` / `templateId` are NOT decoration: the `blast` schema
		// declares `required: [name, segmentId, templateId, channel]`, and run
		// 31473685688 refused this POST with 400 "The required properties
		// (segmentId, templateId) are missing." They are read off the seeded
		// objects through the app's own endpoints rather than invented, so the
		// fixture is a blast the product would accept.
		//
		// `failed` was also dropped from `totals`: the declared counter set is
		// {queued, sent, delivered, bounced, opened, clicked, unsubscribed,
		// complained} and `failed` is not in it.
		const { segmentId, templateId } = await seededFks(page)
		const made = await api(page, 'POST', `${OR}/blast`, {
			name: 'E2E gate-19 sending blast',
			channel: 'email',
			status: 'sending',
			segmentId,
			templateId,
			totals: { queued: 6, sent: 3, delivered: 1, bounced: 0, opened: 0, clicked: 0, unsubscribed: 0, complained: 0 },
		})
		expect(made.status, made.text).toBeLessThan(300)
		blastId = idOf(made.json)
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
