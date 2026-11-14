/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of eight service helpers, pinned before they were split
 * into named steps: the persisted overrides load, the user display name,
 * the visit log payloads, the menu structure save, the campaign writes, the
 * gauge target, the pipeline stage payload and the orphaned leads.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const library = vi.hoisted(() => ({ apps: {}, merge: vi.fn() }))
const auth = vi.hoisted(() => ({ getCurrentUser: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => path,
	generateOcsUrl: (path) => `/ocs/${path}`,
}))
vi.mock('@nextcloud/auth', () => auth)
vi.mock('@conduction/nextcloud-vue', () => ({
	useAppStatus: (id) => ({ enabled: { value: library.apps[id] === true } }),
	mergeManifestDelta: library.merge,
}))

const { loadPersistedOverrides } = await import('../../src/services/persistedOverrides.js')
const { fetchUserDisplayName } = await import('../../src/services/userDisplayName.js')
const { buildVisitPayloads } = await import('../../src/services/visitLog.js')
const { saveMenuStructure } = await import('../../src/services/menuStructureSetting.js')
const { createCampaignLandingPage, saveCampaign } = await import('../../src/services/campaignsApi.js')
const { resolveGaugeTarget } = await import('../../src/utils/pageAppConfig.js')
const { pipelineStagesPayload } = await import('../../src/services/pipelinePayload.js')
const { findOrphanedLeads } = await import('../../src/services/pipelineOrphans.js')
const { MODULES_SETTING } = await import('../../src/utils/menuModules.js')
const { STRUCTURE_SETTING } = await import('../../src/utils/structureProfile.js')

let warn

beforeEach(() => {
	Object.values(http).forEach((fn) => fn.mockReset())
	library.merge.mockReset()
	library.apps = {}
	auth.getCurrentUser.mockReset()
	warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
})

afterEach(() => {
	warn.mockRestore()
})

describe('loadPersistedOverrides', () => {
	const manifest = { pages: [] }

	it('asks nothing without buildiq', async () => {
		expect(await loadPersistedOverrides(manifest)).toBe(manifest)
		expect(http.get).not.toHaveBeenCalled()
	})

	it('merges a non-empty delta and warns about orphaned paths', async () => {
		library.apps.openbuild = true
		http.get.mockResolvedValue({ data: { pages: [1] } })
		library.merge.mockReturnValue({ manifest: { merged: true }, orphanedDeltaPaths: ['a'] })
		expect(await loadPersistedOverrides(manifest)).toEqual({ merged: true })
		expect(http.get).toHaveBeenCalledWith('/apps/buildiq/api/app-overrides/pipelinq', { timeout: 8000 })
		expect(library.merge).toHaveBeenCalledWith(manifest, { pages: [1] })
		expect(warn).toHaveBeenCalledWith(
			'[pipelinq] Manifest override has orphaned delta paths (base changed since the edit):',
			['a'],
		)
		library.merge.mockReturnValue({ manifest: { merged: 2 }, orphanedDeltaPaths: [] })
		warn.mockClear()
		expect(await loadPersistedOverrides(manifest)).toEqual({ merged: 2 })
		expect(warn).not.toHaveBeenCalled()
	})

	it('keeps the manifest for an empty, list or null delta', async () => {
		library.apps.buildiq = true
		for (const data of [{}, [1], null, 'x']) {
			http.get.mockResolvedValue({ data })
			expect(await loadPersistedOverrides(manifest)).toBe(manifest)
		}
		expect(library.merge).not.toHaveBeenCalled()
	})

	it('warns on a failure that is not a 404, and on a failing merge', async () => {
		library.apps.buildiq = true
		http.get.mockRejectedValue({ response: { status: 404 } })
		expect(await loadPersistedOverrides(manifest)).toBe(manifest)
		expect(warn).not.toHaveBeenCalled()
		const failure = new Error('down')
		http.get.mockRejectedValue(failure)
		expect(await loadPersistedOverrides(manifest)).toBe(manifest)
		expect(warn).toHaveBeenCalledWith(
			'[pipelinq] Could not load persisted manifest overrides — using the bundled manifest.',
			failure,
		)
		http.get.mockResolvedValue({ data: { a: 1 } })
		const broken = new Error('merge')
		library.merge.mockImplementation(() => {
			throw broken
		})
		expect(await loadPersistedOverrides(manifest)).toBe(manifest)
		expect(warn).toHaveBeenLastCalledWith(expect.any(String), broken)
	})
})

