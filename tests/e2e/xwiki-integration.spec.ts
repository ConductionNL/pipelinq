import { test, expect } from '@playwright/test'

/**
 * E2E coverage for the xwiki-integration change.
 *
 * 10.3 — Dashboard widget renders (or shows the unavailable message).
 * 10.4 — Sidebar tab on the Client detail renders.
 *
 * Both tests use the lightweight "renders OR shows unavailable" assertion
 * shape (rather than mandating xWiki being live) because the dev compose
 * stack does not always have xWiki running on port 8088, and the proxy is
 * specifically built to degrade gracefully when xWiki is unreachable.
 */
test.describe('xWiki Integration', () => {
	test('dashboard renders the knowledge-base widget or unavailable message', async ({
		page,
	}) => {
		// The knowledge-base widget lives on the Operational overview dashboard
		// after the IA dashboard split, not the landing Commercial overview.
		await page.goto('/apps/pipelinq/#/operational')
		// Wait for the manifest shell to mount before checking widgets.
		await page
			.locator('#content-vue')
			.waitFor({ state: 'visible', timeout: 15000 })
			.catch(() => {})

		// Either the widget title or the unavailable message must be visible.
		const widgetTitle = page
			.getByText('Knowledge base', { exact: false })
			.first()
		const unavailable = page
			.getByText('xWiki integration unavailable', { exact: false })
			.first()
		await expect
			.poll(
				async () => {
					const a = await widgetTitle.isVisible().catch(() => false)
					const b = await unavailable.isVisible().catch(() => false)
					return a || b
				},
				{
					timeout: 15000,
					message: 'xWiki widget should render or report unavailable',
				},
			)
			.toBe(true)
	})

	test('client detail sidebar exposes the Knowledge base tab', async ({
		page,
	}) => {
		await page.goto('/apps/pipelinq/#/clients')
		await page
			.locator('#content-vue')
			.waitFor({ state: 'visible', timeout: 15000 })
			.catch(() => {})

		// Open the first client detail row. The list view exposes rows as
		// links; if there are no clients seeded, we still expect the index
		// header to be visible and the test passes trivially (the tab can
		// only be asserted on a detail page).
		const firstRow = page.getByRole('row').nth(1)
		if (await firstRow.isVisible().catch(() => false)) {
			await firstRow.click()

			// Wait for the DETAIL route, not merely for #content-vue to be
			// visible. The index already satisfies that locator, so the old
			// wait returned immediately and the poll below started while the
			// app was still on the list — burning its budget on the wrong page.
			await page
				.waitForURL(/#\/clients\/[^/]+/, { timeout: 15000 })
				.catch(() => {})

			// First: the detail page actually resolved. This is the claim in
			// this test's own name — "not a blank/404 shell" — and it holds
			// whether or not xWiki is reachable, so it is asserted
			// unconditionally and is what stops the softer check below from
			// passing over a broken page.
			await expect(page.locator('#content-vue')).toBeVisible({
				timeout: 15000,
			})

			// Then the tab, with the SAME tolerance the dashboard test above
			// uses, and for the reason this file's header already gives: the
			// compose stack does not always have xWiki running on 8088, and the
			// proxy is built to degrade gracefully when it is unreachable.
			//
			// The header says both tests use the "renders OR reports
			// unavailable" shape. Only the dashboard one did; this one demanded
			// a live xWiki, and went red whenever there wasn't one. Measured on
			// development: passed on the first attempt of run 33034938378, then
			// failed BOTH attempts of run 33041699146 with no code change
			// between them. A 30s budget did not help, because the tab is
			// absent rather than slow.
			//
			// The sidebar tab is labelled "Knowledge base" (XWikiSidebarTab) —
			// distinct from the leaf-flavoured "Knowledge" tab (CnXwikiTab).
			const tab = page.getByRole('tab', { name: /Knowledge base/i }).first()
			const fallback = page.getByText('Knowledge base').first()
			const unavailable = page
				.getByText('xWiki integration unavailable', { exact: false })
				.first()
			await expect
				.poll(
					async () => {
						return (
							(await tab.isVisible().catch(() => false))
							|| (await fallback.isVisible().catch(() => false))
							|| (await unavailable.isVisible().catch(() => false))
						)
					},
					{
						timeout: 30000,
						message:
							'the Knowledge base tab should render, or the integration should report itself unavailable',
					},
				)
				.toBe(true)
		}
	})
})
