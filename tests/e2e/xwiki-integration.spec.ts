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
		await page.goto('/apps/pipelinq/operational')
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
		// Reach the detail page by URL, NOT by clicking a list row.
		//
		// A row click cannot get here at all: the manifest index toggles its
		// filter/columns sidebar instead of routing to /clients/:id, and the page
		// sets showViewAction:false so no in-row "View" affordance reaches it
		// either. tests/e2e/workflows/client-crud.spec.ts already carries a
		// test.fixme spelling this out.
		//
		// This test used to click a row, then swallow the navigation failure with
		// `.catch(() => {})` and poll for the tab — on the INDEX page, where no
		// tab could ever appear. So it reported "the Knowledge base tab is
		// missing" when the truth was "this never left the client list". That is
		// my own earlier fix mis-stating its own failure, and it is why the tab
		// wired up in this PR still read as absent.
		const res = await page.request.get(
			'/index.php/apps/openregister/api/objects/pipelinq/client?_limit=1',
			{ headers: { 'OCS-APIRequest': 'true' } },
		)
		test.skip(
			!res.ok(),
			`no client list available (HTTP ${res.status()}) — cannot reach a detail page`,
		)
		const first = (await res.json())?.results?.[0]
		test.skip(!first?.id, 'no seeded client to open a detail page for')

		await page.goto(`/apps/pipelinq/clients/${first.id}`)

		// The detail route resolved. Asserted, never caught: if this fails the
		// message must say the page did not load, not that a tab is missing.
		await expect(page).toHaveURL(/\/clients\/[^/]+/, { timeout: 15000 })
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })

		// OPEN THE SIDEBAR. The tab lives inside it, and it mounts CLOSED:
		// CnDetailPage declares `sidebarOpen: { type: Boolean, default: false }`
		// — "Defaults to closed so the detail content fills the page; the user
		// opens it on demand via the header sidebar-toggle".
		//
		// So reaching the detail page was necessary but not sufficient. The poll
		// below was still waiting on an element inside a shut container, where
		// neither the tab NOR the unavailable notice can become visible — which
		// is why widening the assertion to accept "reports itself unavailable"
		// did not help, and why declaring the tab in the manifest did not
		// either. The failure was never about xWiki being reachable.
		//
		// Measured on a seeded instance (pipelinq 0.3.1, client detail page):
		// `.app-sidebar__toggle` present, zero `[role=tab]` nodes anywhere;
		// after clicking it the sidebar mounts and renders its manifest-declared
		// tab ("History", with audit rows). This PR declares "Knowledge base"
		// into that same `sidebar.tabs` array, so it surfaces the same way.
		//
		// NcAppSidebar renders this toggle itself when closed, so it is the
		// affordance a real user would use. Tolerated rather than asserted: if a
		// future default opens the sidebar for us there is no toggle to click,
		// and the assertions below still hold.
		const sidebarToggle = page.locator('.app-sidebar__toggle').first()
		if (await sidebarToggle.isVisible().catch(() => false)) {
			await sidebarToggle.click()
		}

		// Then the tab, with the same tolerance the dashboard test above uses:
		// the compose stack does not always have xWiki on 8088, and the proxy is
		// built to degrade gracefully when it is unreachable.
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
						'on the client DETAIL page, the Knowledge base tab should render, or the integration should report itself unavailable',
				},
			)
			.toBe(true)
	})
})
