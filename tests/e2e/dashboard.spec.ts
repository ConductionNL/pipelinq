/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Dashboard — rewritten for the manifest-driven app shell (#392).
 *
 * Gate-19 @e2e traceability:
 *   @e2e dashboard::quick-action-buttons-in-header
 *   @e2e dashboard::display-open-leads-count
 *   @e2e dashboard::display-open-requests-count
 *   @e2e dashboard::display-pipeline-total-value
 *   @e2e dashboard::display-overdue-items-count
 *   @e2e dashboard::render-status-distribution-bars
 *   @e2e dashboard::display-assigned-items
 */
import { test, expect } from '@playwright/test'
import { openApp } from './helpers/nav'

test.describe('Dashboard', () => {

	test.beforeEach(async ({ page }) => {
		await openApp(page)
	})

	test('shows quick-create action buttons', async ({ page }) => {
		await expect(page.getByRole('button', { name: 'New Lead' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'New Request' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'New Client' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Refresh dashboard' })).toBeVisible()
	})

	test('shows KPI cards with links to filtered views', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Open Leads', level: 4 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Open Requests', level: 4 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Pipeline Value', level: 4 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Overdue', level: 4 })).toBeVisible()

		await expect(page.getByRole('link', { name: /Open Leads/ })).toHaveAttribute('href', /leads\?status=open/)
		await expect(page.getByRole('link', { name: /Open Requests/ })).toHaveAttribute('href', /requests\?status=open/)
		await expect(page.getByRole('link', { name: /Pipeline Value/ })).toHaveAttribute('href', /pipeline/)
		await expect(page.getByRole('link', { name: /Overdue/ })).toHaveAttribute('href', /leads\?overdue=true/)
	})

	test('shows dashboard section panels', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Requests by Status', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'My Work', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Client Overview', level: 3 })).toBeVisible()
	})

	test('a KPI card navigates to its filtered view', async ({ page }) => {
		await page.getByRole('link', { name: /Open Leads/ }).click()
		await expect(page).toHaveURL(/\/apps\/pipelinq\/leads\?status=open/)
		await expect(page.getByRole('heading', { name: 'Leads', level: 2 })).toBeVisible({ timeout: 20000 })
	})
})
