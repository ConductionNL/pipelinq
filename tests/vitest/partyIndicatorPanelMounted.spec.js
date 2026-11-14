// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The party warnings panel as mounted: the compact none line per party kind,
 * the list with its acknowledgement, and the add-a-warning form with its
 * vocabulary, save and cancel, over a mocked HTTP client.
 *
 * @spec openspec/specs/contactmomenten/spec.md#requirement-the-contact-moment-panel-shall-show-the-partys-indicators-req-cmi-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDetailCard: defineComponent({
			name: 'CnDetailCard',
			setup(props, { slots }) {
				return () => h('section', slots.default ? slots.default() : [])
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	const field = (name) => defineComponent({
		name,
		props: { modelValue: { default: null } },
		emits: ['update:modelValue'],
		setup() {
			return () => h('div', { class: 'field-stub' })
		},
	})
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { type: { type: String, default: 'button' }, disabled: { type: Boolean, default: false } },
			setup(props, { slots }) {
				return () => h('button', { type: props.type, disabled: props.disabled }, slots.default ? slots.default() : [])
			},
		}),
		NcLoadingIcon: defineComponent({ name: 'NcLoadingIcon', setup: () => () => h('span') }),
		NcSelect: field('NcSelect'),
		NcTextField: field('NcTextField'),
	}
})

let PartyIndicatorPanel

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	PartyIndicatorPanel = (await import('../../src/components/PartyIndicatorPanel.vue')).default
}, 60000)

/**
 * Answer the party leaf with the given indicators and the vocabulary with two codes.
 *
 * @param {Array<object>} indicators The indicators in force.
 * @return {void}
 */
function answer(indicators) {
	http.get.mockImplementation(async (url) => (url.includes('/leaves/party/')
		? { data: { indicators } }
		: { data: { results: [{ code: 'overleden', label: 'Overleden' }, { code: 'old', active: false }, { label: 'no code' }] } }))
}

/**
 * Mount the panel and let its load settle.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(props = {}) {
	const wrapper = mount(PartyIndicatorPanel, {
		props: { partyId: 'client-1', ...props },
		global: { mocks: { t: globalThis.t }, stubs: { ShieldCheckOutline: true } },
	})
	await flushPromises()
	return wrapper
}

/**
 * The button with the given text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The text.
 * @return {object} The button.
 */
function button(wrapper, text) {
	const found = wrapper.findAll('button').find((b) => b.text() === text)
	expect(found, text).toBeDefined()
	return found
}

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

describe('PartyIndicatorPanel mounted', () => {
	it.each([
		['person', 'No warnings on this resident.'],
		['organization', 'No warnings on this organisation.'],
		['contact', 'No warnings on this contact person.'],
		['', 'No warnings on this record.'],
	])('names the %s in the compact none line', async (partyKind, text) => {
		answer([])
		const wrapper = await mountPanel({ compact: true, framed: true, partyKind })
		expect(wrapper.find('.party-indicators__none').text()).toContain(text)
		expect(wrapper.classes()).toEqual(expect.arrayContaining(['party-indicators--compact', 'party-indicators--framed']))
	})

	it('lists warnings with severity, period, source and acknowledgement', async () => {
		answer([
			{ id: 'v1', label: 'Agressie', severity: 'critical', validFrom: '2026-01-01', validUntil: '2026-12-31', source: 'KCC', requiresAcknowledgement: true },
			{ id: 'v2', label: 'Info', severity: 'info', validFrom: '2026-02-01', acknowledgedAt: '2026-03-01T10:00:00Z', acknowledgedBy: 'admin' },
			{ id: 'v3', label: 'Let op', severity: 'warning', validFrom: '2026-02-02' },
		])
		const wrapper = await mountPanel()

		const items = wrapper.findAll('.party-indicators__item')
		expect(items.map((i) => i.find('.party-indicators__severity').text())).toEqual(['Critical', 'Warning', 'Information'])
		expect(items[0].find('.party-indicators__meta').text()).toContain('Source: KCC')
		expect(items[2].text()).toContain('Seen by admin on')

		http.post.mockResolvedValue({ data: { indicatorValue: {} } })
		await button(items[0], 'I have seen this').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/party-indicator-values/v1/acknowledge')
		expect(http.get).toHaveBeenCalledTimes(2)
	})

	it('reports a failed acknowledgement and a failed load', async () => {
		answer([{ id: 'v1', label: 'A', severity: 'critical', validFrom: '2026-01-01', requiresAcknowledgement: true }])
		const wrapper = await mountPanel()

		http.post.mockRejectedValue(new Error('no'))
		await button(wrapper, 'I have seen this').trigger('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('The acknowledgement could not be saved.')
		expect(wrapper.vm.busyId).toBe('')

		http.get.mockRejectedValue(new Error('down'))
		await wrapper.setProps({ partyId: 'client-2' })
		await flushPromises()
		expect(wrapper.find('.party-indicators__state--error').text()).toBe('Warnings could not be loaded.')
	})

	it('adds a warning from the vocabulary', async () => {
		answer([])
		const wrapper = await mountPanel({ compact: true })

		await wrapper.find('[data-testid="party-indicators-add"]').trigger('click')
		await flushPromises()
		expect(wrapper.vm.vocabulary).toEqual([{ code: 'overleden', label: 'Overleden' }])
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.vm.$attrs.reduce({ code: 'overleden' })).toBe('overleden')
		select.vm.$emit('update:modelValue', 'overleden')
		wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Gemeente')
		await wrapper.find('#party-indicator-from').setValue('2026-05-01')
		await wrapper.find('#party-indicator-until').setValue('2026-06-01')

		http.post.mockResolvedValue({ data: {} })
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/partyIndicatorValue', expect.objectContaining({
			party: 'client-1', indicator: 'overleden', validFrom: '2026-05-01', validUntil: '2026-06-01', source: 'Gemeente',
		}))
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Warning saved')
		expect(wrapper.find('form').exists()).toBe(false)
	})

	it('reports a failed vocabulary or save and cancels the form', async () => {
		answer([{ id: 'v3', label: 'Let op', severity: 'warning', validFrom: '2026-02-02' }])
		const wrapper = await mountPanel()

		http.get.mockRejectedValueOnce(new Error('down'))
		await button(wrapper, 'Add a warning').trigger('click')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('The list of warnings could not be loaded.')
		expect(wrapper.vm.vocabulary).toEqual([])

		wrapper.vm.form.indicator = 'x'
		http.post.mockRejectedValue(new Error('no'))
		await wrapper.find('form').trigger('submit')
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('The warning could not be saved.')
		expect(wrapper.vm.saving).toBe(false)

		await button(wrapper, 'Cancel').trigger('click')
		expect(wrapper.find('form').exists()).toBe(false)
	})
})
