/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for the marketing-mail-templates scenarios
 * (openspec/specs/marketing-mail-templates/spec.md).
 *
 * Each test makes its own template (and, where needed, a contact with email
 * consent, a segment and a mailing) through pipelinq's API and OpenRegister's
 * objects API, so nothing depends on seeded data. Sending only queues the
 * deliveries; the content a mailing keeps is taken when it starts sending, so
 * REQ-MMT-004 needs no background job. The remaining scenarios are covered by
 * unit and component tests: duplicating (the index page's Copy action),
 * personalisation and escaping (MailTemplateRendererTest), the empty-variable
 * preview (EmptyVariableCounterTest, blastWizardFlow.spec.js) and saving the
 * wizard's content (blastWizardTemplateSave.spec.js).
 *
 * @e2e marketing-mail-templates::a-marketer-saves-a-template
 * @e2e marketing-mail-templates::a-template-in-use
 * @e2e marketing-mail-templates::the-template-changes-after-a-send
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough, gotoAppRoute } from '../helpers/pipelinq.ts'

/** Distinctive enough that finding a row proves this run made it. */
const STAMP = Date.now()

const APP = '/index.php/apps/pipelinq'
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'

/**
 * Call a route from inside the page, carrying the session and its token.
 *
 * @param page   The page to run in.
 * @param method The HTTP verb.
 * @param url    The url.
 * @param body   The request body, for a write.
 *
 * @return The status and the parsed body.
 */
