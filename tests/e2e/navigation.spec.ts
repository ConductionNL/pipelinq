import { test, expect, Page } from '@playwright/test'

const sidebarNav = (page: Page) => page.locator('[id^="app-navigation"]').first()

test.describe('Sidebar Navigation', () => {

	test('shows all navigation items', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		const nav = sidebarNav(page)

		for (const label of ['Dashboard', 'Clients', 'Contacts', 'Leads', 'Requests', 'Products', 'Pipeline', 'My Work', 'Documentation']) {
			await expect(nav.getByText(label, { exact: label !== 'Pipeline' })).toBeVisible()
		}
	})

	test('sidebar links point to correct URLs', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		const nav = sidebarNav(page)

		const expected: Record<string, string> = {
			Clients: '/apps/pipelinq/clients',
			Contacts: '/apps/pipelinq/contacts',
			Leads: '/apps/pipelinq/leads',
			Requests: '/apps/pipelinq/requests',
			Products: '/apps/pipelinq/products',
			Pipeline: '/apps/pipelinq/pipeline',
			'My Work': '/apps/pipelinq/my-work',
		}

		for (const [name, href] of Object.entries(expected)) {
			await expect(nav.getByRole('link', { name })).toHaveAttribute('href', href)
		}
	})

	test('settings button expands sub-menu', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		// Settings button is at bottom of nav, may need JS click
		await page.evaluate(() => document.querySelector('.settings-button')?.dispatchEvent(new Event('click', { bubbles: true })))
		await expect(page.getByText('Pipelines')).toBeVisible()
		await expect(page.getByText('Configuration')).toBeVisible()
	})

	test('clicking nav item navigates', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		const nav = sidebarNav(page)
		await nav.getByRole('link', { name: 'Clients' }).click()
		await expect(page).toHaveURL(/.*clients/)
	})
})
