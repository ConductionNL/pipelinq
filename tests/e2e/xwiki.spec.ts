import { test, expect } from '@playwright/test'

/**
 * E2E tests for xWiki integration.
 *
 * These tests verify that the xWiki components render correctly in the app
 * even when the xWiki instance is not reachable (the graceful degradation path).
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.3
 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.4
 */

test.describe('xWiki Dashboard Widget', () => {
	/**
	 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.3
	 */
	test('renders xWiki widget on dashboard (available or graceful degradation)', async ({ page }) => {
		await page.goto('/apps/pipelinq/')
		// Wait for the app to load.
		await page.waitForLoadState('networkidle')

		// The widget should either show content or the unavailability message.
		const widget = page.locator('.xwiki-widget, [data-widget-id="xwiki-kennisbank"]').first()
		await expect(widget).toBeVisible({ timeout: 15000 })

		// One of these two states is acceptable.
		const hasArticles = await page.locator('.xwiki-article-list').isVisible()
		const hasUnavailable = await page.locator('.xwiki-widget__unavailable').isVisible()
		const isLoading = await page.locator('.xwiki-widget__loading').isVisible()

		// Widget must render in at least one valid state.
		expect(hasArticles || hasUnavailable || isLoading).toBe(true)
	})
})

test.describe('xWiki Sidebar Tab', () => {
	/**
	 * @spec openspec/changes/xwiki-integration/tasks.md#task-10.4
	 */
	test.skip('client detail shows Kennisbank sidebar tab', async ({ page }) => {
		// This test requires a client object to exist in OpenRegister.
		// Marked skip — smoke-level test for CI environments with seed data.
		await page.goto('/apps/pipelinq/clients')
		await page.waitForLoadState('networkidle')

		// Navigate to first client if available.
		const firstRow = page.locator('.nc-table-row, [data-cy="client-row"]').first()
		const hasClients = await firstRow.isVisible({ timeout: 5000 }).catch(() => false)

		if (!hasClients) {
			test.skip()
		}

		await firstRow.click()
		await page.waitForLoadState('networkidle')

		// Sidebar tab labeled "Kennisbank" should be visible.
		const kennisbankTab = page.getByRole('tab', { name: /Kennisbank/i })
		await expect(kennisbankTab).toBeVisible({ timeout: 10000 })

		await kennisbankTab.click()

		// After clicking, the sidebar tab content should render.
		const sidebarContent = page.locator('.xwiki-sidebar')
		await expect(sidebarContent).toBeVisible({ timeout: 5000 })
	})
})
