/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The Routing rules section: it reads the shipped flow, adopts it on the
 * first save, stores and publishes the rules, and shows OpenRegister's
 * refusal as it comes.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match)),
}))
const nc = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({
	NcButton: nc('NcButton'),
	NcLoadingIcon: nc('NcLoadingIcon'),
	NcNoteCard: nc('NcNoteCard'),
	NcSelect: nc('NcSelect'),
	NcSettingsSection: nc('NcSettingsSection'),
	NcTextField: nc('NcTextField'),
}))

const { default: RoutingRulesSettings } = await import('../../src/views/settings/RoutingRulesSettings.vue')

function shippedFlow () {
  return {
	id: 7,
	uuid: 'flow-uuid',
	name: 'Route incoming tickets',
	app: 'pipelinq',
	enabled: false,
	nodes: [
		{ id: 'routing-rules', type: 'openregister.decision-table', config: { table: { rules: [{ id: 'everything-else', inputEntries: ['-', '-', '-', '-', '-'], outputEntries: ['', 'normal'] }] } } },
	],
}
}

/**
 * The section as a plain context: data, computed values as getters, methods bound.
 *
 * @return {object} The context.
 */
function section() {
	const ctx = { ...RoutingRulesSettings.data() }
	for (const [name, def] of Object.entries(RoutingRulesSettings.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	for (const [name, fn] of Object.entries(RoutingRulesSettings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.put.mockReset()
})

describe('RoutingRulesSettings', () => {
	it('reads the shipped flow for pipelinq and offers to switch routing on', async () => {
		axios.get.mockResolvedValue({ data: { results: [{ name: 'Other', app: 'pipelinq' }, shippedFlow()] } })
		const self = section()

		await self.load()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/flows', { params: { app: 'pipelinq', limit: 200 } })
		expect(self.flow.uuid).toBe('flow-uuid')
		expect(self.flow.enabled).toBe(false)
		expect(self.rows).toEqual([])
		expect(self.loading).toBe(false)
	})

	it('says so when the flow is not installed', async () => {
		axios.get.mockResolvedValue({ data: { results: [] } })
		const self = section()

		await self.load()

		expect(self.flow).toBeNull()
		expect(self.loadError).toBe('')
	})

	it('adopts the flow on the first save, then stores and publishes the rules', async () => {
		const self = section()
		self.flow = shippedFlow()
		self.add()
		self.update(0, 'fromDomain', 'kadaster.nl')
		self.update(0, 'category', 'Registratie')
		self.update(0, 'priority', 'high')
		axios.post.mockResolvedValue({ data: {} })
		axios.put.mockImplementation(async (url, body) => ({ data: { ...shippedFlow(), nodes: body.nodes } }))

		await self.save()

		expect(axios.post.mock.calls.map((call) => call[0])).toEqual([
			'/index.php/apps/openregister/api/flows/flow-uuid/adopt',
			'/index.php/apps/openregister/api/flows/flow-uuid/publish',
		])
		const [url, body] = axios.put.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/flows/flow-uuid')
		expect(body.nodes[0].config.table.rules.map((rule) => rule.inputEntries[2])).toEqual(['"kadaster.nl"', '-'])
		expect(body.nodes[0].config.table.rules[0].outputEntries).toEqual(['Registratie', 'high'])
		expect(self.flow.enabled).toBe(true)
		expect(self.rows).toHaveLength(1)
		expect(self.saved).toBe(true)
	})

	it('does not adopt a flow that is already on', async () => {
		const self = section()
		self.flow = { ...shippedFlow(), enabled: true }
		self.rows = [{ id: 'rule-1', channel: 'sms', fromAddress: '', fromDomain: '', mailbox: '', clientSegment: '', category: 'Belastingen', priority: 'normal' }]
		axios.post.mockResolvedValue({ data: {} })
		axios.put.mockResolvedValue({ data: {} })

		await self.save()

		expect(axios.post.mock.calls.map((call) => call[0])).toEqual(['/index.php/apps/openregister/api/flows/flow-uuid/publish'])
	})

	it('shows the refusal OpenRegister gives', async () => {
		const self = section()
		self.flow = { ...shippedFlow(), enabled: true }
		self.rows = [{ id: '', channel: 'sms', fromAddress: '', fromDomain: '', mailbox: '', clientSegment: '', category: 'Belastingen', priority: 'normal' }]
		axios.put.mockRejectedValue({ response: { data: { error: 'rule rule-1 has 4 input entries for 5 declared inputs' } } })

		await self.save()

		expect(self.saveError).toBe('rule rule-1 has 4 input entries for 5 declared inputs')
		expect(self.saved).toBe(false)
		expect(axios.post).not.toHaveBeenCalled()
		expect(self.saving).toBe(false)
	})

	it('saves nothing while a rule has no condition or no category', async () => {
		const self = section()
		self.flow = shippedFlow()
		self.add()

		expect(self.problemTexts).toEqual([
			'Rule 1 has no condition, so it would match every ticket. Fill in at least one field.',
			'Rule 1 sets no category.',
		])
		await self.save()

		expect(axios.post).not.toHaveBeenCalled()
		expect(axios.put).not.toHaveBeenCalled()
	})

	it('moves and removes rules', () => {
		const self = section()
		self.rows = [{ id: 'a' }, { id: 'b' }]

		self.move(1, -1)
		expect(self.rows.map((row) => row.id)).toEqual(['b', 'a'])
		self.remove(0)
		expect(self.rows.map((row) => row.id)).toEqual(['a'])
	})
})
