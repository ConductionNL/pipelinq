// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead form: an existing lead read into the form, a new lead on the default
 * pipeline's first open stage, a pipeline change, and the lead it saves.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-44
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(async () => []),
	collections: { pipeline: [] },
	objects: {},
}))

vi.mock('@nextcloud/initial-state', () => ({
	loadState: () => ({ currency: 'USD' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/store/modules/leadSources.js', () => ({
	useLeadSourcesStore: () => ({
		fetchSources: vi.fn(async () => []),
		sourceNames: [],
	}),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: { name: 'CnResourceSelect', render: () => null },
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['label', 'modelValue'],
		render() {
			return h('label', { 'data-label': this.label })
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			render() {
				return h('button', this.$slots.default?.())
			},
		},
		NcDateTimePickerNative: field('NcDateTimePickerNative'),
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
	}
})

globalThis.t = (_app, text) => text
globalThis.n = (_app, s) => s

globalThis.t = (_app, text) => text
globalThis.n = (_app, s) => s

const { default: LeadForm } = await import('../../src/views/leads/LeadForm.vue')

const pipelines = [
	{ id: 'p-lead', title: 'Sales', isDefault: true, entityType: 'lead', stages: [{ name: 'Won', order: 3, isClosed: true }, { name: 'New', order: 1, isClosed: true }, { name: 'Contact', order: 2 }] },
	{ id: 'p-req', title: 'Desk', isDefault: true, entityType: 'request', stages: [{ name: 'Open', order: 2 }, { name: 'Intake', order: 1 }] },
	{ id: 'p-empty', title: 'Empty', entityType: 'both' },
]

async function mountForm(props = {}) {
	storeMock.collections.pipeline = pipelines
	const wrapper = mount(LeadForm, {
		props,
		global: { mocks: { t: (_app, text) => text, n: (_app, s) => s } },
	})
	await flushPromises()
	return wrapper
}

describe('LeadForm', () => {
	it('reads an existing lead into the form', async () => {
		const lead = { id: 'l1', title: 'Ovens', labels: ['a'], value: 0, currency: 'EUR', probability: 0, stage: 'Contact', pipeline: 'p-lead' }
		const wrapper = await mountForm({ lead })
		expect(wrapper.vm.form).toEqual({ id: 'l1', title: 'Ovens', description: '', category: '', labels: ['a'], value: 0, currency: 'EUR', probability: 0, source: null, priority: 'normal', expectedCloseDate: null, client: null, contact: null, pipeline: 'p-lead', stage: 'Contact' })
		expect(wrapper.vm.form.labels).not.toBe(lead.labels)
		const bare = await mountForm({ lead: { id: 'l2', labels: 'x' } })
		expect(bare.vm.form).toMatchObject({ labels: [], value: null, currency: 'USD', probability: null })
	})

	it('puts a new lead on the default lead pipeline, in its first open stage', async () => {
		const wrapper = await mountForm()
		expect([wrapper.vm.form.pipeline, wrapper.vm.form.stage]).toEqual(['p-lead', 'Contact'])
		expect(wrapper.vm.stageOptions).toEqual(['New', 'Contact', 'Won'])
	})

	it('moves to the first open stage of a newly chosen pipeline, or none', async () => {
		const wrapper = await mountForm()
		wrapper.vm.form.pipeline = 'p-req'
		wrapper.vm.onPipelineChange()
		expect(wrapper.vm.form.stage).toBe('Intake')
		wrapper.vm.form.pipeline = 'p-empty'
		wrapper.vm.onPipelineChange()
		expect(wrapper.vm.form.stage).toBe(null)
		expect(wrapper.vm.stageOptions).toEqual([])
		wrapper.vm.form.pipeline = null
		wrapper.vm.onPipelineChange()
		expect(wrapper.vm.form.stage).toBe(null)
		expect(wrapper.vm.selectedPipeline).toBe(null)
	})

	it('saves the lead without its empty fields, with the stage order and entry time', async () => {
		const wrapper = await mountForm()
		Object.assign(wrapper.vm.form, { title: 'Ovens', value: null, currency: '', source: '', probability: null })
		wrapper.vm.onSave()
		const [saved] = wrapper.emitted('save')[0]
		expect(Object.keys(saved).sort()).toEqual(['description', 'labels', 'pipeline', 'priority', 'stage', 'stageEnteredAt', 'stageOrder', 'title'])
		expect(saved).toMatchObject({ title: 'Ovens', pipeline: 'p-lead', stage: 'Contact', stageOrder: 2 })
		Object.assign(wrapper.vm.form, { value: 0, currency: 'EUR', probability: 0, source: 'web', category: 'c', expectedCloseDate: '2026-12-01', client: 'c1', contact: 'k1', stage: '' })
		wrapper.vm.onSave()
		const [second] = wrapper.emitted('save')[1]
		expect(second).toMatchObject({ value: 0, currency: 'EUR', probability: 0, source: 'web', category: 'c', expectedCloseDate: '2026-12-01', client: 'c1', contact: 'k1' })
		expect(second).not.toHaveProperty('stage')
		expect(second).not.toHaveProperty('stageOrder')
	})

	it('keeps the entry time of an unchanged stage, and saves nothing while invalid', async () => {
		const wrapper = await mountForm({ lead: { id: 'l1', title: 'Ovens', stage: 'Contact', pipeline: 'p-lead' } })
		wrapper.vm.onSave()
		const [saved] = wrapper.emitted('save')[0]
		expect(saved.stageOrder).toBe(2)
		expect(saved).not.toHaveProperty('stageEnteredAt')
		wrapper.vm.form.title = ' '
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toHaveLength(1)
	})
})
