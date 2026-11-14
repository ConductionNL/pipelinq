// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request form: an existing request read into the form, a new request on
 * the default request pipeline's first open stage, and the request it saves.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-42
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(async () => []),
	fetchCollectionForOptions: vi.fn(async () => []),
	fetchObject: vi.fn(async () => null),
	collections: { pipeline: [] },
	objects: {},
}))

vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'pieter', displayName: 'Pieter Jansen' }),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/store/modules/requestChannels.js', () => ({
	useRequestChannelsStore: () => ({
		fetchChannels: vi.fn(async () => []),
		channelNames: [],
	}),
}))
vi.mock('../../src/services/ticketCategories.js', () => ({
	fetchTicketCategories: vi.fn(async () => []),
	categoryOptions: () => [],
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({
	default: { name: 'ClientCreateDialog', render: () => null },
}))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({
	default: { name: 'ContactCreateDialog', render: () => null },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnResourceSelect: { name: 'CnResourceSelect', render: () => null },
	CnSearchSelect: {
		name: 'CnSearchSelect',
		props: ['modelValue', 'options', 'loading', 'filterable', 'inputLabel', 'placeholder'],
		emits: ['search', 'update:modelValue'],
		render: () => null,
	},
}))
vi.mock('@nextcloud/vue', () => {
	const field = (name) => ({
		name,
		props: ['label', 'modelValue', 'ariaLabelCombobox', 'options', 'error', 'helperText', 'getOptionLabel'],
		emits: ['update:modelValue'],
		render() {
			return h('label', { 'data-label': this.ariaLabelCombobox || this.label })
		},
	})
	return {
		NcButton: {
			name: 'NcButton',
			emits: ['click'],
			render() {
				return h(
					'button',
					{ onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcCheckboxRadioSwitch: {
			name: 'NcCheckboxRadioSwitch',
			props: ['value', 'modelValue'],
			render() {
				return h('span', { 'data-type': this.value }, [
					this.$slots.icon?.(),
					this.$slots.default?.(),
				])
			},
		},
		NcNoteCard: {
			name: 'NcNoteCard',
			render() {
				return h('p', this.$slots.default?.())
			},
		},
		NcDateTimePickerNative: field('NcDateTimePickerNative'),
		NcSelect: field('NcSelect'),
		NcTextArea: field('NcTextArea'),
		NcTextField: field('NcTextField'),
	}
})

vi.mock('vue-material-design-icons/MessageTextOutline.vue', () => ({ default: { render: () => h('i', { 'data-icon': 'request' }) } }))
vi.mock('vue-material-design-icons/AlertOutline.vue', () => ({ default: { render: () => h('i', { 'data-icon': 'melding' }) } }))
vi.mock('vue-material-design-icons/AlertCircleOutline.vue', () => ({ default: { render: () => h('i', { 'data-icon': 'complaint' }) } }))
vi.mock('vue-material-design-icons/PhoneOutline.vue', () => ({ default: { render: () => h('i', { 'data-icon': 'interaction' }) } }))
vi.mock('vue-material-design-icons/ClockOutline.vue', () => ({ default: { render: () => h('i', { 'data-icon': 'clock' }) } }))

globalThis.t = (_app, text, vars) =>
	vars ? text.replace('{name}', vars.name) : text

globalThis.t = (_app, text, vars) =>
	vars ? text.replace('{name}', vars.name) : text

const { default: RequestForm } =
	await import('../../src/views/requests/RequestForm.vue')

const pipelines = [
	{ id: 'p-lead', title: 'Sales', isDefault: true, entityType: 'lead', stages: [{ name: 'Won', order: 3, isClosed: true }, { name: 'New', order: 1, isClosed: true }, { name: 'Contact', order: 2 }] },
	{ id: 'p-req', title: 'Desk', isDefault: true, entityType: 'request', stages: [{ name: 'Open', order: 2 }, { name: 'Intake', order: 1 }] },
	{ id: 'p-empty', title: 'Empty', entityType: 'both' },
]

async function mountForm(props = {}) {
	storeMock.collections.pipeline = pipelines
	const wrapper = mount(RequestForm, {
		props,
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

describe('RequestForm', () => {
	it('reads an existing request into the form', async () => {
		const wrapper = await mountForm({ request: { id: 'r1', title: 'Pothole', channel: 'phone', pipeline: 'p-req', stage: 'Open' } })
		expect(wrapper.vm.form).toEqual({ id: 'r1', title: 'Pothole', description: '', status: 'new', priority: 'normal', channel: 'phone', category: '', occurredAt: null, client: null, contact: null, pipeline: 'p-req', stage: 'Open' })
	})

	it('puts a new request on the default request pipeline, in its first open stage, with the pre-linked client', async () => {
		const wrapper = await mountForm({ preLinkedClient: 'c1' })
		expect([wrapper.vm.form.pipeline, wrapper.vm.form.stage, wrapper.vm.form.client]).toEqual(['p-req', 'Intake', 'c1'])
		wrapper.vm.form.pipeline = 'p-lead'
		wrapper.vm.onPipelineChange()
		expect(wrapper.vm.form.stage).toBe('Contact')
		expect(wrapper.vm.stageOptions).toEqual(['New', 'Contact', 'Won'])
	})

	it('saves the request without its empty fields', async () => {
		const wrapper = await mountForm()
		Object.assign(wrapper.vm.form, { title: 'Pothole', channel: '', category: '', stage: null })
		wrapper.vm.onSave()
		const [saved] = wrapper.emitted('save')[0]
		expect(saved).not.toHaveProperty('channel')
		expect(saved).not.toHaveProperty('category')
		expect(saved).not.toHaveProperty('stage')
		expect(saved).toMatchObject({ title: 'Pothole', pipeline: 'p-req' })
		Object.assign(wrapper.vm.form, { channel: 'mail', occurredAt: '2026-01-01', client: 'c', contact: 'k', stage: 'Open', category: 'x', assignee: 'me', ticketType: 'request' })
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')[1][0]).toMatchObject({ channel: 'mail', occurredAt: '2026-01-01', client: 'c', contact: 'k', stage: 'Open', category: 'x', assignee: 'me', ticketType: 'request' })
		wrapper.vm.form.title = ''
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')).toHaveLength(2)
	})
})
