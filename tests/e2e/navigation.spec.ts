/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sidebar navigation — rewritten for the manifest-driven app shell (#392).
 * The left-nav links perform client-side routing; see helpers/nav.ts.
 */
import { test, expect, Page } from '@playwright/test'
import { openApp } from './helpers/nav'

// route slug → human label, as rendered in the in-app left navigation.
const NAV: Record<string, string> = {
	clients: 'Clients',
	contacts: 'Contacts',
	leads: 'Leads',
	requests: 'Requests',
	tasks: 'Tasks',
	contactmomenten: 'Contactmomenten',
	complaints: 'Complaints',
	products: 'Products',
	pipeline: 'Pipeline',
	surveys: 'Surveys',
	queues: 'Queues',
	kennisbank: 'Kennisbank',
	'my-work': 'My Work',
	rapportage: 'Reporting',
}

const navLink = (page: Page, route: string) =>
	page.locator(`a[href="/apps/pipelinq/${route}"]`).first()

test.describe('Sidebar navigation', () => {

	test('shows all navigation items with correct routes', async ({ page }) => {
		await openApp(page)
		for (const [route, label] of Object.entries(NAV)) {
			const link = navLink(page, route)
			await expect(link, `nav link for ${label}`).toBeVisible()
			await expect(link).toContainText(label)
		}
	})

	test('clicking a nav item routes client-side without a full reload', async ({ page }) => {
		await openApp(page)
		await navLink(page, 'leads').click()
		await expect(page).toHaveURL(/\/apps\/pipelinq\/leads$/)
		await expect(page.getByRole('heading', { name: 'Leads', level: 2 })).toBeVisible({ timeout: 20000 })
	})

	test('settings sub-menu exposes admin views', async ({ page }) => {
		await openApp(page)
		// Scope to the in-app nav Settings button; the header also has a
		// "Settings menu" button which otherwise matches in strict mode.
		await page.getByTestId('cn-nav-settings').getByRole('button', { name: 'Settings' }).click()
		for (const label of ['Pipelines', 'Forms', 'Automations']) {
			await expect(page.getByRole('link', { name: label })).toBeVisible({ timeout: 10000 })
		}
	})
})