describe('fetchUserDisplayName', () => {
	it('names the signed-in user, or their uid', async () => {
		auth.getCurrentUser.mockReturnValue({ uid: 'u1', displayName: 'User' })
		expect(await fetchUserDisplayName('u1')).toBe('User')
		auth.getCurrentUser.mockReturnValue({ uid: 'u1', displayName: '' })
		expect(await fetchUserDisplayName('u1')).toBe('u1')
		expect(http.get).not.toHaveBeenCalled()
	})

	it('asks core autocomplete for anyone else, even when the session cannot be read', async () => {
		auth.getCurrentUser.mockImplementation(() => {
			throw new Error('no storage')
		})
		http.get.mockResolvedValue({ data: { ocs: { data: [null, { id: 'u2x', label: 'No' }, { id: 'u2', label: 'Two' }] } } })
		expect(await fetchUserDisplayName('u2')).toBe('Two')
		expect(http.get).toHaveBeenCalledWith('/ocs/core/autocomplete/get', {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			params: { search: 'u2', itemType: ' ', itemId: ' ', 'shareTypes[]': 0, limit: 10 },
		})
		auth.getCurrentUser.mockReturnValue(null)
		http.get.mockResolvedValue({ data: { ocs: { data: [{ id: 'u2', label: '' }] } } })
		expect(await fetchUserDisplayName('u2')).toBe('u2')
		http.get.mockResolvedValue({ data: { ocs: { data: 'x' } } })
		expect(await fetchUserDisplayName('u2')).toBe('u2')
		http.get.mockRejectedValue(new Error('500'))
		expect(await fetchUserDisplayName('u2')).toBe('u2')
	})
})

describe('buildVisitPayloads', () => {
	const now = new Date('2026-03-01T10:00:00Z')

	it('builds the ticket and the follow-up task with every link', () => {
		const result = buildVisitPayloads({
			title: 'Visit', note: ' Went well ', now, userId: 'u1', clientId: 'c1', leadId: 'l1',
			followUpDate: '2026-03-08', taskSubject: 'Call back',
		})
		expect(result.ticket).toEqual({
			ticketType: 'interaction', title: 'Visit', channel: 'visit', direction: 'outbound',
			occurredAt: '2026-03-01T10:00:00.000Z', description: 'Went well', assignee: 'u1', client: 'c1', lead: 'l1',
		})
		expect(Object.keys(result.task)).toEqual([
			'type', 'subject', 'description', 'status', 'priority', 'deadline', 'contactMomentSummary',
			'assigneeUserId', 'createdBy', 'clientId', 'lead', 'leadId',
		])
		expect(result.task).toMatchObject({
			type: 'followUpTask', subject: 'Call back', description: 'Went well', status: 'open', priority: 'normal',
			contactMomentSummary: 'Went well', assigneeUserId: 'u1', createdBy: 'u1', clientId: 'c1', lead: 'l1', leadId: 'l1',
		})
		expect(typeof result.task.deadline).toBe('string')
	})

	it('leaves out what was not given, and the task without a follow-up day', () => {
		expect(buildVisitPayloads({ title: 'V', now })).toEqual({
			ticket: { ticketType: 'interaction', title: 'V', channel: 'visit', direction: 'outbound', occurredAt: '2026-03-01T10:00:00.000Z', description: '' },
			task: null,
		})
		const { task } = buildVisitPayloads({ title: 'V', now, followUpDate: '2026-03-08', taskSubject: 'S' })
		expect(Object.keys(task)).toEqual(['type', 'subject', 'description', 'status', 'priority', 'deadline', 'contactMomentSummary'])
		expect(buildVisitPayloads({ title: 'V', now, followUpDate: 'soon' }).task).toBeNull()
	})
})

describe('saveMenuStructure', () => {
	const target = (answer) => ({
		url: '/settings',
		requestToken: 'tok',
		fetchImpl: vi.fn(async () => answer),
	})

	it('writes both settings and reads them back', async () => {
		const options = target({ ok: true, json: async () => ({ config: { [STRUCTURE_SETTING]: 'simple', [MODULES_SETTING]: 'a,b' } }) })
		expect(await saveMenuStructure({ structure: 'simple', modules: ['a', 'b'] }, options)).toEqual({ structure: 'simple', modules: ['a', 'b'] })
		expect(options.fetchImpl).toHaveBeenCalledWith('/settings', {
			method: 'PUT',
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok' },
			body: JSON.stringify({ [STRUCTURE_SETTING]: 'simple', [MODULES_SETTING]: 'a,b' }),
		})
		const none = target({ ok: true, json: async () => ({ config: { [STRUCTURE_SETTING]: 'simple', [MODULES_SETTING]: '' } }) })
		expect(await saveMenuStructure({ structure: 'simple', modules: 'x' }, none)).toEqual({ structure: 'simple', modules: [] })
	})

	it('refuses a failed or unstored write', async () => {
		await expect(saveMenuStructure({ structure: 'simple', modules: [] }, target({ ok: false, status: 500 })))
			.rejects.toThrow('settings write answered 500')
		await expect(saveMenuStructure({ structure: 'simple', modules: [] }, target({ ok: true, json: async () => ({}) })))
			.rejects.toThrow('settings write did not store the menu structure')
		await expect(saveMenuStructure({ structure: 'simple', modules: ['a'] }, target({ ok: true, json: async () => ({ config: { [STRUCTURE_SETTING]: 'simple', [MODULES_SETTING]: 'b' } }) })))
			.rejects.toThrow('settings write did not store the menu structure')
	})
})

