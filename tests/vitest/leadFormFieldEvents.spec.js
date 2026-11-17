// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead form's fields as a user drives them: every text field writes its
 * own form key, the value field turns text into a number (and empty into
 * none), the currency is normalised as it is typed, the select options are
 * reduced to their ids, the labels and contact pickers write back, and the
 * pipeline error and stage placeholder follow the chosen pipeline.
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
	loadState: () => ({ currency: 'EUR' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/store/modules/leadSources.js', () => ({
	useLeadSourcesStore: () => ({
		fetchSources: vi.fn(async () => []),
		sourceNames: ['web'],
	}),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('../../src/components/LabelSelect.vue', () => ({
	default: {
		name: 'LabelSelect',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render: () => null,
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: {
		name: 'CnResourceSelect',
		props: ['schema', 'modelValue'],
		emits: ['update:modelValue'],
		render: () => null,
	},
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['label', 'modelValue', 'reduce', 'ariaLabelCombobox', 'placeholder', 'disabled', 'helperText', 'error'],
		emits: ['update:modelValue'],
		render() {
			return h('label', { 'data-label': this.label || this.ariaLabelCombobox })
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			props: ['disabled'],
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

const { default: LeadForm } = await import('../../src/views/leads/LeadForm.vue')

const pipelines = [
	{ id: 'p-lead', title: 'Sales', isDefault: true, entityType: 'lead', stages: [{ name: 'New', order: 1 }] },
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

function byLabel(wrapper, name, label) {
	return wrapper
		.findAllComponents({ name })
		.find((c) => (c.props('ariaLabelCombobox') || c.props('label')) === label)
}

describe('LeadForm field events', () => {
	it('writes each text field into its own form key', async () => {
		const wrapper = await mountForm()
		byLabel(wrapper, 'NcTextField', 'Title').vm.$emit('update:modelValue', 'Ovens')
		byLabel(wrapper, 'NcTextField', 'Description').vm.$emit('update:modelValue', 'Six ovens')
		byLabel(wrapper, 'NcTextField', 'Category').vm.$emit('update:modelValue', 'Kitchens')
		expect(wrapper.vm.form).toMatchObject({ title: 'Ovens', description: 'Six ovens', category: 'Kitchens' })
	})

	it('reads the value as a number, empty as none, and shows it as text', async () => {
		const wrapper = await mountForm()
		const value = () => byLabel(wrapper, 'NcTextField', 'Value')
		expect(value().props('modelValue')).toBe('')
		value().vm.$emit('update:modelValue', '1250')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.form.value).toBe(1250)
		expect(value().props('modelValue')).toBe('1250')
		value().vm.$emit('update:modelValue', '-5')
		await wrapper.vm.$nextTick()
		expect(value().props('error')).toBe(true)
		expect(value().props('helperText')).toBe('Value must be non-negative')
		value().vm.$emit('update:modelValue', '')
		expect(wrapper.vm.form.value).toBe(null)
	})

	it('normalises the currency as it is typed', async () => {
		const wrapper = await mountForm()
		byLabel(wrapper, 'NcTextField', 'Currency').vm.$emit('update:modelValue', ' usd ')
		expect(wrapper.vm.form.currency).toBe('USD')
	})

	it('reduces the source, priority and pipeline options to their ids', async () => {
		const wrapper = await mountForm()
		expect(byLabel(wrapper, 'NcSelect', 'Source').props('reduce')({ id: 'web', label: 'Web' })).toBe('web')
		expect(byLabel(wrapper, 'NcSelect', 'Priority').props('reduce')({ value: 'high', label: 'High' })).toBe('high')
		expect(byLabel(wrapper, 'NcSelect', 'Pipeline').props('reduce')({ value: 'p-lead', label: 'Sales' })).toBe('p-lead')
	})

	it('writes the date picker and the labels back into the form', async () => {
		const wrapper = await mountForm()
		byLabel(wrapper, 'NcDateTimePickerNative', 'Expected close date').vm.$emit('update:modelValue', new Date(2026, 11, 1))
		expect(wrapper.vm.form.expectedCloseDate).toBe('2026-12-01')
		wrapper.findComponent({ name: 'LabelSelect' }).vm.$emit('update:modelValue', ['hot'])
		expect(wrapper.vm.form.labels).toEqual(['hot'])
	})

	it('stores a cleared contact as none', async () => {
		const wrapper = await mountForm()
		const contact = wrapper.findAllComponents({ name: 'CnResourceSelect' }).find((c) => c.props('schema') === 'contact')
		contact.vm.$emit('update:modelValue', 'k1')
		expect(wrapper.vm.form.contact).toBe('k1')
		contact.vm.$emit('update:modelValue', '')
		expect(wrapper.vm.form.contact).toBe(null)
	})

	it('shows the pipeline error and asks for a pipeline first once it is cleared', async () => {
		const wrapper = await mountForm()
		const stage = () => byLabel(wrapper, 'NcSelect', 'Stage')
		expect(stage().props('placeholder')).toBe('Select stage')
		expect(wrapper.find('.field-error').exists()).toBe(false)
		byLabel(wrapper, 'NcSelect', 'Pipeline').vm.$emit('update:modelValue', null)
		await wrapper.vm.$nextTick()
		expect(stage().props('placeholder')).toBe('Select pipeline first')
		expect(stage().props('disabled')).toBe(true)
		expect(wrapper.find('.field-error').text()).toBe('Pipeline is required')
	})

	it('labels the save button by whether the lead exists', async () => {
		const created = await mountForm()
		expect(created.findAll('button').map((b) => b.text())).toEqual(['Cancel', 'Create'])
		const edited = await mountForm({ lead: { id: 'l1', title: 'Ovens', pipeline: 'p-lead' } })
		expect(edited.findAll('button').map((b) => b.text())).toEqual(['Cancel', 'Save'])
	})
})
