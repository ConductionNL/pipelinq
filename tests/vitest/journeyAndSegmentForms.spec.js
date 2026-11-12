// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The journey form (defaults, loading a journey, the saved journey and the flow
 * engine warning) and the segment form (audience, rule state hints, loading and
 * saving), mounted with their services doubled.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const bus = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showSuccess: (m) => toasts.push(['success', m]),
	showWarning: (m) => toasts.push(['warning', m]),
}))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/services/journeysApi.js', () => ({ fetchJourney: vi.fn(), saveJourney: vi.fn() }))
vi.mock('../../src/components/SegmentBuilder.vue', () => ({ default: stub('SegmentBuilder') }))

const journeys = await import('../../src/services/journeysApi.js')
const { default: JourneyFormDialog } = await import('../../src/dialogs/JourneyFormDialog.vue')
const { default: SegmentFormDialog } = await import('../../src/dialogs/SegmentFormDialog.vue')

/**
 * Mount a hosted dialog, open, and let it load.
 *
 * @param {object} component The dialog.
 * @param {object} [props] Its props besides show.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(component, props = {}) {
	const w = shallowMount(component, { props: { show: true, ...props }, global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	bus.length = 0
	vi.clearAllMocks()
	axios.get = vi.fn()
	axios.post = vi.fn()
	axios.patch = vi.fn()
})

describe('JourneyFormDialog', () => {
	it('opens a new journey on the first option of every choice', async () => {
		const w = await openDialog(JourneyFormDialog)
		expect([w.vm.isEditing, w.vm.triggerKind.value, w.vm.conditionOperator.value, w.vm.actionKind.value, w.vm.intent.value, w.vm.status.value, w.vm.cron])
			.toEqual([false, 'leadStageChanged', 'equals', 'createTask', 'promotional', 'draft', '0 7 * * *'])
		expect(journeys.fetchJourney).not.toHaveBeenCalled()
		expect(w.vm.triggerOptions).toHaveLength(6)
	})

	it('loads an existing journey into the form, falling back to the first option for unknown values', async () => {
		journeys.fetchJourney.mockResolvedValueOnce({
			name: 'Renewal', audienceSegment: 's1', trigger: { kind: 'ticketClosed', cron: '0 9 * * 1' },
			condition: { field: 'status', operator: 'isNull' }, action: { kind: 'sendMailing', intent: 'weird', listId: 'l1' },
			status: 'paused', flowStatus: 'engine_missing',
		})
		const w = await openDialog(JourneyFormDialog, { item: { '@self': { id: 'j1' } } })
		expect(journeys.fetchJourney).toHaveBeenCalledWith('j1')
		expect([w.vm.form.name, w.vm.cron, w.vm.condition.field, w.vm.action.listId]).toEqual(['Renewal', '0 9 * * 1', 'status', 'l1'])
		expect([w.vm.triggerKind.value, w.vm.conditionOperator.value, w.vm.actionKind.value, w.vm.intent.value, w.vm.status.value]).toEqual(['ticketClosed', 'isNull', 'sendMailing', 'promotional', 'paused'])
		expect(w.vm.flowMessage).toBe('This journey is saved but it will not run: this instance has no flow engine.')
	})

	it('says when a journey cannot be loaded', async () => {
		journeys.fetchJourney.mockRejectedValueOnce({})
		const w = await openDialog(JourneyFormDialog, { item: { id: 'j1' } })
		expect([w.vm.error, w.vm.loading]).toEqual(['Could not load this journey.', false])
	})

	it('saves the journey with the chosen options, warns about a refused flow, and refreshes the host', async () => {
		journeys.saveJourney.mockResolvedValueOnce({ flowStatus: 'refused', flowError: 'cron invalid' })
		const refresh = vi.fn()
		const close = vi.fn()
		const w = await openDialog(JourneyFormDialog, { refresh, close })
		w.vm.form.name = 'Welcome'
		w.vm.actionKind = { value: 'sendMailing' }
		await w.vm.save()
		const [journey, id] = journeys.saveJourney.mock.calls[0]
		expect(id).toBe('')
		expect(journey).toMatchObject({ name: 'Welcome', status: 'draft', trigger: { kind: 'leadStageChanged', cron: '0 7 * * *' }, condition: { operator: 'equals' }, action: { kind: 'sendMailing', intent: 'promotional', taskType: 'callback' } })
		expect(toasts).toEqual([['warning', 'The flow engine refused this journey: cron invalid']])
		expect([refresh.mock.calls.length, close.mock.calls.length]).toEqual([1, 1])
	})

	it('refreshes the page without a host, falls back on cleared choices, and shows a refused save', async () => {
		journeys.saveJourney.mockResolvedValue({})
		const w = await openDialog(JourneyFormDialog)
		Object.assign(w.vm, { status: null, triggerKind: null, conditionOperator: null, actionKind: null, intent: null })
		await w.vm.save()
		expect(journeys.saveJourney.mock.calls[0][0]).toMatchObject({ status: 'draft', trigger: { kind: 'leadStageChanged' }, condition: { operator: 'equals' }, action: { kind: 'createTask', intent: 'promotional' } })
		expect([bus, toasts]).toEqual([[['cn:page:refresh', {}]], []])
		journeys.saveJourney.mockRejectedValueOnce({ response: { data: { error: 'Name taken' } } })
		await w.vm.save()
		expect([w.vm.error, w.vm.saving]).toEqual(['Name taken', false])
		journeys.saveJourney.mockRejectedValueOnce({})
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save this journey.')
	})
})

describe('SegmentFormDialog', () => {
	it('opens blank, and hints what is missing before it can be saved', async () => {
		const w = await openDialog(SegmentFormDialog)
		expect([w.vm.isEditing, w.vm.entityTypeOption.value, w.vm.canSave]).toEqual([false, 'contact', false])
		expect(w.vm.saveHint).toBe('Give the segment a name.')
		w.vm.model.name = 'Active'
		expect(w.vm.saveHint).toBe('Add at least one condition.')
		w.vm.rulesStatus = 'incomplete'
		expect(w.vm.saveHint).toBe('Complete or remove the unfinished conditions.')
		w.vm.rulesStatus = 'valid'
		expect([w.vm.saveHint, w.vm.canSave]).toEqual(['', true])
		expect(w.vm.fieldOptions.length).toBeGreaterThan(0)
	})

	it('clears the rules when the audience changes, and says so beforehand', async () => {
		const w = await openDialog(SegmentFormDialog)
		w.vm.model.rules = { type: 'AND', children: [{ field: 'city' }] }
		expect(w.vm.audienceHint).toBe('Changing the audience clears the rules.')
		w.vm.entityTypeOption = { value: 'contact' }
		expect(w.vm.model.rules.children).toHaveLength(1)
		w.vm.entityTypeOption = { value: 'customer' }
		expect([w.vm.model.entityType, w.vm.model.rules]).toEqual(['customer', { type: 'AND', children: [] }])
		w.vm.entityTypeOption = null
		expect(w.vm.model.entityType).toBe('contact')
		expect(w.vm.audienceHint).toBe('')
	})

	it('creates a segment with a copy of its rules', async () => {
		const close = vi.fn()
		const refresh = vi.fn()
		const w = await openDialog(SegmentFormDialog, { close, refresh })
		const rules = { type: 'AND', children: [{ field: 'city', operator: 'equals', value: 'Utrecht' }] }
		Object.assign(w.vm.model, { name: ' Utrecht ', rules })
		w.vm.rulesStatus = 'valid'
		axios.post.mockResolvedValueOnce({})
		await w.vm.save()
		const [url, payload] = axios.post.mock.calls[0]
		expect(url).toMatch(/\/apps\/pipelinq\/api\/segments$/)
		expect(payload).toEqual({ name: 'Utrecht', description: '', entityType: 'contact', rules })
		expect(payload.rules).not.toBe(rules)
		expect([toasts, close.mock.calls.length, refresh.mock.calls.length]).toEqual([[['success', 'Segment created.']], 1, 1])
	})

	it('loads and updates an existing segment, and saves nothing that is not ready', async () => {
		axios.get.mockResolvedValueOnce({ data: { name: 'Old', entityType: 'customer' } })
		const w = await openDialog(SegmentFormDialog, { item: { id: 's1' } })
		expect(axios.get.mock.calls[0][0]).toMatch(/\/apps\/pipelinq\/api\/segments\/s1$/)
		expect(w.vm.model).toEqual({ name: 'Old', description: '', entityType: 'customer', rules: { type: 'AND', children: [] } })
		await w.vm.save()
		expect(axios.patch).not.toHaveBeenCalled()
		w.vm.rulesStatus = 'valid'
		axios.patch.mockResolvedValueOnce({})
		await w.vm.save()
		expect(axios.patch.mock.calls[0][0]).toMatch(/\/segments\/s1$/)
		expect(toasts).toEqual([['success', 'Segment saved.']])
	})

	it('shows a failed load and a refused save', async () => {
		axios.get.mockRejectedValueOnce({})
		const w = await openDialog(SegmentFormDialog, { item: { uuid: 's2' } })
		expect([w.vm.loadError, w.vm.saveHint]).toEqual(['Could not load this segment.', ''])
		w.vm.loadError = ''
		Object.assign(w.vm.model, { name: 'X' })
		w.vm.rulesStatus = 'valid'
		axios.patch.mockRejectedValueOnce({ response: { data: { error: 'Invalid rule' } } })
		await w.vm.save()
		expect([w.vm.saveError, w.vm.saving]).toEqual(['Invalid rule', false])
	})
})
