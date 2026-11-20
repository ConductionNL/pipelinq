/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * New leads without an owner are shared in turn (pipeline-lead-rotation task
 * 1.4): the admin switch, the cap and pause on the agent profile form, and
 * three leads for one skill ending up two and one.
 *
 *   @e2e pipeline-lead-rotation::three-website-leads-are-shared-by-two-colleagues
 *   @e2e pipeline-lead-rotation::a-lead-made-with-an-assignee-is-left-alone
 *   @e2e pipeline-lead-rotation::everyone-with-the-skill-is-on-leave
 *
 * Two colleagues are made for the run, each with an agent profile carrying a
 * run-unique skill whose category is the leads' category. The switch
 * "Assign new leads in turn" is turned on in Pipelinq's admin settings
 * (General tab, Lead Management section) and saved; its previous value is put
 * back afterwards. The cap and the pause are set through the agent profile's
 * Edit form on the same page.
 *
 * The task names leads "created by the website form". A published web form
 * that targets the lead schema is instance configuration this run cannot
 * assume, and DealCreatedListener assigns any new lead without an owner the
 * same way whatever wrote it, so the leads are created through OpenRegister's
 * object API without an assignee. The 2 and 1 split, the reason line and the
 * paused case are asserted on the stored leads; the reason is also read on
 * LeadDetail.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
 */
