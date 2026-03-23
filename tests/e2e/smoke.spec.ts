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
		const nav = page.locator('nav').first()
		await expect(nav).toBeVisible({ timeout: 10000 })
	})
})
