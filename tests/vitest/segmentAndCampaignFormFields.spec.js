// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The segment and campaign form dialogs as a user fills them in: every field
 * on screen writes into what Save sends, the rule builder reports its state,
 * the buttons close or save, and an error the server gives is shown.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

/**
 * A stub that renders its slots and keeps the props a test finds it by.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element it renders.
 * @return {object} The stub.
 */
function passthrough(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'label', 'inputLabel', 'modelValue', 'type', 'variant', 'disabled', 'entityType', 'fieldOptions'],
		emits: ['click', 'update:modelValue', 'closing', 'statusChange'],
		render() {
			const slots = Object.entries(this.$slots).map(([key, fn]) => h('div', { 'data-slot': key }, fn?.()))
			return h(tag, { 'data-stub': name, 'data-testid': this.$attrs['data-testid'] }, slots)
		},
	}
}

const toasts = []
const bus = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: passthrough('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: passthrough('NcButton', 'button'),
	NcDateTimePickerNative: passthrough('NcDateTimePickerNative'),
	NcLoadingIcon: passthrough('NcLoadingIcon'),
	NcNoteCard: passthrough('NcNoteCard'),
	NcSelect: passthrough('NcSelect'),
	NcTextArea: passthrough('NcTextArea'),
	NcTextField: passthrough('NcTextField'),
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: (m) => toasts.push(m) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: (name, payload) => bus.push([name, payload]), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/components/SegmentBuilder.vue', () => ({ default: passthrough('SegmentBuilder') }))
vi.mock('../../src/services/campaignsApi.js', () => ({ fetchCampaignVocabularies: vi.fn(), saveCampaign: vi.fn() }))

const campaigns = await import('../../src/services/campaignsApi.js')
const { default: SegmentFormDialog } = await import('../../src/dialogs/SegmentFormDialog.vue')
const { default: CampaignFormDialog } = await import('../../src/dialogs/CampaignFormDialog.vue')

/**
 * Mount a hosted dialog, open, and let it load.
 *
 * @param {object} component The dialog.
 * @param {object} [props] Its props besides show.
 * @param {object} [mocks] Global mocks.
 * @return {Promise<object>} The wrapper.
 */
async function openDialog(component, props = {}, mocks = {}) {
	const w = mount(component, { props: { show: true, ...props }, global: { mocks: { t: globalThis.t, ...mocks } } })
	await flushPromises()
	return w
}

/**
 * The child component with this name whose label (or input label) is the given text.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @param {string} label The label.
 * @return {object} The component.
 */
function field(w, name, label) {
	const found = w.findAllComponents({ name }).find((c) => c.props('label') === label || c.props('inputLabel') === label)
	if (!found) {
		throw new Error(`No ${name} labelled "${label}"`)
	}
	return found
}

/**
 * The button whose text is the given text.
 *
 * @param {object} w The wrapper.
 * @param {string} text The text.
 * @return {object} The button.
 */
function button(w, text) {
	const found = w.findAllComponents({ name: 'NcButton' }).find((c) => c.text().trim() === text)
	if (!found) {
		throw new Error(`No button "${text}"`)
	}
	return found
}

beforeEach(() => {
	toasts.length = 0
	bus.length = 0
	vi.clearAllMocks()
	axios.get = vi.fn()
	axios.post = vi.fn()
	axios.patch = vi.fn()
	campaigns.fetchCampaignVocabularies.mockResolvedValue({ sources: ['newsletter', 'linkedin'], mediums: ['email', 'social'] })
})

describe('SegmentFormDialog, filled in on screen', () => {
	it('creates the segment the fields, the audience and the rule builder describe', async () => {
		const close = vi.fn()
		const refresh = vi.fn()
		const w = await openDialog(SegmentFormDialog, { close, refresh })
		expect(button(w, 'Create segment').props('disabled')).toBe(true)
		expect(w.text()).toContain('Give the segment a name.')

		field(w, 'NcTextField', 'Segment name').vm.$emit('update:modelValue', ' Utrecht customers ')
		field(w, 'NcTextArea', 'Description').vm.$emit('update:modelValue', 'Everyone in Utrecht')
		field(w, 'NcSelect', 'Audience').vm.$emit('update:modelValue', { value: 'customer', label: 'Customers' })
		await flushPromises()
		const builder = w.findComponent({ name: 'SegmentBuilder' })
		expect(builder.props('entityType')).toBe('customer')
		const rules = { type: 'AND', children: [{ field: 'city', operator: 'equals', value: 'Utrecht' }] }
		builder.vm.$emit('update:modelValue', rules)
		builder.vm.$emit('statusChange', 'valid')
		await flushPromises()
		expect(button(w, 'Create segment').props('disabled')).toBe(false)

		axios.post.mockResolvedValueOnce({})
		button(w, 'Create segment').vm.$emit('click')
		await flushPromises()
		expect(axios.post).toHaveBeenCalledWith('/apps/pipelinq/api/segments', {
			name: 'Utrecht customers',
			description: 'Everyone in Utrecht',
			entityType: 'customer',
			rules,
		})
		expect(toasts).toEqual(['Segment created.'])
		expect(refresh).toHaveBeenCalledTimes(1)
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('shows the error the server gives when saving fails, and stays open', async () => {
		const close = vi.fn()
		const w = await openDialog(SegmentFormDialog, { close })
		field(w, 'NcTextField', 'Segment name').vm.$emit('update:modelValue', 'Broken')
		w.findComponent({ name: 'SegmentBuilder' }).vm.$emit('statusChange', 'valid')
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'Rule tree too deep' } } })
		await w.vm.save()
		await flushPromises()
		expect(w.findAllComponents({ name: 'NcNoteCard' }).map((c) => c.text())).toContain('Rule tree too deep')
		expect(close).not.toHaveBeenCalled()
		expect(w.vm.saving).toBe(false)
	})

	it('shows why an existing segment could not load instead of the form', async () => {
		axios.get.mockRejectedValueOnce({})
		const w = await openDialog(SegmentFormDialog, { item: { id: 's1' } })
		expect(axios.get).toHaveBeenCalledWith('/apps/pipelinq/api/segments/s1')
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('Could not load this segment.')
		expect(w.findComponent({ name: 'SegmentBuilder' }).exists()).toBe(false)
		expect(w.vm.saveHint).toBe('')
	})

	it('closes from Cancel and from the dialog itself', async () => {
		const close = vi.fn()
		const w = await openDialog(SegmentFormDialog, { close })
		button(w, 'Cancel').vm.$emit('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(close).toHaveBeenCalledTimes(2)
	})
})

