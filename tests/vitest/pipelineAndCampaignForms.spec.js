// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline form (stage rules, ordering, mappings, the saved payload) and the
 * campaign form (vocabulary, loading a campaign, saving and the refusals it
 * explains), mounted with their services doubled.
 *
 * @spec openspec/specs/pipeline/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const bus = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcDateTimePickerNative: stub('NcDateTimePickerNative'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('vuedraggable', () => ({ default: stub('Draggable') }))
vi.mock('../../src/services/viewService.js', () => ({ getViews: vi.fn() }))
vi.mock('../../src/services/campaignsApi.js', () => ({ fetchCampaignVocabularies: vi.fn(), saveCampaign: vi.fn() }))

const views = await import('../../src/services/viewService.js')
const campaigns = await import('../../src/services/campaignsApi.js')
const { default: PipelineFormDialog } = await import('../../src/dialogs/PipelineFormDialog.vue')
const { default: CampaignFormDialog } = await import('../../src/dialogs/CampaignFormDialog.vue')

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
globalThis.t = t

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @param {object} [mocks] Global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}, mocks = {}) {
	const w = shallowMount(component, { props, global: { mocks: { t, ...mocks } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	bus.length = 0
	vi.clearAllMocks()
	views.getViews.mockResolvedValue([{ id: 'v1', name: 'Open leads' }, { uuid: 'v2', slug: 'mine' }])
})

describe('PipelineFormDialog', () => {
	it('starts empty for a new pipeline, invalid until it has a title and a stage', async () => {
		const w = await mountIt(PipelineFormDialog)
		expect(w.vm.isEdit).toBe(false)
		expect(w.vm.viewOptions).toEqual([{ value: 'v1', label: 'Open leads' }, { value: 'v2', label: 'mine' }])
		expect(w.vm.errors).toEqual({ title: 'Pipeline title is required' })
		w.vm.form.title = 'Sales'
		expect(w.vm.isValid).toBe(false)
		w.vm.addStage()
		w.vm.form.stages[0].name = 'New'
		expect(w.vm.isValid).toBe(true)
	})

	it('loads an existing pipeline with its stages in order, and has no views when they cannot load', async () => {
		views.getViews.mockRejectedValueOnce(new Error('x'))
		const pipeline = { id: 'p1', title: 'Sales', isDefault: 1, propertyMappings: [{ schemaSlug: 'lead' }], stages: [{ name: 'B', order: 5 }, { name: 'A', order: 2 }] }
		const w = await mountIt(PipelineFormDialog, { pipeline })
		expect([w.vm.isEdit, w.vm.form.isDefault, w.vm.views]).toEqual([true, true, []])
		expect(w.vm.form.stages.map((s) => [s.name, s.order])).toEqual([['A', 0], ['B', 1]])
		expect(pipeline.stages[0].order).toBe(5)
	})

	it('checks every stage: a name, a won stage that is closed, a probability and a limit', async () => {
		const w = await mountIt(PipelineFormDialog)
		w.vm.form.stages = [
			{ name: ' ', isWon: true, isClosed: false, probability: 120, maxItems: '2.5' },
			{ name: 'Won', isWon: true, isClosed: true, probability: '', maxItems: 0 },
			{ name: 'Open', probability: 50, maxItems: 3 },
		]
		expect(w.vm.stageErrors).toEqual([
			{
				name: 'Stage name is required',
				isWon: 'A Won stage must also be marked as Closed',
				probability: 'Probability must be between 0 and 100',
				maxItems: 'A limit is a whole number of 1 or more',
			},
			null,
			null,
		])
		w.vm.form.stages = [{ name: 'Lost', isClosed: true }]
		expect(w.vm.errors.stages).toBe('Pipeline must have at least one non-closed stage')
	})

	it('adds, moves, drags and removes stages, keeping the order they are put in', async () => {
		const w = await mountIt(PipelineFormDialog)
		w.vm.addStage()
		w.vm.addStage()
		w.vm.addStage()
		w.vm.form.stages.forEach((s, i) => { s.name = `S${i}` })
		w.vm.moveStage(w.vm.form.stages[2], -1)
		expect(w.vm.form.stages.map((s) => [s.name, s.order])).toEqual([['S0', 0], ['S2', 1], ['S1', 2]])
		w.vm.moveStage(w.vm.form.stages[0], -1)
		w.vm.moveStage({ name: 'other' }, 1)
		expect(w.vm.form.stages.map((s) => s.name)).toEqual(['S0', 'S2', 'S1'])
		w.vm.removeStage(0)
		w.vm.removeStage(9)
		expect(w.vm.form.stages.map((s) => [s.name, s.order])).toEqual([['S2', 0], ['S1', 1]])
		w.vm.form.stages.reverse()
		w.vm.recomputeOrders()
		expect(w.vm.form.stages.map((s) => [s.name, s.order])).toEqual([['S1', 0], ['S2', 1]])
	})

	it('adds and removes property mappings', async () => {
		const w = await mountIt(PipelineFormDialog)
		w.vm.addMapping()
		expect(w.vm.form.propertyMappings).toEqual([{ schemaSlug: '', columnProperty: 'stage', totalsProperty: null }])
		w.vm.removeMapping(0)
		expect(w.vm.form.propertyMappings).toEqual([])
	})

	it('saves the cleaned payload only when valid', async () => {
		const w = await mountIt(PipelineFormDialog)
		w.vm.onSave()
		expect(w.emitted('save')).toBeUndefined()
		w.vm.form.title = 'Sales'
		w.vm.form.propertyMappings = [{ schemaSlug: 'lead', columnProperty: 'stage', totalsProperty: '' }, { schemaSlug: 'request', columnProperty: 'status', totalsProperty: 'value' }]
		w.vm.form.stages = [{ name: 'New', order: 0, probability: '33.4', isClosed: false, isWon: false, color: '#f00', maxItems: '4' }]
		w.vm.onSave()
		const [saved] = w.emitted('save')[0]
		expect(saved.propertyMappings).toEqual([{ schemaSlug: 'lead', columnProperty: 'stage' }, { schemaSlug: 'request', columnProperty: 'status', totalsProperty: 'value' }])
		expect(saved.stages).toEqual([{ name: 'New', order: 0, isClosed: false, isWon: false, probability: 33, color: '#f00', maxItems: 4 }])
	})
})

describe('CampaignFormDialog', () => {
	beforeEach(() => {
		campaigns.fetchCampaignVocabularies.mockResolvedValue({ sources: ['newsletter'], mediums: ['email'] })
		axios.get = vi.fn()
	})

	it('loads the vocabulary for a new campaign', async () => {
		const w = await mountIt(CampaignFormDialog, { show: true })
		expect([w.vm.sources, w.vm.mediums, w.vm.isEditing, w.vm.status, w.vm.statuses]).toEqual([['newsletter'], ['email'], false, 'planned', ['planned', 'running', 'finished', 'cancelled']])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('loads an existing campaign into the form', async () => {
		axios.get.mockResolvedValueOnce({ data: { name: 'Spring', goal: 'Leads', utmSource: 'newsletter', utmMedium: 'email', status: 'running', startsAt: '2026-04-01', budgetEur: 500 } })
		const w = await mountIt(CampaignFormDialog, { show: true, item: { '@self': { id: 'c1' } } })
		expect(axios.get.mock.calls[0][0]).toContain('/objects/pipelinq/campaign/c1')
		expect([w.vm.form.name, w.vm.source, w.vm.medium, w.vm.status, w.vm.budget, w.vm.endsAt]).toEqual(['Spring', 'newsletter', 'email', 'running', '500', null])
		expect(w.vm.startsAt).toEqual(new Date('2026-04-01'))
	})

	it('shows why the vocabulary or the campaign could not load', async () => {
		campaigns.fetchCampaignVocabularies.mockRejectedValueOnce({})
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Not found' } } })
		const w = await mountIt(CampaignFormDialog, { show: true, item: { id: 'c1' } })
		expect(w.vm.error).toBe('Not found')
		campaigns.fetchCampaignVocabularies.mockRejectedValueOnce({})
		await mountIt(CampaignFormDialog, { show: true })
			.then((fresh) => expect(fresh.vm.error).toBe('Could not load the campaign vocabulary.'))
	})

	it('creates a campaign and opens it', async () => {
		const close = vi.fn()
		const router = { push: vi.fn() }
		const w = await mountIt(CampaignFormDialog, { show: true, close }, { $router: router })
		Object.assign(w.vm.form, { name: 'Spring' })
		Object.assign(w.vm, { source: 'newsletter', startsAt: new Date('2026-04-01T10:00:00Z'), budget: '250' })
		campaigns.saveCampaign.mockResolvedValueOnce({ campaign: { uuid: 'c9' } })
		await w.vm.save()
		expect(campaigns.saveCampaign).toHaveBeenCalledWith({ name: 'Spring', goal: '', status: 'planned', utmSource: 'newsletter', utmMedium: '', startsAt: '2026-04-01', endsAt: '', articleSummary: '', articleBody: '', budgetEur: 250 }, '')
		expect(close).toHaveBeenCalled()
		expect(router.push).toHaveBeenCalledWith({ name: 'CampaignDetail', params: { id: 'c9' } })
	})

	it('saves an edited campaign and refreshes the host, or the page', async () => {
		axios.get.mockResolvedValue({ data: {} })
		const refresh = vi.fn()
		const close = vi.fn()
		const hosted = await mountIt(CampaignFormDialog, { show: true, item: { id: 'c1' }, refresh, close })
		campaigns.saveCampaign.mockResolvedValue({ campaign: { id: 'c1' } })
		await hosted.vm.save()
		expect(campaigns.saveCampaign.mock.calls[0][1]).toBe('c1')
		expect(campaigns.saveCampaign.mock.calls[0][0]).not.toHaveProperty('budgetEur')
		expect([refresh.mock.calls.length, close.mock.calls.length]).toEqual([1, 1])
		const page = await mountIt(CampaignFormDialog, { show: true, item: { id: 'c1' }, close })
		await page.vm.save()
		expect(bus).toEqual([['cn:page:refresh', {}]])
	})

	it('explains a refused source, medium or name, and a failed save', async () => {
		const w = await mountIt(CampaignFormDialog, { show: true })
		const cases = [
			[{ error: 'unknown_utm_source', value: 'tv', allowed: ['newsletter', 'social'] }, 'tv is not one of the allowed sources: newsletter, social'],
			[{ error: 'unknown_utm_medium', value: 'fax', allowed: ['email'] }, 'fax is not one of the allowed mediums: email'],
			[{ error: 'name_required' }, 'A campaign needs a name.'],
			[{ error: 'other' }, 'Could not save the campaign.'],
		]
		for (const [result, message] of cases) {
			campaigns.saveCampaign.mockResolvedValueOnce(result)
			await w.vm.save()
			expect(w.vm.error).toBe(message)
		}
		campaigns.saveCampaign.mockRejectedValueOnce({ response: { data: { error: 'Server error' } } })
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['Server error', false])
		expect(w.vm.isoDay(null)).toBe('')
	})
})
