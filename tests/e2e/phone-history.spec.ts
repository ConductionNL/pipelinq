/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A caller's history is found by phone number, however the number was typed
 * (contact-moments-phone-lookup tasks 1.5 and 1.6).
 *
 *   @e2e contact-moments-phone-lookup::a-caller-who-never-left-a-name-calls-again
 *   @e2e contact-moments-phone-lookup::a-first-time-caller
 *   @e2e contact-moments-phone-lookup::a-kcc-agent-types-the-number-with-a-leading-zero-and-spaces
 *
 * Two earlier contact moments are seeded on one run-unique mobile number,
 * stored the way the CTI and contact-moment writers store it (`phoneE164`
 * +316..., `from_number` as dialled). The intake form is opened through the
 * incoming-call card's address preview (`?callPreview=<number>&callPreviewOpen=intake`,
 * see IncomingCallCard.vue), which shows the card and the new-contact form for
 * a number without a telephony platform or a live call; the form then reads
 * GET /api/tickets/by-phone for real. The Tickets list is searched with the
 * number typed as `06 xxxx xxxx`, and must say it searched by phone number and
 * list the seeded ticket.
 *
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-an-unknown-callers-earlier-contacts-are-shown-req-chp-001
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute, openIndexSearch } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq/ticket'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

// A run-unique Dutch mobile number: 06 + 8 digits.
const DIGITS = String(Date.now()).slice(-8)
const E164 = `+316${DIGITS}`
const TYPED = `06 ${DIGITS.slice(0, 4)} ${DIGITS.slice(4)}`
const UNKNOWN = `+316${String(Number(DIGITS) + 1).padStart(8, '0').slice(-8)}`

const seeded: string[] = []
let admin: APIRequestContext

/**
 * Seed one earlier contact moment from the caller's number.
 *
 * @param title The contact moment's title.
 * @param occurredAt When it happened.
 */
async function seedContactMoment(title: string, occurredAt: string): Promise<void> {
	const created = await admin.post(OBJECTS, {
		data: {
			title,
			ticketType: 'interaction',
			status: 'closed',
			channel: 'phone',
			direction: 'inbound',
			occurredAt,
			from_number: TYPED,
			phoneE164: E164,
		},
	})
	expect(created.ok(), `seeding "${title}": ${created.status()} ${(await created.text()).slice(0, 300)}`).toBeTruthy()
	const body = await created.json()
	seeded.push(String(body.id ?? body['@self']?.id ?? body.uuid))
}

test.beforeAll(async () => {
	admin = await request.newContext({ baseURL: BASE_URL, httpCredentials: ADMIN, extraHTTPHeaders: HEADERS })
	await seedContactMoment(`E2E-CHP-${RUN} question about a permit`, '2026-01-10T09:00:00+01:00')
	await seedContactMoment(`E2E-CHP-${RUN} callback about the permit`, '2026-02-12T14:30:00+01:00')
})

test.afterAll(async () => {
	for (const id of seeded) {
		await admin.delete(`${OBJECTS}/${id}`).catch(() => undefined)
	}
	await admin.dispose()
})

test.describe('history by phone number', () => {
	test.setTimeout(120_000)

	test('the server finds the tickets whatever way the number is typed', async () => {
		for (const typed of [TYPED, `06-${DIGITS}`, `+31 6 ${DIGITS}`, `00316${DIGITS}`]) {
			const answer = await admin.get('/index.php/apps/pipelinq/api/tickets/by-phone', { params: { number: typed } })
			expect(answer.ok(), `by-phone ${typed}: ${answer.status()}`).toBeTruthy()
			const body = await answer.json()
			expect(body.number, typed).toBe(E164)
			const ids = (body.results as Array<{ id?: string, '@self'?: { id?: string } }>).map((r) => String(r.id ?? r['@self']?.id))
			for (const id of seeded) {
				expect(ids, `by-phone ${typed} lists ${id}`).toContain(id)
			}
		}
	})

	test('an unknown caller with two earlier contacts sees both in the intake form (REQ-CHP-001)', async ({ page }) => {
		await gotoAppRoute(page, `/?callPreview=${encodeURIComponent(E164)}&callPreviewOpen=intake`)

		const intake = page.getByTestId('cti-intake-dialog')
		await expect(intake).toBeVisible({ timeout: 30_000 })
		const history = intake.getByTestId('cti-intake-history')
		await expect(history).toBeVisible()
		await expect(history.getByTestId('cti-intake-history-item')).toHaveCount(2, { timeout: 15_000 })
		await expect(history.getByText(`E2E-CHP-${RUN} question about a permit`)).toBeVisible()
		await expect(history.getByText(`E2E-CHP-${RUN} callback about the permit`)).toBeVisible()
		await expect(intake.getByTestId('cti-intake-history-failed')).toHaveCount(0)
	})

	test('a first-time caller is told there are no earlier contacts', async ({ page }) => {
		await gotoAppRoute(page, `/?callPreview=${encodeURIComponent(UNKNOWN)}&callPreviewOpen=intake`)

		const intake = page.getByTestId('cti-intake-dialog')
		await expect(intake).toBeVisible({ timeout: 30_000 })
		await expect(intake.getByTestId('cti-intake-history-empty')).toBeVisible({ timeout: 15_000 })
	})

	test('the Tickets list searches a typed number as a phone number (REQ-CHP-002)', async ({ page }) => {
		await gotoAppRoute(page, '/tickets')

		const search = await openIndexSearch(page)
		await search.fill(TYPED)

		await expect(page.getByTestId('cn-index-search-note')).toHaveText(/Searching by phone number|Zoeken op telefoonnummer/, { timeout: 15_000 })
		await expect(page.locator('#content-vue').getByText(`E2E-CHP-${RUN} question about a permit`)).toBeVisible({ timeout: 15_000 })
		await expect(page.locator('#content-vue').getByText(`E2E-CHP-${RUN} callback about the permit`)).toBeVisible()
	})
})