async function api(
	page: Page,
	method: string,
	url: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; body: Record<string, unknown> }> {
	return await page.evaluate(
		async ({ method, url, body }) => {
			const res = await fetch(url, {
				method,
				headers: {
					'Content-Type': 'application/json',
					'OCS-APIRequest': 'true',
					requesttoken:
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let parsed: Record<string, unknown>
			try {
				parsed = JSON.parse(text)
			} catch {
				parsed = { raw: text.slice(0, 300) }
			}
			return { status: res.status, body: parsed }
		},
		{ method, url, body },
	)
}

/**
 * The id of a created object, failing the test with the answer when there is none.
 *
 * @param res  The answer.
 * @param what What was created, for the message.
 *
 * @return The id.
 */
function idOf(res: { status: number; body: Record<string, unknown> }, what: string): string {
	expect(res.status, `${what}: ${JSON.stringify(res.body).slice(0, 300)}`).toBeLessThan(300)
	const body = (res.body.segment ?? res.body.blast ?? res.body.template ?? res.body) as Record<string, unknown>
	const id = (body.id ?? (body['@self'] as Record<string, unknown> | undefined)?.id) as string
	expect(id, `${what} returned no id`).toBeTruthy()
	return id
}

/**
 * Make an email template.
 *
 * @param page    The page.
 * @param name    The template name.
 * @param subject The subject.
 *
 * @return The template id.
 */
async function makeTemplate(page: Page, name: string, subject: string): Promise<string> {
	return idOf(
		await api(page, 'POST', `${APP}/api/templates`, {
			name,
			channel: 'email',
			subject,
			bodyHtml: '<p>Dear {{preferredName}},</p><p>{{unsubscribe_link}}</p><p>{{physical_address}}</p>',
			bodyText: 'Dear {{preferredName}}, {{unsubscribe_link}} {{physical_address}}',
			senderEmail: 'news@example.test',
			senderName: 'E2E newsletter',
			footerOverride: 'Example B.V., Voorbeeldstraat 1, 1234 AB Voorbeeld',
		}),
		`template ${name}`,
	)
}

/**
 * The mailing as the blast list answers it.
 *
 * @param page The page.
 * @param id   The mailing id.
 *
 * @return The mailing row, or undefined.
 */
async function mailing(page: Page, id: string): Promise<Record<string, unknown> | undefined> {
	const res = await api(page, 'GET', `${APP}/api/blasts?limit=200`)
	expect(res.status).toBe(200)
	const rows = (res.body.data ?? []) as Array<Record<string, unknown>>
	return rows.find((row) => row.id === id)
}

test.describe('marketing-mail-templates', () => {
	test.beforeEach(async ({ page }) => {
		await gotoAppRoute(page, '/templates')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
	})

	// @e2e marketing-mail-templates::a-marketer-saves-a-template
	test('a saved email template is listed with channel Email', async ({ page }) => {
		const name = `E2E Monthly newsletter ${STAMP}`
		await makeTemplate(page, name, 'News of the month')

		await page.reload()
		const row = page.getByRole('row').filter({ hasText: name })
		await expect(row).toBeVisible({ timeout: 20000 })
		await expect(row).toContainText(/email/i)
	})

	// @e2e marketing-mail-templates::a-template-in-use
	test('a template a scheduled mailing uses cannot be deleted, and the refusal names the mailing', async ({ page }) => {
		const templateId = await makeTemplate(page, `E2E In use ${STAMP}`, 'Kept')
		const segmentId = idOf(
			await api(page, 'POST', `${APP}/api/segments`, {
				name: `E2E Nobody ${STAMP}`,
				entityType: 'contact',
				rules: { type: 'AND', children: [{ field: 'email', operator: 'contains', value: `nobody-${STAMP}` }] },
			}),
			'segment',
		)
		const mailingName = `E2E Tomorrow's mailing ${STAMP}`
		const blastId = idOf(
			await api(page, 'POST', `${APP}/api/blasts`, {
				name: mailingName,
				segmentId,
				templateId,
				channel: 'email',
				scheduledFor: new Date(Date.now() + 86400000).toISOString(),
			}),
			'mailing',
		)
		const scheduled = await api(page, 'PATCH', `${OBJECTS}/blast/${blastId}`, { status: 'scheduled' })
		expect(scheduled.status, JSON.stringify(scheduled.body).slice(0, 300)).toBeLessThan(300)

		const refused = await api(page, 'DELETE', `${OBJECTS}/campaignTemplate/${templateId}`)
		expect(refused.status).toBeGreaterThanOrEqual(400)
		expect(JSON.stringify(refused.body)).toContain(mailingName)

		const still = await api(page, 'GET', `${OBJECTS}/campaignTemplate/${templateId}`)
		expect(still.status).toBe(200)
	})

	// @e2e marketing-mail-templates::the-template-changes-after-a-send
	test('a sent mailing keeps the subject it was sent with when the template changes', async ({ page }) => {
		const email = `e2e-reader-${STAMP}@example.test`
		const contactId = idOf(
			await api(page, 'POST', `${OBJECTS}/contact`, { name: `E2E Reader ${STAMP}`, email, contactsUid: `e2e-reader-${STAMP}` }),
			'contact',
		)
		idOf(
			await api(page, 'POST', `${OBJECTS}/consentRecord`, {
				contactId,
				channel: 'email',
				lawfulBasis: 'consent',
				consentSource: 'e2e',
				consentedAt: new Date().toISOString(),
				softBounceCount: 0,
			}),
			'consent',
		)
		const segmentId = idOf(
			await api(page, 'POST', `${APP}/api/segments`, {
				name: `E2E Readers ${STAMP}`,
				entityType: 'contact',
				rules: { type: 'AND', children: [{ field: 'email', operator: 'equals', value: email }] },
			}),
			'segment',
		)
		const templateId = await makeTemplate(page, `E2E Monthly ${STAMP}`, 'October news')
		const blastId = idOf(
			await api(page, 'POST', `${APP}/api/blasts`, {
				name: `E2E October newsletter ${STAMP}`,
				segmentId,
				templateId,
				channel: 'email',
			}),
			'mailing',
		)

		const sent = await api(page, 'POST', `${APP}/api/blasts/${blastId}/send`)
		expect(sent.status, JSON.stringify(sent.body).slice(0, 300)).toBeLessThan(300)
		expect(sent.body.queued).toBe(1)

		const changed = await api(page, 'PATCH', `${APP}/api/templates/${templateId}`, { subject: 'November news' })
		expect(changed.status).toBeLessThan(300)

		const row = await mailing(page, blastId)
		expect(row?.sentSubject).toBe('October news')
		expect(String(row?.sentHtml ?? '')).toContain('Dear {{preferredName}},')
	})
})
