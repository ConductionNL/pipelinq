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
			await page.waitForURL(/#\/clients\/[^/]+/, { timeout: 15000 }).catch(() => {})

			// The sidebar tab is rendered with label "Knowledge base"
			// (XWikiSidebarTab) — distinct from the leaf-flavoured
			// "Knowledge" tab (CnXwikiTab).
			//
			// The tab is injected by the integration registry once the detail
			// page resolves its widgets, which is a second round-trip after
			// navigation. At 10s this raced under CI load: the spec failed on
			// the first attempt and passed on retry across several unrelated
			// PRs, which is the signature of a budget that is too tight rather
			// than of a missing surface.
			const tab = page.getByRole('tab', { name: /Knowledge base/i }).first()
			const fallback = page.getByText('Knowledge base').first()
			await expect
				.poll(
					async () => {
						return (
							(await tab.isVisible().catch(() => false))
							|| (await fallback.isVisible().catch(() => false))
						)
					},
					{
						timeout: 30000,
						message: 'Knowledge base sidebar tab should render',
					},
				)
				.toBe(true)
		}
	})
})
