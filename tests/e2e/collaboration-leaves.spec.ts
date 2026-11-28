/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The collaboration leaves mounted on the deal and client pages
 * (leaf-integration-broadening 4.1).
 *
 * LeadDetail carries three leaf tabs in its body strip: "Deal room" (Talk),
 * "Board" (Deck) and "Intake forms" (Forms). ClientDetail carries "Client
 * room" (Talk). Each must render either its leaf widget or the library's
 * integration-unavailable state when the Nextcloud app behind it is absent on
 * the CI instance: never a hard error, and never a missing tab.
 *
 * Written from the change's scenarios; it waits for a seeded instance and is
 * not run as part of the build.
 *
 * @spec openspec/changes/leaf-integration-broadening/specs/collaboration-leaves/spec.md#requirement-deck-leaf-widget-on-leaddetail
 * @spec openspec/changes/leaf-integration-broadening/specs/collaboration-leaves/spec.md#requirement-talk-rooms-on-client-and-lead-detail
 * @spec openspec/changes/leaf-integration-broadening/specs/collaboration-leaves/spec.md#requirement-forms-leaf-widget-on-leaddetail
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { assertNoHardError, openApp, trackPipelinqErrors } from './helpers/pipelinq.ts'

/**
 * Call OpenRegister's object API from inside the page, so the request carries
 * the session and its CSRF token.
 *
 * @param page   The page to run in.
 * @param method The HTTP method.
 * @param path   The path under /index.php/apps/openregister/api/objects/.
 * @param body   The body, for a write.
 *
 * @return The status and the parsed body.
 */
async function objectApi(
	page: Page,
	method: 'GET' | 'POST',
	path: string,
	body?: Record<string, unknown>,
): Promise<{ status: number; data: any }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(`/index.php/apps/openregister/api/objects/${path}`, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken:
						document.querySelector('head[data-requesttoken]')?.getAttribute('data-requesttoken') ?? '',
				},
				body: body ? JSON.stringify(body) : undefined,
			})
			const text = await res.text()
			try {
				return { status: res.status, data: JSON.parse(text) }
			} catch {
				return { status: res.status, data: text }
			}
		},
		{ method, path, body },
	)
}

/**
 * The id of a created or listed object.
 *
 * @param object The object.
 *
 * @return The id, or null.
 */
function idOf(object: any): string | null {
	return object?.id ?? object?.uuid ?? object?.['@self']?.id ?? null
}

/**
 * Seed a client and a lead on the first pipeline.
 *
 * @param page The page to run in.
 *
 * @return The client and lead ids.
 */
async function seed(page: Page): Promise<{ clientId: string; leadId: string }> {
	const stamp = Date.now()
	const client = await objectApi(page, 'POST', 'pipelinq/client', {
		name: `E2E leaves client ${stamp}`,
		type: 'organization',
		contactsUid: `e2e-leaves-${stamp}`,
	})
	expect(idOf(client.data), `could not seed a client: HTTP ${client.status}`).toBeTruthy()

	const pipelines = await objectApi(page, 'GET', 'pipelinq/pipeline?_limit=1')
	const pipelineId = idOf(pipelines.data?.results?.[0])
	expect(pipelineId, 'the instance has no pipeline to put a lead on').toBeTruthy()

	const lead = await objectApi(page, 'POST', 'pipelinq/lead', {
		title: `E2E leaves lead ${stamp}`,
		pipeline: pipelineId,
		client: idOf(client.data),
	})
	expect(idOf(lead.data), `could not seed a lead: HTTP ${lead.status}`).toBeTruthy()

	return { clientId: idOf(client.data) as string, leadId: idOf(lead.data) as string }
}

/**
 * Open a tab by its label and expect its widget or the unavailable state.
 *
 * @param page  The page.
 * @param label The tab label.
 */
async function expectLeafTab(page: Page, label: string): Promise<void> {
	const tab = page.getByRole('tab', { name: label }).first()
	await expect(tab, `the "${label}" tab is missing`).toBeVisible({ timeout: 30000 })
	await tab.click()

	const panel = page.getByRole('tabpanel').filter({ hasText: /./ }).first()
	await expect(panel, `the "${label}" tab shows nothing`).toBeVisible({ timeout: 30000 })
	await assertNoHardError(page)
}

test.describe('collaboration leaves on the deal and client pages', () => {
	test.setTimeout(120000)

	test('LeadDetail renders the Deal room, Board and Intake forms tabs', async ({ page }) => {
		const errors = trackPipelinqErrors(page)
		await openApp(page)
		const { leadId } = await seed(page)

		await page.goto(`/apps/pipelinq/leads/${leadId}`)
		for (const label of ['Deal room', 'Board', 'Intake forms']) {
			await expectLeafTab(page, label)
		}

		expect(errors(), 'the lead page logged pipelinq errors').toEqual([])
	})

	test('ClientDetail renders the Client room', async ({ page }) => {
		const errors = trackPipelinqErrors(page)
		await openApp(page)
		const { clientId } = await seed(page)

		await page.goto(`/apps/pipelinq/clients/${clientId}`)
		await expect(
			page.getByText('Client room', { exact: true }).first(),
			'the client page does not show the Client room',
		).toBeVisible({ timeout: 30000 })
		await assertNoHardError(page)

		expect(errors(), 'the client page logged pipelinq errors').toEqual([])
	})
})
