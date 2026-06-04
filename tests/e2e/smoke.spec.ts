import { test, expect } from '@playwright/test'

test.describe('Smoke', () => {

	test('app loads without server errors', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		await expect(page).toHaveURL(/.*pipelinq/)
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		await expect(page.locator('body')).not.toContainText('not installed')
	})

	test('sidebar navigation is visible', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		// The in-app left navigation renders the Clients route link.
		await expect(
			page.locator('a[href="/apps/pipelinq/clients"]').first(),
		).toBeVisible({ timeout: 20000 })
	})
})