describe('CampaignFormDialog, filled in on screen', () => {
	it('saves what every field on screen holds, then opens the new campaign', async () => {
		const close = vi.fn()
		const router = { push: vi.fn() }
		const w = await openDialog(CampaignFormDialog, { close }, { $router: router })
		expect(button(w, 'Save').props('disabled')).toBe(true)

		field(w, 'NcTextField', 'Name').vm.$emit('update:modelValue', 'Spring')
		field(w, 'NcTextField', 'Goal').vm.$emit('update:modelValue', 'More leads')
		field(w, 'NcSelect', 'Source').vm.$emit('update:modelValue', 'linkedin')
		field(w, 'NcSelect', 'Medium').vm.$emit('update:modelValue', 'social')
		field(w, 'NcSelect', 'Status').vm.$emit('update:modelValue', 'running')
		field(w, 'NcTextField', 'Budget in euro').vm.$emit('update:modelValue', '750')
		field(w, 'NcDateTimePickerNative', 'Starts at').vm.$emit('update:modelValue', new Date('2026-04-01T10:00:00Z'))
		field(w, 'NcDateTimePickerNative', 'Ends at').vm.$emit('update:modelValue', new Date('2026-05-31T10:00:00Z'))
		field(w, 'NcTextArea', 'Page summary').vm.$emit('update:modelValue', 'Spring offer')
		field(w, 'NcTextArea', 'Page body').vm.$emit('update:modelValue', '# Spring')
		await flushPromises()
		expect(button(w, 'Save').props('disabled')).toBe(false)

		campaigns.saveCampaign.mockResolvedValueOnce({ campaign: { '@self': { id: 'c7' } } })
		button(w, 'Save').vm.$emit('click')
		await flushPromises()
		expect(campaigns.saveCampaign).toHaveBeenCalledWith({
			name: 'Spring',
			goal: 'More leads',
			status: 'running',
			utmSource: 'linkedin',
			utmMedium: 'social',
			startsAt: '2026-04-01',
			endsAt: '2026-05-31',
			articleSummary: 'Spring offer',
			articleBody: '# Spring',
			budgetEur: 750,
		}, '')
		expect(close).toHaveBeenCalledTimes(1)
		expect(router.push).toHaveBeenCalledWith({ name: 'CampaignDetail', params: { id: 'c7' } })
	})

	it('shows the minted campaign value of an existing campaign, and refreshes the page after saving it', async () => {
		axios.get.mockResolvedValueOnce({ data: { name: 'Spring', utmCampaign: 'spring-2026' } })
		const close = vi.fn()
		const w = await openDialog(CampaignFormDialog, { close, item: { uuid: 'c1' } })
		expect(w.find('.campaign-form__minted').text()).toBe('Campaign value: spring-2026. It was minted from the name and does not change when you rename the campaign.')
		campaigns.saveCampaign.mockResolvedValueOnce({ campaign: { id: 'c1' } })
		await w.vm.save()
		expect(campaigns.saveCampaign.mock.calls[0][1]).toBe('c1')
		expect(bus).toEqual([['cn:page:refresh', {}]])
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('names the refused value and the allowed list, and shows a failed save', async () => {
		const w = await openDialog(CampaignFormDialog)
		w.vm.form.name = 'Spring'
		campaigns.saveCampaign.mockResolvedValueOnce({ error: 'unknown_utm_medium', value: 'fax', allowed: ['email', 'social'] })
		await w.vm.save()
		await flushPromises()
		expect(w.findComponent({ name: 'NcNoteCard' }).text()).toBe('fax is not one of the allowed mediums: email, social')
		campaigns.saveCampaign.mockRejectedValueOnce({})
		await w.vm.save()
		expect(w.vm.error).toBe('Could not save the campaign.')
		expect(w.vm.saving).toBe(false)
	})

	it('closes from Cancel and from the dialog itself', async () => {
		const close = vi.fn()
		const w = await openDialog(CampaignFormDialog, { close })
		button(w, 'Cancel').vm.$emit('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(close).toHaveBeenCalledTimes(2)
	})
})
