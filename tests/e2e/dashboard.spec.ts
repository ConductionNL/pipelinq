import { test, expect } from '@playwright/test'

test.describe('Dashboard', () => {

	test.beforeEach(async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		await expect(page.getByRole('heading', { name: 'Dashboard', level: 2 })).toBeVisible({ timeout: 10000 })
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

	test('shows dashboard sections', async ({ page }) => {
		await expect(page.getByRole('heading', { name: 'Requests by Status', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'My Work', level: 3 })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Client Overview', level: 3 })).toBeVisible()
	})
})
