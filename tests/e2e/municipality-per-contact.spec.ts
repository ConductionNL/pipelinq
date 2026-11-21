/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One KCC desk serves several municipalities (requests-municipality-per-contact
 * task 3.1): the Clients list names a client's municipality, and the Tickets
 * and Queue lists narrow to one municipality.
 *
 *   @e2e requests-municipality-per-contact::a-kcc-employee-sees-whose-resident-is-calling
 *   @e2e requests-municipality-per-contact::a-team-lead-looks-at-one-municipality-s-queue
 *   @e2e requests-municipality-per-contact::a-handler-corrects-the-municipality
 *   @e2e requests-municipality-per-contact::a-ticket-follows-its-client
 *   @e2e requests-municipality-per-contact::a-resident-submits-a-request-on-the-goes-portal
 *
 * Seeding: three `servedMunicipality` objects named after the spec's desk
 * (Goes, Borsele, Reimerswaal) with the run stamp in the name, so a match is
 * this run's and not a colleague's. The Municipality column is hidden by
 * default on the lists, so the test turns it on in the index sidebar's
 * Columns tab, the way a user does. The municipality filter is applied
 * through the list's address (`?municipality=<uuid>`), which is the same
 * filter the facet writes.
 *
 * Two scenarios are not reachable from a browser and stay `test.fixme`, with
 * PHPUnit covering them: the portal stamp needs a portal tenant linked to a
 * municipality (PortalRequestServiceTest), and the client's municipality is
 * copied in TicketService::save(), which the internal create paths use but
 * the OpenRegister object API this suite seeds through does not
 * (TicketServiceTest).
 *
 * @spec openspec/changes/requests-municipality-per-contact/specs/served-municipality/spec.md#requirement-clients-contacts-and-tickets-name-their-municipality-req-rmc-001
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const OCS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const GOES = `Goes ${RUN}`
const BORSELE = `Borsele ${RUN}`
const REIMERSWAAL = `Reimerswaal ${RUN}`

let api: APIRequestContext
const made: Array<{ schema: string; id: string }> = []

/**
 * Create one object and remember it for clean-up.
 *
 * @param schema The pipelinq schema.
 * @param data The fields.
 */
async function seed(schema: string, data: Record<string, unknown>): Promise<string> {
	const res = await api.post(`${OBJECTS}/${schema}`, { data })
	expect(
		res.ok(),
		`seeding ${schema}: ${res.status()} ${(await res.text()).slice(0, 300)}`,
	).toBeTruthy()
	const body = await res.json()
	const id = String(body.id ?? body['@self']?.id ?? body.uuid)
	made.push({ schema, id })
	return id
}

/**
 * Read one object.
 *
 * @param schema The pipelinq schema.
 * @param id The object.
 */
async function read(schema: string, id: string): Promise<Record<string, unknown>> {
	const res = await api.get(`${OBJECTS}/${schema}/${id}`)
	expect(res.ok(), `reading ${schema} ${id}: ${res.status()}`).toBeTruthy()
	return await res.json()
}

/**
 * Turn on the hidden Municipality column through the index sidebar.
 *
 * @param page The page, on an index page.
 */
async function showMunicipalityColumn(page: Page): Promise<void> {
	const header = page.getByRole('columnheader', { name: 'Municipality' })
	if (await header.isVisible()) {
		return
	}
	const toggle = page.locator('.app-sidebar__toggle').first()
	if (await toggle.isVisible()) {
		await toggle.click()
	}
	await page.getByRole('tab', { name: /columns/i }).click()
	await page
		.locator('.cn-sidebar-columns__group-content')
		.getByText('Municipality', { exact: true })
		.click()
	await expect(header).toBeVisible({ timeout: 10_000 })
}

