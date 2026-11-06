// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new-request form as board PqNieuwVerzoek draws it (`boardLayout`): the
 * kind of ticket first, the subject and the question, priority and handler,
 * and the fields the board leaves out one click away under More details.
 * The edit form (no `boardLayout`) is unchanged.
 *
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
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

const { default: RequestForm } =
	await import('../../src/views/requests/RequestForm.vue')

async function mountForm(props = {}) {
	const wrapper = mount(RequestForm, {
		props,
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}
function labels(wrapper) {
	return wrapper.findAll('[data-label]').map((el) => el.attributes('data-label'))
}

describe('RequestForm board layout', () => {
	it('offers the four kinds of ticket and starts on a request, handled by the person filling it in', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		expect(
			wrapper.findAll('[data-type]').map((el) => el.attributes('data-type')),
		).toEqual(['request', 'melding', 'complaint', 'interaction'])
		expect(wrapper.vm.form.ticketType).toBe('request')
		expect(wrapper.vm.form.assignee).toBe('pieter')
		expect(wrapper.vm.assigneeOptions.map((o) => o.label)).toEqual([
			'Me, Pieter Jansen',
			'Nobody, the queue',
		])
	})

	it('asks for the subject and the question, and keeps status, requested at and pipeline behind More details', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		expect(labels(wrapper)).toEqual(
			expect.arrayContaining([
				'Subject',
				'What does the customer ask?',
				'Handler',
			]),
		)
		expect(labels(wrapper)).not.toContain('Status')
		expect(labels(wrapper)).not.toContain('Requested at')
		await wrapper.find('[data-testid="request-form-more"]').trigger('click')
		expect(labels(wrapper)).toEqual(
			expect.arrayContaining(['Status', 'Requested at', 'Pipeline']),
		)
	})

	it('sends the kind and the handler, and leaves an empty handler out', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		wrapper.vm.form.title = 'Straatfeest'
		wrapper.vm.form.ticketType = 'complaint'
		wrapper.vm.form.assignee = null
		wrapper.vm.onSave()
		const [data] = wrapper.emitted('save')[0]
		expect(data.ticketType).toBe('complaint')
		expect('assignee' in data).toBe(false)
	})

	it('draws the kinds as separate buttons, each with its icon', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const kinds = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(kinds).toHaveLength(4)
		for (const kind of kinds) {
			expect(kind.attributes('buttonvariantgrouped')).toBeUndefined()
			expect(kind.find('[data-icon]').exists()).toBe(true)
		}
	})

	it('names the priority and the status, not their stored values', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const priority = wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('ariaLabelCombobox') === 'Priority')
		expect(priority.props('getOptionLabel')('normal')).toBe('Normal')
	})

	it('does not call the subject missing before anyone typed in it', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const subject = wrapper.findAllComponents({ name: 'NcTextField' }).find((c) => c.props('label') === 'Subject')
		expect(subject.props('error')).toBe(false)
		subject.vm.$emit('update:modelValue', '')
		await flushPromises()
		expect(subject.props('error')).toBe(true)
		expect(subject.props('helperText')).toBe('Title is required')
	})

	it('draws New client as the tonal pill', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const button = wrapper.findAll('button').find((b) => b.text() === 'New client')
		expect(button.classes()).toContain('cn-button--tonal')
	})

	it('says when the answer is due as a plain line on the sunken ground, with a clock', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const note = wrapper.find('[data-testid="request-form-term"]')
		expect(note.exists()).toBe(true)
		expect(note.find('[data-icon]').exists()).toBe(true)
		expect(wrapper.findComponent({ name: 'NcNoteCard' }).exists()).toBe(false)
	})

	it('leaves the edit form as it was', async () => {
		const wrapper = await mountForm({
			request: { id: 't-1', title: 'A', status: 'new' },
		})
		expect(wrapper.find('[data-testid="request-form-type"]').exists()).toBe(
			false,
		)
		expect(labels(wrapper)).toEqual(
			expect.arrayContaining(['Title', 'Description', 'Status']),
		)
	})
})

describe('RequestForm board layout: the client search', () => {
	const SANNE = { id: 'c-1', name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14' }
	const OOST = { id: 'c-2', name: 'Buurtvereniging Oost', type: 'organization', address: '' }

	it('draws Client as the search field, not the edit form\'s picker', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		const search = wrapper.findComponent({ name: 'CnSearchSelect' })
		expect(search.exists()).toBe(true)
		expect(search.props('inputLabel')).toBe('Client')
		expect(search.props('filterable')).toBe(false)
		expect(wrapper.findComponent({ name: 'CnResourceSelect' }).exists()).toBe(false)
	})

	it('lists the matching clients with initials, their kind and their address', async () => {
		storeMock.fetchCollectionForOptions.mockResolvedValueOnce([SANNE, OOST])
		const wrapper = await mountForm({ boardLayout: true })
		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('search', 'de Vries')
		await flushPromises()
		expect(storeMock.fetchCollectionForOptions).toHaveBeenLastCalledWith('client', { _search: 'de Vries', _limit: 20 })
		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options')).toEqual([
			{ id: 'c-1', label: 'Sanne de Vries', secondary: 'Resident · Lindelaan 14', avatar: true },
			{ id: 'c-2', label: 'Buurtvereniging Oost', secondary: 'Organisation', avatar: true },
		])
	})

	it('keeps the newest search when an older answer arrives late', async () => {
		let answerOld
		storeMock.fetchCollectionForOptions
			.mockImplementationOnce(() => new Promise((resolve) => { answerOld = resolve }))
			.mockResolvedValueOnce([OOST])
		const wrapper = await mountForm({ boardLayout: true })
		const search = wrapper.findComponent({ name: 'CnSearchSelect' })
		search.vm.$emit('search', 'de')
		search.vm.$emit('search', 'Oost')
		await flushPromises()
		answerOld([SANNE])
		await flushPromises()
		expect(search.props('options').map((o) => o.id)).toEqual(['c-2'])
	})

	it('puts a picked client on the request', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		wrapper.findComponent({ name: 'CnSearchSelect' }).vm.$emit('update:modelValue', 'c-1')
		expect(wrapper.vm.form.client).toBe('c-1')
	})

	it('names the client the dialog opened with', async () => {
		storeMock.fetchObject.mockResolvedValueOnce(SANNE)
		const wrapper = await mountForm({ boardLayout: true, preLinkedClient: 'c-1' })
		expect(storeMock.fetchObject).toHaveBeenCalledWith('client', 'c-1')
		expect(wrapper.findComponent({ name: 'CnSearchSelect' }).props('options')[0].label).toBe('Sanne de Vries')
	})
})

