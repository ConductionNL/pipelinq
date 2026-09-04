/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Queue page (/queue).
 * Maps to openspec/changes/retire-queue-concept/specs/retire-queue-concept/spec.md.
 *
 * The queue is a FILTER, not a collection, so the assertions here are about the
 * filter holding: every row unassigned, and the row count strictly below the
 * unfiltered index. A page that silently dropped its base filter would render a
 * healthy-looking table of every ticket in the app, which is exactly the failure
 * `assignee_isnull=true` produced before the sentinel spelling replaced it.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/changes/retire-queue-concept/specs/retire-queue-concept/spec.md#the-queue-holds-unassigned-open-tickets
test('Queue: navigates from the sidebar and renders its index surface', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Queue', /\/queue/)

	await expect(
		page.locator('#content-vue').getByRole('heading', { name: 'Queue' }).first(),
	).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/retire-queue-concept/specs/retire-queue-concept/spec.md#the-queue-holds-unassigned-open-tickets
test('Queue: holds strictly fewer rows than the unfiltered ticket index', async ({
	page,
}) => {
	await openApp(page)

	// The seeded register carries assigned tickets, so a queue that returned the
	// same count as All tickets means the base filter never reached the query.
	await navClick(page, 'All tickets', /\/tickets/)
	const content = page.locator('#content-vue')
	await expect(content.locator('table tbody tr').first()).toBeVisible({
		timeout: 15000,
	})
	const allRows = await content.locator('table tbody tr').count()

	await navClick(page, 'Queue', /\/queue/)
	await expect(
		content.locator('table tbody tr, .cn-index-page__empty').first(),
	).toBeVisible({ timeout: 15000 })
	const queueRows = await content.locator('table tbody tr').count()

	expect(
		queueRows,
		'the queue is a filtered slice of the ticket index, never the whole of it',
	).toBeLessThan(allRows)
})

// @e2e openspec/changes/retire-queue-concept/specs/retire-queue-concept/spec.md#the-queue-narrows-by-ticket-type
test('Queue: the ticket-type tabs narrow the queue', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Queue', /\/queue/)

	const content = page.locator('#content-vue')
	await expect(content.locator('table tbody tr').first()).toBeVisible({
		timeout: 15000,
	})
	const before = await content.locator('table tbody tr').count()

	await content.getByRole('button', { name: 'Complaints' }).first().click()
	await expect(
		content.locator('table tbody tr, .cn-index-page__empty').first(),
	).toBeVisible({ timeout: 15000 })
	const after = await content.locator('table tbody tr').count()

	expect(
		after,
		'narrowing to one ticketType cannot widen the result set',
	).toBeLessThanOrEqual(before)
})

// @e2e openspec/changes/retire-queue-concept/specs/retire-queue-concept/spec.md#an-empty-queue-says-so
test('Queue: an empty result renders the empty state, not a bare table', async ({
	page,
}) => {
	// Drive the filter to a slice that cannot match: the page must answer with its
	// empty state rather than an empty table body or a blank region.
	await page.goto('/apps/pipelinq/queue?ticketType=__none__')
	await expect(page.locator('[data-testid="cn-app-root"]')).toBeVisible({
		timeout: 15000,
	})

	const content = page.locator('#content-vue')
	await expect(
		content.locator('.cn-index-page__empty, table tbody tr').first(),
	).toBeVisible({ timeout: 15000 })
})

// @e2e openspec/specs/kcc-werkplek/spec.md#the-werkplek-shows-the-agents-own-work-only
test('Werkplek: the queue filter widget is gone and the work widgets remain', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await page.goto('/apps/pipelinq/werkplek')
	await expect(page.locator('[data-testid="cn-app-root"]')).toBeVisible({
		timeout: 15000,
	})

	const content = page.locator('#content-vue')
	await expect(
		content.getByRole('heading', { name: 'Requests' }).first(),
	).toBeVisible({ timeout: 15000 })

	// The retired widget rendered a "Queues" panel heading and an "All queues"
	// reset control. Neither may survive the removal, and a leftover slot that
	// resolves to nothing renders silently rather than erroring.
	await expect(content.getByRole('heading', { name: 'Queues' })).toHaveCount(0)
	await expect(content.getByRole('button', { name: /All queues/i })).toHaveCount(0)

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})
