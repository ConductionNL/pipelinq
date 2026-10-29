// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Categorie on the request form: a select over the tenant's own active
 * ticketCategory list (name shown, key stored), the free-text field kept for
 * a tenant without one, and a stored category outside the list kept.
 *
 * @spec openspec/changes/request-category-from-vocabulary/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

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

globalThis.t = (_app, text) => text

const { categoryOptions, fetchTicketCategories } =
	await import('../../src/services/ticketCategories.js')
const { default: RequestForm } =
	await import('../../src/views/requests/RequestForm.vue')

const rows = [
	{ key: 'vergunningen', name: 'Vergunningen', active: true },
	{ key: 'afval', name: 'Afval' },
	{ key: 'oud', name: 'Oud', active: false },
	{ name: 'no key' },
]

async function mountForm(props = {}) {
	const wrapper = mount(RequestForm, {
		props,
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

function categoryField(wrapper) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('ariaLabelCombobox') === 'Category')
}

beforeEach(() => {
	axiosMock.get.mockReset()
})

describe('categoryOptions', () => {
	it('offers the active categories by name, sorted, with the key as the value', () => {
		expect(categoryOptions(rows)).toEqual([
			{ value: 'afval', label: 'Afval' },
			{ value: 'vergunningen', label: 'Vergunningen' },
		])
	})

	it('keeps a stored category the list does not name', () => {
		expect(categoryOptions(rows, 'oud-onderwerp')).toEqual([
			{ value: 'afval', label: 'Afval' },
			{ value: 'vergunningen', label: 'Vergunningen' },
			{ value: 'oud-onderwerp', label: 'oud-onderwerp' },
		])
		expect(categoryOptions(rows, 'afval')).toHaveLength(2)
	})

	it('offers nothing for an empty or missing list', () => {
		expect(categoryOptions([])).toEqual([])
		expect(categoryOptions(null, 'x')).toEqual([])
	})
})

describe('fetchTicketCategories', () => {
	it('reads the ticketCategory objects of the pipelinq register', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: rows } })
		expect(await fetchTicketCategories()).toEqual(rows)
		expect(axiosMock.get.mock.calls[0][0]).toContain('/apps/openregister/api/objects/pipelinq/ticketCategory')
	})

	it('answers an empty list when the read fails', async () => {
		axiosMock.get.mockRejectedValue(new Error('down'))
		expect(await fetchTicketCategories()).toEqual([])
	})
})

describe('RequestForm Categorie', () => {
	it('is a select over the categories, and saves the picked key', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: rows } })
		const wrapper = await mountForm({ boardLayout: true })
		const select = categoryField(wrapper)
		expect(select).toBeTruthy()
		expect(select.props('options').map((o) => o.label)).toEqual(['Afval', 'Vergunningen'])
		select.vm.$emit('update:modelValue', { value: 'vergunningen', label: 'Vergunningen' })
		wrapper.vm.form.title = 'Straatfeest'
		wrapper.vm.onSave()
		expect(wrapper.emitted('save')[0][0].category).toBe('vergunningen')
	})

	it('stays a text field for a tenant without categories', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: [] } })
		const wrapper = await mountForm({ boardLayout: true })
		expect(categoryField(wrapper)).toBeUndefined()
		expect(wrapper.findAllComponents({ name: 'NcTextField' }).some((c) => c.props('label') === 'Category')).toBe(true)
	})

	it('shows a stored category outside the list as selected', async () => {
		axiosMock.get.mockResolvedValue({ data: { results: rows } })
		const wrapper = await mountForm({ request: { id: 't1', title: 'Oud', category: 'oud-onderwerp' } })
		const select = categoryField(wrapper)
		expect(select.props('modelValue')).toEqual({ value: 'oud-onderwerp', label: 'oud-onderwerp' })
	})
})