test.describe('One desk, several municipalities', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(150_000)

	const ids: Record<string, string> = {}

	test.beforeAll(async () => {
		api = await request.newContext({
			baseURL: BASE_URL,
			httpCredentials: ADMIN,
			extraHTTPHeaders: OCS,
		})
		ids.goes = await seed('servedMunicipality', { name: GOES, code: '0664' })
		ids.borsele = await seed('servedMunicipality', {
			name: BORSELE,
			code: '0654',
		})
		ids.reimerswaal = await seed('servedMunicipality', {
			name: REIMERSWAAL,
			code: '0703',
		})
		ids.client = await seed('client', {
			name: `E2E-RMC-${RUN} resident`,
			type: 'person',
			municipality: ids.borsele,
		})
		ids.ticketGoes = await seed('ticket', {
			title: `E2E-RMC-${RUN} Goes question`,
			ticketType: 'request',
			status: 'new',
			channel: 'phone',
			municipality: ids.goes,
		})
		ids.ticketBorsele = await seed('ticket', {
			title: `E2E-RMC-${RUN} Borsele question`,
			ticketType: 'request',
			status: 'new',
			channel: 'phone',
			client: ids.client,
			municipality: ids.borsele,
		})
	})

	test.afterAll(async () => {
		for (const { schema, id } of made.reverse()) {
			await api.delete(`${OBJECTS}/${schema}/${id}`)
		}
		await api.dispose()
	})

	test("the Clients list names the resident's municipality, not its uuid", async ({
		page,
	}) => {
		await gotoAppRoute(
			page,
			`/clients?_search=${encodeURIComponent(`E2E-RMC-${RUN}`)}`,
		)
		const row = page
			.getByRole('row')
			.filter({ hasText: `E2E-RMC-${RUN} resident` })
		await expect(row).toBeVisible({ timeout: 20_000 })
		await showMunicipalityColumn(page)
		await expect(row).toContainText(BORSELE)
		await expect(row).not.toContainText(ids.borsele)
	})

	for (const route of ['/tickets', '/queue']) {
		test(`filtering ${route} on one municipality lists only its tickets`, async ({
			page,
		}) => {
			await gotoAppRoute(
				page,
				`${route}?municipality=${ids.goes}&_search=${encodeURIComponent(`E2E-RMC-${RUN}`)}`,
			)
			await expect(
				page
					.getByRole('row')
					.filter({ hasText: `E2E-RMC-${RUN} Goes question` }),
			).toBeVisible({ timeout: 20_000 })
			await expect(
				page
					.getByRole('row')
					.filter({ hasText: `E2E-RMC-${RUN} Borsele question` }),
			).toHaveCount(0)
		})
	}

	test("a handler corrects the ticket's municipality; the client keeps its own", async ({
		page,
	}) => {
		await gotoAppRoute(page, `/tickets/${ids.ticketBorsele}`)
		const edit = page.getByTestId('cn-detail-page-edit')
		await expect(edit).toBeVisible({ timeout: 20_000 })
		await edit.click()
		const form = page.locator('[data-testid-modal="cn-form-dialog"]')
		await expect(form).toBeVisible()
		const picker = form.locator('[data-cn-field="municipality"] input').first()
		await picker.click()
		await picker.fill(REIMERSWAAL)
		await page.getByRole('option', { name: REIMERSWAAL }).click()
		await form.getByRole('button', { name: 'Save', exact: true }).click()
		await expect(form).toBeHidden({ timeout: 20_000 })

		await expect(page.getByTestId('cn-detail-page')).toContainText(REIMERSWAAL, {
			timeout: 20_000,
		})
		expect((await read('ticket', ids.ticketBorsele)).municipality).toBe(
			ids.reimerswaal,
		)
		expect((await read('client', ids.client)).municipality).toBe(ids.borsele)
	})

	test.fixme("a new request for a client takes the client's municipality", async () => {
		// TicketService::save() copies it; that path is reached by the internal
		// create flows (CTI, intake), not by the OpenRegister object API this
		// suite seeds through. Covered by TicketServiceTest until a browser
		// create path that goes through TicketService is named here.
	})

	test.fixme('a request submitted on the Goes portal carries Goes', async () => {
		// Needs a portal tenant whose id is the municipality's portalTenantId
		// and a resident session on that portal. Covered by
		// PortalRequestServiceTest.
	})
})
