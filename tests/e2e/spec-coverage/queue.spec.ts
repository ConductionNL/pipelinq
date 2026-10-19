/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the queue, the default view `queue` on
 * All tickets. Maps to
 * openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md.
 *
 * The queue is a FILTER, not a collection, so the assertions here are about the
 * filter holding: the row count strictly below the unfiltered index. A view that
 * silently dropped its filter would render a healthy-looking table of every
 * ticket in the app.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

const QUEUE_VIEW = '/apps/pipelinq/tickets?savedView=default:queue'

// @e2e openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md#the-old-address-opens-the-view
test('Queue: the old address opens the queue view on All tickets', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await page.goto('/apps/pipelinq/queue')
	await expect(page).toHaveURL(/\/tickets\?savedView=default(%3A|:)queue/)
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible({
		timeout: 15000,
	})
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/changes/queue-as-default-view/specs/retire-queue-concept/spec.md#the-queue-holds-unassigned-open-tickets
test('Queue: the view holds strictly fewer rows than the unfiltered ticket index', async ({
	page,
}) => {
	await openApp(page)

	// The seeded register carries assigned tickets, so a view that returned the
	// same count as All tickets means its filter never reached the query.
	await navClick(page, 'All tickets', /\/tickets/)
	const content = page.locator('#content-vue')
	await expect(content.locator('table tbody tr').first()).toBeVisible({
		timeout: 15000,
	})
	const allRows = await content.locator('table tbody tr').count()

	await page.goto(QUEUE_VIEW)
	await expect(
		content.locator('table tbody tr, .cn-index-page__empty').first(),
	).toBeVisible({ timeout: 15000 })
	const queueRows = await content.locator('table tbody tr').count()

	expect(
		queueRows,
		'the queue is a filtered slice of the ticket index, never the whole of it',
	).toBeLessThan(allRows)
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
