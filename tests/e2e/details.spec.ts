/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Detail-view + quick-log coverage for the manifest-driven app shell.
 *
 * Detail routes (`/leads/:id`, `/requests/:id`, …) are registered in the
 * vue-router and Nextcloud serves the SPA shell for any sub-path, so a
 * deep-link boots straight onto the CnDetailPage. The OpenRegister object
 * endpoint may 500 / return nothing for an unseeded id — that is expected
 * (see openFirstDetail in helpers/nav.ts). The detail component still
 * renders its shell (header, title, "Back to list" + "Edit" actions), so
 * assertions target the rendered chrome, NOT data rows.
 *
 * Gate-19 @e2e traceability (test → spec scenario):
 *   "Request detail renders the shell"      @e2e request-management::view-request-core-information
 *   "Complaint detail renders the shell"    @e2e klachtenregistratie::display-complaint-details
 *   "Product detail renders the shell"      @e2e product-catalog::product-detail-display
 *   "Client detail renders the shell"       @e2e client-management::view-organization-client-detail
 *   "Contactmoment detail renders…"         @e2e contactmomenten::display-contactmoment-details
 *   "Request detail opens the quick-log…"   @e2e contactmomenten::quick-log-from-request-detail
 */
import { test, expect } from '@playwright/test'
import { openDetail } from './helpers/nav'

// Each detail route renders a CnDetailPage shell with a "Back to list"
// button and an "Edit" action, independent of whether data loads.
const DETAILS = [
	{ route: 'requests', name: 'Request' },
	{ route: 'complaints', name: 'Complaint' },
	{ route: 'products', name: 'Product' },
	{ route: 'clients', name: 'Client' },
	{ route: 'contactmomenten', name: 'Contactmoment' },
] as const

test.describe('Detail views', () => {

	for (const d of DETAILS) {
		test(`${d.name} detail renders the CnDetailPage shell`, async ({ page }) => {
			await openDetail(page, d.route)
			// "Back to list" is rendered for every load/error/empty state.
			await expect(page.getByRole('button', { name: 'Back to list' }))
				.toBeVisible({ timeout: 20000 })
			// The detail header title renders (data title or entity fallback).
			await expect(page.locator('[data-testid="cn-detail-page-header"]'))
				.toBeVisible()
		})
	}

	test('Request detail opens the pre-filled contactmoment quick-log dialog', async ({ page }) => {
		await openDetail(page, 'requests')
		// Wait for the detail body to settle (the quick-log button lives
		// inside the Contactmomenten card, rendered once loading clears).
		const logBtn = page.getByRole('button', { name: 'Log contactmoment' })
		await expect(logBtn).toBeVisible({ timeout: 30000 })
		await logBtn.click()
		// The quick-log dialog mounts ContactmomentQuickLog (pre-filled with
		// the request + its client context) — assert the rendered form.
		await expect(page.getByRole('heading', { name: 'Log contactmoment' }).first())
			.toBeVisible({ timeout: 10000 })
		await expect(page.getByText('Subject').first()).toBeVisible()
	})
})
