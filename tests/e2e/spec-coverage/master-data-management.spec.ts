/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/master-data-management/spec.md.
 *
 * WHAT IS OBSERVABLE THROUGH A BROWSER, AND WHAT IS NOT
 * ----------------------------------------------------
 * Almost nothing of this capability is. Master Data Management was handed to
 * OpenRegister wholesale (ADR-045 #D): survivorship, duplicate detection, the
 * merge engine, the trust-tier resolver, the quality score and every steward
 * screen now live there, driven by the `x-openregister-*` annotations on the
 * `masterEntity` schema. What pipelinq keeps is a read helper
 * (`MdmObjectRepository`), one event listener (`ObjectsMergedSyncListener`), two
 * repair steps and the schema declaration itself.
 *
 * The app therefore has NO MDM user interface at all — not a page, not a nav
 * entry, not even the interim "Data quality" deep-link that the spec text still
 * describes: `src/manifest.d/90-master-data-management.json` declares
 * `"menu": []` and `"pages": []` and exists only as a record of the removal.
 * Every remaining scenario is an in-process event path, a one-shot `IRepairStep`
 * or a cross-app OpenRegister surface, and each carries a reason-bearing
 * `@e2e exclude` in the spec naming the PHPUnit class that asserts it.
 *
 * The ONE thing a browser can still decide is REQ-MDM-010: whether pipelinq's
 * retired read-API is really gone from the router. That is asserted below.
 */
import { test, expect } from '@playwright/test'

import { openApp, assertNoHardError } from '../helpers/pipelinq'

/**
 * The retired MDM read-API paths (`retire-mdm-sync-queue`, ADR-022 / ADR-045 #D).
 * `MdmApiController` and its `mdmApi#queryByNaturalKey` / `mdmApi#show` routes
 * were deleted; downstream apps read master entities from OpenRegister's
 * `/apps/openregister/api/objects` surface instead.
 */
const RETIRED_MDM_PATHS = [
	'/apps/pipelinq/api/mdm/master',
	'/apps/pipelinq/api/mdm/master?naturalKey=NL123456789B01',
	'/apps/pipelinq/api/mdm/master/550e8400-e29b-41d4-a716-446655440002',
]

/**
 * A live pipelinq API route, used as the POSITIVE CONTROL. `xWiki#status` is
 * registered in appinfo/routes.php as `GET /api/xwiki/status`, is
 * `#[NoAdminRequired]` + `#[NoCSRFRequired]`, and returns a JSONResponse on
 * BOTH of its branches (the try and the catch), so it answers
 * `application/json` on any instance whether or not xWiki is reachable.
 */
const LIVE_API_CONTROL = '/apps/pipelinq/api/xwiki/status'

// @e2e openspec/specs/master-data-management/spec.md#no-pipelinq-read-api-routes-remain
test('the retired /api/mdm/master read-API is not routed to any controller', async ({
	page,
}) => {
	// Requests ride the authenticated browser context, so a 401 cannot be
	// mistaken for "the route is gone".
	await openApp(page)

	/*
	 * WHY THIS IS A CONTENT-TYPE ASSERTION AND NOT `expect(status).toBe(404)`.
	 * appinfo/routes.php ends with an SPA catch-all —
	 *
	 *   ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET',
	 *    'requirements' => ['path' => '.*'], 'defaults' => ['path' => '']]
	 *
	 * — which is reached by ANY unmatched GET under /apps/pipelinq, including
	 * one under /api/. A deleted API route is therefore answered 200 with the
	 * Vue app's HTML shell, not 404, and a status-code assertion would be
	 * checking something the router never promised. What DOES separate a live
	 * API route from a deleted one is the response media type: a routed
	 * controller returning a JSONResponse answers `application/json`; the
	 * catch-all (and NC's own error chrome, if the router ever stopped
	 * swallowing these) answers `text/html`. The assertion below holds under
	 * either behaviour.
	 */
	const control = await page.request.get(LIVE_API_CONTROL)
	expect(
		control.status(),
		'positive control: a LIVE pipelinq API route must answer 200',
	).toBe(200)
	expect(
		control.headers()['content-type'] ?? '',
		'positive control: a routed JSONResponse must answer application/json — without this, '
			+ '"no JSON here" below would be true of every URL on the instance and would prove nothing',
	).toContain('application/json')

	for (const path of RETIRED_MDM_PATHS) {
		const response = await page.request.get(path)
		expect(
			response.headers()['content-type'] ?? '',
			`${path} must not be served by a JSON controller — the MDM read-API is retired`,
		).not.toContain('application/json')

		// Belt and braces: whatever answered, it is not a master-entity
		// projection. The seeded masterEntity objects
		// (lib/Settings/register.d/90-master-data-management.json) carry
		// `masterId` + `goldenRecord`, so a surviving wrapper would show here.
		const body = await response.text()
		expect(
			body,
			`${path} must not return a master-entity projection`,
		).not.toContain('"masterId"')
		expect(
			body,
			`${path} must not return a master-entity projection`,
		).not.toContain('"goldenRecord"')
	}

	await assertNoHardError(page)
})