describe('campaign writes', () => {
	it('creates a landing page, or returns the refusal', async () => {
		http.post.mockResolvedValue({ data: { id: 'lp' } })
		expect(await createCampaignLandingPage('c1', { portal: 'p' })).toEqual({ id: 'lp' })
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/campaigns/c1/landing-page', { portal: 'p', route: '' })
		await createCampaignLandingPage('c1')
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/campaigns/c1/landing-page', { portal: '', route: '' })
		http.post.mockRejectedValue({ response: { data: { error: 'Taken' } } })
		expect(await createCampaignLandingPage('c1')).toEqual({ error: 'Taken' })
		const failure = { response: { data: { message: 'x' } } }
		http.post.mockRejectedValue(failure)
		await expect(createCampaignLandingPage('c1')).rejects.toBe(failure)
		const plain = new Error('down')
		http.post.mockRejectedValue(plain)
		await expect(createCampaignLandingPage('c1')).rejects.toBe(plain)
	})

	it('creates or updates a campaign, or returns the refusal', async () => {
		http.post.mockResolvedValue({ data: { id: 'n' } })
		expect(await saveCampaign({ name: 'A' })).toEqual({ campaign: { id: 'n' } })
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/campaigns', { name: 'A' })
		http.patch.mockResolvedValue({ data: { id: 'e' } })
		expect(await saveCampaign({ name: 'B' }, 'e')).toEqual({ campaign: { id: 'e' } })
		expect(http.patch).toHaveBeenCalledWith('/apps/pipelinq/api/campaigns/e', { name: 'B' })
		http.patch.mockRejectedValue({ response: { data: { error: 'No' } } })
		expect(await saveCampaign({}, 'e')).toEqual({ error: 'No' })
		http.post.mockRejectedValue({ response: { data: 'text' } })
		await expect(saveCampaign({})).rejects.toEqual({ response: { data: 'text' } })
	})
})

describe('resolveGaugeTarget', () => {
	const gauge = (value, extra = {}) => ({ type: 'gauge', id: 'g', content: { target: { value, unit: '€' }, label: 'L', ...extra } })

	it('reads the target from the app config', () => {
		expect(resolveGaugeTarget(gauge('@config.goal'), { goal: '500' })).toEqual({
			type: 'gauge', id: 'g', content: { target: { value: 500, unit: '€' }, label: 'L' },
		})
		expect(resolveGaugeTarget(gauge('@config.goal?'), { goal: 7 }).content.target.value).toBe(7)
	})

	it('shows the label without a target when none is set', () => {
		expect(resolveGaugeTarget(gauge('@config.goal', { labelWithoutTarget: 'No goal' }), { goal: '0' }).content)
			.toEqual({ target: { value: 0, unit: '€' }, label: 'No goal', labelWithoutTarget: 'No goal' })
		expect(resolveGaugeTarget(gauge('@config.goal'), null).content).toEqual({ target: { value: 0, unit: '€' }, label: 'L' })
	})

	it('leaves anything else alone', () => {
		for (const widget of [null, { type: 'chart' }, gauge(5), gauge('fixed'), { type: 'gauge' }]) {
			expect(resolveGaugeTarget(widget, { goal: 1 })).toBe(widget)
		}
	})
})

describe('pipelineStagesPayload', () => {
	it('keeps only the filled-in optional fields', () => {
		expect(pipelineStagesPayload([
			{ name: 'A', order: 1, probability: '40.6', color: '#f00', maxItems: '5' },
			{ name: 'B', order: 2, isClosed: 1, isWon: true, probability: '', color: '', maxItems: 3 },
			{ name: 'C', order: 3, probability: 'x', maxItems: '2.5' },
			{ name: 'D', order: 4, probability: null, maxItems: 0 },
			{ name: 'E', order: 5, maxItems: '' },
		])).toEqual([
			{ name: 'A', order: 1, isClosed: false, isWon: false, probability: 41, color: '#f00', maxItems: 5 },
			{ name: 'B', order: 2, isClosed: true, isWon: true },
			{ name: 'C', order: 3, isClosed: false, isWon: false },
			{ name: 'D', order: 4, isClosed: false, isWon: false },
			{ name: 'E', order: 5, isClosed: false, isWon: false },
		])
		expect(pipelineStagesPayload(null)).toEqual([])
	})
})

describe('findOrphanedLeads', () => {
	it('names leads on a missing pipeline or a missing stage', () => {
		const pipelines = [{ id: 'p1', stages: [{ name: 'New' }] }, null, { stages: [] }]
		const leads = [
			null,
			{ id: 1 },
			{ id: 2, pipeline: 'gone' },
			{ id: 3, pipeline: 'p1', stage: 'New' },
			{ id: 4, pipeline: 'p1', stage: 'Old' },
			{ id: 5, pipeline: 'p1' },
		]
		expect(findOrphanedLeads(leads, pipelines)).toEqual([
			{ lead: { id: 2, pipeline: 'gone' }, reason: 'pipeline' },
			{ lead: { id: 4, pipeline: 'p1', stage: 'Old' }, reason: 'stage' },
		])
		expect(findOrphanedLeads(null, null)).toEqual([])
	})
})
