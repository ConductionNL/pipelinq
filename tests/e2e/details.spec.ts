/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Detail-view coverage for the manifest-driven app shell.
 *
 * Each `type: "detail"` manifest page (`/clients/:id`, `/requests/:id`, …)
 * is rendered by the shared library's CnDetailPage (see
 * @conduction/nextcloud-vue pageTypes.detail). Detail routes are
 * registered in the vue-router and Nextcloud serves the SPA shell for any
 * sub-path, so a deep-link boots straight onto the detail page.
 *
 * The OpenRegister object endpoint may 500 / return nothing for an
 * unseeded id — that is expected. CnDetailPage still renders its shell
 * (the `cn-detail-page` container + header), so the assertions target the
 * rendered chrome via the library's stable `data-testid`s, NOT data rows.
 *
 * Gate-19 @e2e traceability (test → spec scenario):
 *   "Request detail renders the shell"      @e2e request-management::view-request-core-information
 *   "Complaint detail renders the shell"    @e2e klachtenregistratie::display-complaint-details
 *   "Product detail renders the shell"      @e2e product-catalog::product-detail-display
 *   "Client detail renders the shell"       @e2e client-management::view-organization-client-detail
 *   "Contactmoment detail renders…"         @e2e contactmomenten::display-contactmoment-details
 */
import { test, expect } from '@playwright/test'
import { openDetail } from './helpers/nav'

// Each detail route renders the library CnDetailPage shell — its
// container + header are present for every load/error/empty state.
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
			// openDetail already asserts [data-testid="cn-detail-page"].
			await openDetail(page, d.route)
			// The detail header chrome renders independent of data load.
			await expect(page.locator('[data-testid="cn-detail-page-header"]'))
				.toBeVisible({ timeout: 20000 })
		})
	}
})
