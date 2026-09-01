/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the Queues index page (/queues).
 * Maps to openspec/specs/queue-management/spec.md.
 */
import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	navClick,
	openApp,
	trackPipelinqErrors,
} from '../helpers/pipelinq.ts'

// @e2e openspec/specs/queue-management/spec.md#queues-index
test('Queues: navigates from sidebar and shows index surface', async ({ page }) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)

	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'Queues' })
			.first(),
	).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e openspec/specs/queue-management/spec.md#queues-list-table
test('Queues: list table and primary actions render on a single page', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('button', { name: 'Add Queue' })).toBeVisible()
	await expect(
		content
			.locator('table, .cn-data-table, [data-testid="cn-data-table"]')
			.first(),
	).toBeVisible()

	// CORRECTED 2026-08-06. This used to assert a visible "Next" button with the
	// comment "Queues has seeded data → a pagination control is present". Both
	// halves were wrong about the component. CnIndexPage renders CnPagination
	// only under `effectivePagination.pages > 1` (verified in
	// @conduction/nextcloud-vue 2.2.0-vue3.3 dist/), and the page size is 20.
	// The queue collection holds the base register's 3 example queues plus the 3
	// seeded demo queues — six rows, one page — so "Next" is correctly absent and
	// the old assertion could not pass on any healthy install. Seeding 21 queues
	// purely to make a Next button appear would be contriving data to satisfy an
	// assertion rather than testing anything.
	//
	// The real pagination contract is asserted where it genuinely applies: the
	// Tickets index carries 30 rows, so spec-coverage/request-management.spec.ts
	// drives a page change there.
	const rows = content.locator('table tbody tr')
	await expect(rows.first()).toBeVisible()
	expect(
		await rows.count(),
		'the queue collection fits one page',
	).toBeLessThanOrEqual(20)
	await expect(content.locator('.cn-index-page__pagination')).toHaveCount(0)
})

// @e2e openspec/specs/queue-management/spec.md#queues-create-modal
test('Queues: Add Queue opens a create modal with a form', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Queues', /\/queues/)
	await dismissSupportDialog(page)

	await page
		.locator('#content-vue')
		.getByRole('button', { name: 'Add Queue' })
		.click()
	const modal = page.locator('.modal-container, [role="dialog"]').first()
	await expect(modal).toBeVisible({ timeout: 10000 })
	await expect(
		modal.locator('input, .input-field__input, textarea').first(),
	).toBeVisible()
})

/*
 * Backend / data-dependent scenarios excluded — covered by PHPUnit / Newman:
 * @e2e exclude queue-routing-rules — server-side; covered by PHPUnit
 * @e2e exclude queue-detail-view — requires seeded record
 * @e2e exclude queue-walk-in-ticket — requires KCC werkplek flow
 * @e2e exclude queue-delete — requires existing record
 */