import type { APIRequestContext, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { dismissSupportDialog, dismissWalkthrough, gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const SETTINGS = '/index.php/apps/pipelinq/api/settings'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const CATEGORY = `e2e-solar-${RUN}`
const ANNA = `e2e-lrt-anna-${RUN}`
const BRAM = `e2e-lrt-bram-${RUN}`

let admin: APIRequestContext
let previousSwitch = 'false'
let pipelineId = ''
const made: Array<{ schema: string, id: string }> = []
const profiles: Record<string, string> = {}

/**
 * The id of an object as OpenRegister returns it.
 *
 * @param body The response body.
 */
function idOf(body: Record<string, unknown>): string {
	const self = body['@self'] as { id?: string } | undefined
	return String(body.id ?? self?.id ?? body.uuid)
}

/**
 * Create an object and remember it for cleanup.
 *
 * @param schema The schema slug.
 * @param data The object.
 */
async function create(schema: string, data: Record<string, unknown>): Promise<Record<string, unknown>> {
	const created = await admin.post(`${OR}/${schema}`, { data })
	expect(created.ok(), `creating a ${schema}: ${created.status()} ${(await created.text()).slice(0, 300)}`).toBeTruthy()
	const body = await created.json()
	made.push({ schema, id: idOf(body) })
	return body
}

/**
 * Wait until a lead carries an assignment reason (the listener runs deferred).
 *
 * @param id The lead.
 */
async function settledLead(id: string): Promise<Record<string, unknown>> {
	let lead: Record<string, unknown> = {}
	await expect.poll(async () => {
		const read = await admin.get(`${OR}/lead/${id}`)
		lead = await read.json()
		return String(lead.assignmentReason ?? '')
	}, { timeout: 30_000, message: `lead ${id} gets an assignment reason` }).not.toBe('')
	return lead
}

/**
 * Open the agent profile of a colleague in the admin settings and save a cap
 * and the pause switch through its Edit form.
 *
 * @param page The admin's page.
 * @param userId The colleague.
 * @param cap The 30-day cap, or null to leave it empty.
 * @param pause Whether to pause their share.
 */
async function editProfile(page: Page, userId: string, cap: number | null, pause: boolean): Promise<void> {
	const row = page.locator('.agent-item').filter({ has: page.locator('.agent-name', { hasText: userId }) })
	await expect(row).toBeVisible({ timeout: 20_000 })
	await row.getByRole('button', { name: /^(Edit|Bewerken)$/ }).click()
	const editor = page.locator('.agent-item').filter({ has: page.locator('#agent-edit-lead-cap') })
	if (cap !== null) {
		await editor.locator('#agent-edit-lead-cap').fill(String(cap))
	}
	const pauseBox = editor.getByLabel(/Pause lead assignment|Leadtoewijzing pauzeren/)
	if (pause) {
		await pauseBox.check()
	} else {
		await pauseBox.uncheck()
	}
	await editor.getByRole('button', { name: /^(Save|Opslaan)$/ }).click()
	await expect(page.locator('#agent-edit-lead-cap')).toHaveCount(0, { timeout: 15_000 })
}

test.beforeAll(async () => {
	admin = await request.newContext({ baseURL: BASE_URL, httpCredentials: ADMIN, extraHTTPHeaders: HEADERS })

	const settings = await admin.get(SETTINGS)
	expect(settings.ok(), `reading settings: ${settings.status()}`).toBeTruthy()
	previousSwitch = String((await settings.json()).config?.lead_rotation_enabled ?? 'false')

	const pipelines = await admin.get(`${OR}/pipeline`, { params: { _limit: '1' } })
	expect(pipelines.ok(), `reading pipelines: ${pipelines.status()}`).toBeTruthy()
	const first = ((await pipelines.json()).results ?? [])[0]
	expect(first, 'the instance has a pipeline to put leads on').toBeTruthy()
	pipelineId = idOf(first)

	for (const userId of [ANNA, BRAM]) {
		const user = await admin.post('/ocs/v2.php/cloud/users', {
			form: { userid: userId, password: `Lrt-${RUN}-${userId}-42!`, displayName: userId },
		})
		expect(user.ok(), `creating ${userId}: ${user.status()}`).toBeTruthy()
	}

	const skill = await create('skill', { title: `Solar ${RUN}`, categories: [CATEGORY], isActive: true })
	for (const userId of [ANNA, BRAM]) {
		const profile = await create('agentProfile', { userId, skills: [idOf(skill)], isAvailable: true })
		profiles[userId] = idOf(profile)
	}
})

test.afterAll(async () => {
	await admin.put(SETTINGS, { data: { lead_rotation_enabled: previousSwitch } }).catch(() => undefined)
	for (const { schema, id } of made.reverse()) {
		await admin.delete(`${OR}/${schema}/${id}`).catch(() => undefined)
	}
	for (const userId of [ANNA, BRAM]) {
		await admin.delete(`/ocs/v2.php/cloud/users/${userId}`).catch(() => undefined)
	}
	await admin.dispose()
})

test.describe.serial('lead rotation', () => {
	test.setTimeout(180_000)

	test('the admin turns the switch on and sets a cap on an agent profile', async ({ page }) => {
		await page.goto('/settings/admin/pipelinq')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		const toggle = page.getByLabel(/Assign new leads in turn|Nieuwe leads om de beurt toewijzen/)
		await expect(toggle).toBeVisible({ timeout: 30_000 })
		if (!(await toggle.isChecked())) {
			await toggle.check({ force: true })
		}
		await page.getByRole('button', { name: /Save lead settings|Lead-instellingen opslaan/ }).click()
		await expect.poll(async () => String((await (await admin.get(SETTINGS)).json()).config?.lead_rotation_enabled), { timeout: 15_000 }).toBe('true')

		await editProfile(page, ANNA, 10, false)
		const anna = await (await admin.get(`${OR}/agentProfile/${profiles[ANNA]}`)).json()
		expect(Number(anna.leadCapPer30Days)).toBe(10)
		expect(anna.pauseLeadAssignment === true).toBe(false)
	})

	test('three leads for the skill are shared two and one, each with its reason', async ({ page }) => {
		const ids: string[] = []
		for (const n of [1, 2, 3]) {
			const lead = await create('lead', { title: `E2E-LRT-${RUN} solar panels ${n}`, pipeline: pipelineId, category: CATEGORY })
			ids.push(idOf(lead))
			await settledLead(ids[ids.length - 1])
		}

		const leads = await Promise.all(ids.map(settledLead))
		const owners = leads.map((lead) => String(lead.assignee ?? ''))
		const counts = [ANNA, BRAM].map((user) => owners.filter((o) => o === user).length).sort()
		expect(counts, `owners ${owners.join(', ')}`).toEqual([1, 2])
		for (const lead of leads) {
			expect(String(lead.assignmentReason)).toMatch(/^rotation: fewest leads in 30 days/)
		}

		await gotoAppRoute(page, `/leads/${ids[0]}`)
		await expect(page.locator('#content-vue').getByText(String(leads[0].assignmentReason))).toBeVisible({ timeout: 20_000 })
	})

	test('a lead made with an owner keeps that owner', async () => {
		const lead = await create('lead', { title: `E2E-LRT-${RUN} own lead`, pipeline: pipelineId, category: CATEGORY, assignee: ADMIN.username })
		// Give the deferred listener time to run before reading the owner back.
		await new Promise((resolve) => setTimeout(resolve, 5_000))
		const read = await (await admin.get(`${OR}/lead/${idOf(lead)}`)).json()
		expect(read.assignee).toBe(ADMIN.username)
		expect(String(read.assignmentReason ?? '')).toBe('')
	})

	test('with everyone paused a lead stays unassigned and says why', async ({ page }) => {
		await page.goto('/settings/admin/pipelinq')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		await editProfile(page, ANNA, null, true)
		await editProfile(page, BRAM, null, true)

		const lead = await create('lead', { title: `E2E-LRT-${RUN} nobody free`, pipeline: pipelineId, category: CATEGORY })
		const read = await settledLead(idOf(lead))
		expect(String(read.assignee ?? '')).toBe('')
		expect(read.assignmentReason).toBe('rotation found no eligible colleague')
	})
})
