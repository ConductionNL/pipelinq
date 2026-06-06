// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// REQ-CR-001: Reporting dashboard loads with KPI cards visible.
// @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
import { test, expect } from '@playwright/test'

test.describe('Rapportage (Reporting)', () => {

	test.beforeEach(async ({ page }) => {
		// Deep-link `goto('/rapportage')` lands on the app shell but the
		// manifest-driven SPA router resets to the Dashboard. Navigate via the
		// in-app "Reporting" sidebar link instead so the rapportage view mounts.
		await page.goto('/apps/pipelinq/')
		await expect(page.locator('body')).not.toContainText('Internal Server Error')
		const nav = page.locator('[id^="app-navigation"]').first()
		await nav.getByRole('link', { name: 'Reporting' }).click()
	})

	/**
	 * REQ-CR-001: Dashboard loads with KPI cards.
	 *
	 * Verifies that the Rapportage page is reachable, the heading renders,
	 * and the four KPI cards are present in the DOM.
	 *
	 * @spec openspec/changes/contactmomenten-rapportage/tasks.md#task-6
	 */
	test('REQ-CR-001: rapportage dashboard loads with KPI cards', async ({ page }) => {
		// Page heading should be visible.
		await expect(
			page.getByRole('heading', { name: /Reporting Dashboard|Rapportagedashboard/i }),
		).toBeVisible({ timeout: 15000 })

		// Date range selector buttons.
		const dateButtons = page.locator('.date-range-selector .button-vue, .date-range-selector button')
		await expect(dateButtons.first()).toBeVisible({ timeout: 10000 })

		// KPI grid container should be in the DOM.
		const kpiGrid = page.locator('.kpi-grid')
		await expect(kpiGrid).toBeVisible({ timeout: 10000 })

		// Four KPI cards present.
		const kpiCards = kpiGrid.locator('.kpi-card')
		await expect(kpiCards).toHaveCount(4)
	})

	test('rapportage page navigates to channel analytics', async ({ page }) => {
		// Wait for the dashboard to render.
		await expect(page.locator('.rapportage-links')).toBeVisible({ timeout: 15000 })

		// Click Channel Analytics button.
		const channelBtn = page.getByRole('button', { name: /Channel Analytics|Kanaalanalyse/i })
		await expect(channelBtn).toBeVisible()
		await channelBtn.click()

		// The manifest-driven SPA router can rewrite the URL on in-app
		// navigation, so assert the channel analytics view rendered instead.
		await expect(
			page.getByRole('heading', { name: /Channel Analytics|Kanaalanalyse/i }),
		).toBeVisible({ timeout: 10000 })
	})

	test('rapportage page navigates to agent performance', async ({ page }) => {
		// Wait for the dashboard to render.
		await expect(page.locator('.rapportage-links')).toBeVisible({ timeout: 15000 })

		// Click Agent Performance button.
		const agentBtn = page.getByRole('button', { name: /Agent Performance|Agentprestaties/i })
		await expect(agentBtn).toBeVisible()
		await agentBtn.click()

		// The manifest-driven SPA router can rewrite the URL on in-app
		// navigation, so assert the agent performance view rendered instead.
		await expect(
			page.getByRole('heading', { name: /Agent Performance|Agentprestaties/i }),
		).toBeVisible({ timeout: 10000 })
	})

	test('channel analytics page loads', async ({ page }) => {
		await page.goto('/apps/pipelinq/rapportage/channels')
		await expect(
			page.getByRole('heading', { name: /Channel Analytics|Kanaalanalyse/i }),
		).toBeVisible({ timeout: 15000 })
	})

	test('agent performance page loads', async ({ page }) => {
		await page.goto('/apps/pipelinq/rapportage/agents')
		await expect(
			page.getByRole('heading', { name: /Agent Performance|Agentprestaties/i }),
		).toBeVisible({ timeout: 15000 })
	})
})
