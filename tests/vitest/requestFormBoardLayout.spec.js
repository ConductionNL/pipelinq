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
		props: ['label', 'modelValue', 'ariaLabelCombobox', 'options'],
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
				return h(
					'span',
					{ 'data-type': this.value },
					this.$slots.default?.(),
				)
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
	it('offers the three kinds of ticket and starts on a request, handled by the person filling it in', async () => {
		const wrapper = await mountForm({ boardLayout: true })
		expect(
			wrapper.findAll('[data-type]').map((el) => el.attributes('data-type')),
		).toEqual(['request', 'complaint', 'interaction'])
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
