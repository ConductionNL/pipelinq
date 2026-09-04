/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client detail page reads a client's projects OUT OF PLANNINQ.
 *
 * WHY THIS FILE EXISTS. Pipelinq used to own a four-level project WBS of its
 * own, duplicating the project / task / time-entry schemas planninq already
 * had. A schema slug is GLOBAL on a shared OpenRegister, so the two `project`
 * definitions could answer for each other. Planninq keeps them; this page now
 * reads across.
 *
 * ⚠️ THE FAILURE THIS GUARDS IS AN EMPTY TABLE, NOT AN ERROR. A widget pointed
 * at another app's register gets a 404 when that app is absent, and an empty
 * result renders exactly like a client that genuinely has no projects — correct
 * looking, on every client, forever. dossiq shipped precisely that against
 * humaniq for as long as its hours tile existed, which is why gate-55 now
 * requires `requiredApp` on any widget reading a foreign register.
 *
 * So the assertion below is deliberately POSITIVE: it seeds a planninq project
 * against a real pipelinq client and requires that project's name to appear on
 * the client page. An assertion that the section merely *exists* would pass in
 * exactly the broken state this file is about.
 *
 * REQUIRES PLANNINQ. `.github/workflows/code-quality.yml` installs it through
 * `additional-apps`; the repo is `planix` and the app is `planninq`, which
 * differ because the app was renamed and the repo was not. If planninq is
 * missing, this spec FAILS rather than skipping: a skip here cannot be told
 * apart from the defect, and "the optional app was absent" is the single most
 * likely way for this integration to break silently.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

/** A name distinctive enough that finding it on the page proves the read. */
const PROJECT_TITLE = `E2E cross-app project ${Date.now()}`

/**
 * Create one object through OpenRegister's REST API from inside the page, so
 * the request carries the authenticated session and its CSRF token.
 *
 * @param page     The page to run in.
 * @param register The register slug.
 * @param schema   The schema slug.
 * @param body     The object body.
 *
 * @return The created object's id, or null.
 */
async function createObject(
	page: Page,
	register: string,
	schema: string,
	body: Record<string, unknown>,
): Promise<string | null> {
	return await page.evaluate(
		async ({ register, schema, body }) => {
			const res = await fetch(
				`/index.php/apps/openregister/api/objects/${register}/${schema}`,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken:
							document
								.querySelector('head[data-requesttoken]')
								?.getAttribute('data-requesttoken') ?? '',
					},
					body: JSON.stringify(body),
				},
			)
			if (!res.ok) {
				return null
			}
			const created = await res.json()
			return created?.id ?? created?.['@self']?.id ?? null
		},
		{ register, schema, body },
	)
}

test.describe('client projects come from planninq', () => {
	// Two register writes plus a cold shell boot; 60s is not enough.
	test.setTimeout(120000)

	test('a planninq project for this client renders on the pipelinq client page', async ({
		page,
	}) => {
		await openApp(page)

		// Planninq must be installed, or the rest of this test is meaningless.
		// Asserted FIRST and explicitly, so an absent app reports itself rather
		// than surfacing four assertions later as "the project did not appear".
		const planninqServed = await page.evaluate(async () => {
			const res = await fetch(
				'/index.php/apps/openregister/api/objects/planninq/project?_limit=1',
			)
			return res.status
		})
		expect(
			planninqServed,
			'planninq is not installed or its register was never imported, so this page has nothing to read across to. '
				+ 'It is provisioned by additional-apps in code-quality.yml — repo planix, app planninq.',
		).toBeLessThan(400)

		const clientId = await createObject(page, 'pipelinq', 'client', {
			name: `E2E cross-app client ${Date.now()}`,
			type: 'organization',
		})
		expect(clientId, 'could not seed a pipelinq client').toBeTruthy()

		const projectId = await createObject(page, 'planninq', 'project', {
			title: PROJECT_TITLE,
			status: 'active',
			billable: true,
			budgetAmount: 12345,
			client: clientId,
		})
		expect(
			projectId,
			'could not seed a planninq project — the client FK is the whole point of this test',
		).toBeTruthy()

		await page.goto(`/apps/pipelinq/clients/${clientId}`)

		// The project's NAME, not the section heading. A heading renders whether
		// or not the cross-app read returned anything.
		await expect(
			page.getByText(PROJECT_TITLE, { exact: false }).first(),
			'the client page did not show the planninq project seeded against this client — '
				+ 'either the cross-app read did not run, or it ran and returned nothing',
		).toBeVisible({ timeout: 30000 })
	})
})
