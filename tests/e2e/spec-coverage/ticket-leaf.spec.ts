/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for the ticket-leaf scenarios
 * (openspec/specs/ticket-leaf/spec.md).
 *
 * A request and a complaint are created through OpenRegister's objects API, as
 * any client of the register would. The counter is shared by the whole
 * instance, so the test does not expect 0001: it expects this year's
 * PQ-<year>-<nnnn> form, two different numbers for two tickets, and a
 * reference given on create kept as it is.
 *
 * @e2e ticket-leaf::a-new-request
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from '../helpers/pipelinq.ts'

/** Distinctive enough that finding a ticket proves this run made it. */
const STAMP = Date.now()

const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'

/**
 * Create a ticket from inside the page and answer what was stored.
 *
 * @param page The page to run in.
 * @param data The ticket.
 *
 * @return The stored ticket.
 */
async function createTicket(page: Page, data: Record<string, unknown>): Promise<Record<string, unknown>> {
	const res = await page.evaluate(
		async ({ url, data }) => {
			const r = await fetch(url, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: JSON.stringify(data),
			})
			return { status: r.status, text: await r.text() }
		},
		{ url: `${OBJECTS}/ticket`, data },
	)
	expect(res.status, res.text.slice(0, 300)).toBeLessThan(300)
	return JSON.parse(res.text)
}

test.describe('ticket-leaf', () => {
	// @e2e ticket-leaf::a-new-request
	test('a new request and complaint are numbered PQ-<year>-<nnnn>, each its own, and a given reference is kept', async ({ page }) => {
		await openApp(page)
		const year = new Date().getFullYear()

		const request = await createTicket(page, { title: `E2E request ${STAMP}`, ticketType: 'request', status: 'new' })
		const complaint = await createTicket(page, { title: `E2E complaint ${STAMP}`, ticketType: 'complaint', status: 'new' })

		expect(String(request.reference)).toMatch(new RegExp(`^PQ-${year}-\\d{4}$`))
		expect(String(complaint.reference)).toMatch(new RegExp(`^PQ-${year}-\\d{4}$`))
		expect(complaint.reference).not.toBe(request.reference)

		const kept = await createTicket(page, {
			title: `E2E imported ${STAMP}`,
			ticketType: 'request',
			status: 'new',
			reference: 'PQ-2019-0042',
		})
		expect(kept.reference).toBe('PQ-2019-0042')
	})
})
