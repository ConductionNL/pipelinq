/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * My work on a phone as board PqMobiel draws it (REQ-MW-170).
 *
 *   @e2e my-work::chips-with-counts
 *   @e2e my-work::the-group-header-stays-in-view
 *   @e2e my-work::quick-actions-fold-away
 *
 * The lens chips carry their counts, the chosen chip reads on its dark fill,
 * the Overdue heading stays at the top while its rows scroll under it, and
 * the header holds the title and one Actions button whose menu offers New
 * ticket and New contact.
 *
 * @spec openspec/changes/archive/2026-11-28-my-work-saved-views-and-phone/specs/my-work/spec.md#requirement-phone-view-keeps-headers-and-filters-in-reach-req-mw-170
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'
import { gotoAppRoute } from '../helpers/pipelinq.ts'

const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}

let api: APIRequestContext
const made: string[] = []

test.use({ viewport: { width: 390, height: 420 }, isMobile: true, hasTouch: true })

test.beforeAll(async () => {
	api = await request.newContext({
		baseURL: resolveBaseUrl(),
		httpCredentials: ADMIN,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true' },
	})
	// Six overdue tickets and one due today, all the admin's own, so the
	// Overdue group is longer than a short phone screen.
	for (let i = 0; i < 7; i++) {
		const due = new Date(Date.now() + (i === 6 ? 0 : -(i + 2)) * 86400000)
		const res = await api.post(`${OBJECTS}/ticket`, {
			data: {
				ticketType: 'request',
				title: `Phone ${RUN} ${i}`,
				assignee: ADMIN.username,
				status: 'new',
				slaDeadline: due.toISOString(),
			},
		})
		expect(res.ok()).toBeTruthy()
		made.push((await res.json()).id)
	}
})

test.afterAll(async () => {
	for (const id of made) {
		await api.delete(`${OBJECTS}/ticket/${id}`)
	}
	await api.dispose()
})

test('the lens chips carry their counts and the chosen one reads', async ({ page }) => {
	await gotoAppRoute(page, '/my-work')
	const chips = page.locator('.cn-segmented-control--phone-pills .cn-segmented-control__option')
	// The counts arrive after the chips: wait for the first one to carry its number.
	await expect(chips.first()).toHaveText(/All\s*\d+/)
	const labels = (await chips.allInnerTexts()).map((t) => t.replace(/\s+/g, ' ').trim())
	expect(labels[0]).toMatch(/^All \d+$/)
	expect(labels.some((l) => /^Tickets \d+$/.test(l))).toBe(true)
	const chosen = page.locator('.cn-segmented-control--phone-pills .cn-segmented-control__option--checked')
	const [color, background] = await chosen.evaluate((el) => [getComputedStyle(el).color, getComputedStyle(el).backgroundColor])
	expect(color).not.toBe(background)
})

test('the Overdue heading stays at the top while its rows scroll', async ({ page }) => {
	await gotoAppRoute(page, '/my-work')
	const heading = page.locator('.cn-record-list__group-heading', { hasText: /overdue/i }).first()
	await expect(heading).toBeVisible()
	const scrollArea = page.locator('.app-content, #app-content').first()
	const areaTop = (await scrollArea.boundingBox())!.y
	await heading.scrollIntoViewIfNeeded()
	await scrollArea.evaluate((el) => { el.scrollTop += 200 })
	await page.waitForTimeout(300)
	const top = (await heading.boundingBox())!.y
	expect(Math.abs(top - areaTop)).toBeLessThanOrEqual(2)
})

test('the header holds one Actions button that offers New ticket and New contact', async ({ page }) => {
	await gotoAppRoute(page, '/my-work')
	await expect(page.getByTestId('cn-dashboard-page-header-actions')).toHaveCount(0)
	await expect(page.getByTestId('cn-dashboard-page-layout-panel-button')).toHaveCount(0)
	await page.getByRole('button', { name: 'Actions' }).first().click()
	await expect(page.getByRole('menuitem', { name: 'New ticket' })).toBeVisible()
	await expect(page.getByRole('menuitem', { name: 'New contact' })).toBeVisible()
	await page.getByRole('menuitem', { name: 'New ticket' }).click()
	await expect(page.getByRole('dialog')).toBeVisible()
})
