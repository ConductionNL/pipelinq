/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Role-based content on My work (my-work-roadmap block 10, REQ-MW-180).
 *
 *   @e2e my-work::an-agent-sees-only-their-own-items
 *   @e2e my-work::a-manager-looks-at-the-team
 *   @e2e my-work::a-manager-narrows-to-one-member
 *   @e2e my-work::an-admin-looks-at-the-organisation
 *
 * A team manager group is named in the admin settings; its members form the
 * team. An agent outside every manager group gets no scope switch and only
 * their own tickets. A member of the group switches to Team and sees the
 * open tickets of every member, each member with their open count, narrows
 * to one member and goes back to the team. The administrator gets
 * Organisation, which lists every open ticket.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-role-based-content-req-mw-180
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from '../../base-url.ts'

const RUN = Date.now().toString(36)
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const SETTINGS = '/index.php/apps/pipelinq/api/settings'
const TEAM = '/index.php/apps/pipelinq/api/my-work/team'
const ITEMS = '/index.php/apps/pipelinq/api/worklist/items'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const GROUP = `e2e-team-${RUN}`
const MANAGER = { username: `e2e-mgr-${RUN}`, password: `E2e-mgr-${RUN}-Pw!` }
const MEMBER = { username: `e2e-mbr-${RUN}`, password: `E2e-mbr-${RUN}-Pw!` }
const AGENT = { username: `e2e-agt-${RUN}`, password: `E2e-agt-${RUN}-Pw!` }

let admin: APIRequestContext
let previousGroups = ''
const tickets: string[] = []

/**
 * A request context signed in as a user.
 *
 * @param user The user and password.
 * @param user.username The user id.
 * @param user.password The password.
 */
async function as(user: { username: string; password: string }): Promise<APIRequestContext> {
	return request.newContext({
		baseURL: resolveBaseUrl(),
		httpCredentials: user,
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
}

/**
 * Create an open request ticket assigned to a user.
 *
 * @param assignee The user it is assigned to.
 * @param title Its title.
 */
async function ticketFor(assignee: string, title: string): Promise<void> {
	const res = await admin.post(`${OBJECTS}/ticket`, {
		data: { title, ticketType: 'request', status: 'new', priority: 'normal', channel: 'email', assignee },
	})
	expect(res.ok(), `creating ${title}: ${res.status()}`).toBeTruthy()
	const body = await res.json()
	tickets.push(String(body.id ?? body['@self']?.id))
}

/**
 * The titles of the open work a user reads in a scope.
 *
 * @param ctx The user's request context.
 * @param query The scope and member.
 */
async function titles(ctx: APIRequestContext, query: Record<string, string>): Promise<string[]> {
	const res = await ctx.get(ITEMS, { params: query })
	expect(res.status(), `items ${JSON.stringify(query)}`).toBe(200)
	return ((await res.json()).items ?? []).map((item: { title: string }) => item.title)
}

test.describe('My work: role-based content', () => {
	test.setTimeout(120_000)

	test.beforeAll(async () => {
		admin = await as(ADMIN)
		previousGroups = String((await (await admin.get(SETTINGS)).json()).config?.my_work_manager_groups ?? '')

		for (const user of [MANAGER, MEMBER, AGENT]) {
			const res = await admin.post('/ocs/v2.php/cloud/users', { form: { userid: user.username, password: user.password } })
			expect(res.ok(), `creating ${user.username}: ${res.status()}`).toBeTruthy()
		}
		expect((await admin.post('/ocs/v2.php/cloud/groups', { form: { groupid: GROUP } })).ok()).toBeTruthy()
		for (const user of [MANAGER, MEMBER]) {
			expect((await admin.post(`/ocs/v2.php/cloud/users/${user.username}/groups`, { form: { groupid: GROUP } })).ok()).toBeTruthy()
		}
		expect((await admin.put(SETTINGS, { data: { my_work_manager_groups: GROUP } })).ok()).toBeTruthy()

		await ticketFor(MANAGER.username, `Manager ticket ${RUN}`)
		await ticketFor(MEMBER.username, `Member ticket ${RUN}`)
		await ticketFor(AGENT.username, `Agent ticket ${RUN}`)
	})

	test.afterAll(async () => {
		await admin.put(SETTINGS, { data: { my_work_manager_groups: previousGroups } })
		for (const id of tickets) {
			await admin.delete(`${OBJECTS}/ticket/${id}`)
		}
		for (const user of [MANAGER, MEMBER, AGENT]) {
			await admin.delete(`/ocs/v2.php/cloud/users/${user.username}`)
		}
		await admin.delete(`/ocs/v2.php/cloud/groups/${GROUP}`)
		await admin.dispose()
	})

	test('an agent sees only their own items and gets no scope switch', async () => {
		const agent = await as(AGENT)
		const team = await (await agent.get(TEAM)).json()
		expect(team).toEqual({ scopes: [], members: [] })

		const own = await titles(agent, {})
		expect(own).toContain(`Agent ticket ${RUN}`)
		expect(own).not.toContain(`Member ticket ${RUN}`)

		expect((await agent.get(ITEMS, { params: { scope: 'team' } })).status()).toBe(400)
		await agent.dispose()
	})

	test('a manager looks at the team, each member with their open count', async () => {
		const manager = await as(MANAGER)
		const team = await (await manager.get(TEAM)).json()
		expect(team.scopes.map((scope: { value: string }) => scope.value)).toEqual(['mine', 'team'])
		const counts = Object.fromEntries(team.members.map((m: { value: string; count: number }) => [m.value, m.count]))
		expect(counts[MANAGER.username]).toBeGreaterThanOrEqual(1)
		expect(counts[MEMBER.username]).toBeGreaterThanOrEqual(1)
		expect(counts[AGENT.username]).toBeUndefined()

		const teamWork = await titles(manager, { scope: 'team' })
		expect(teamWork).toEqual(expect.arrayContaining([`Manager ticket ${RUN}`, `Member ticket ${RUN}`]))
		expect(teamWork).not.toContain(`Agent ticket ${RUN}`)
		await manager.dispose()
	})

	test('a manager narrows to one member and goes back to the team', async () => {
		const manager = await as(MANAGER)
		const one = await titles(manager, { scope: 'team', member: MEMBER.username })
		expect(one).toContain(`Member ticket ${RUN}`)
		expect(one).not.toContain(`Manager ticket ${RUN}`)

		const back = await titles(manager, { scope: 'team', member: '' })
		expect(back).toEqual(expect.arrayContaining([`Manager ticket ${RUN}`, `Member ticket ${RUN}`]))

		expect((await manager.get(ITEMS, { params: { scope: 'team', member: AGENT.username } })).status()).toBe(400)
		await manager.dispose()
	})

	test('an admin looks at the organisation', async () => {
		const team = await (await admin.get(TEAM)).json()
		expect(team.scopes.map((scope: { value: string }) => scope.value)).toContain('organisation')

		const everyone = await titles(admin, { scope: 'organisation' })
		expect(everyone).toEqual(expect.arrayContaining([`Manager ticket ${RUN}`, `Member ticket ${RUN}`, `Agent ticket ${RUN}`]))
	})
})
