// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The segment, campaign and journey forms as rendered: every field a user
 * types or picks into reaches the form's model, the branches that depend on a
 * choice appear and disappear, and errors show in the dialog. The Nextcloud
 * fields are replaced by stubs that render their slots and emit
 * `update:modelValue` the way the real ones do.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

/**
 * A stub that renders its slots and marks itself with its label.
 *
 * @param {string} name The component name.
 * @return {object} The stub.
 */
function field(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'inputLabel', 'name', 'disabled', 'options', 'type', 'variant'],
		emits: ['update:modelValue', 'click', 'closing'],
		render() {
			const slots = Object.values(this.$slots).map((slot) => slot?.())
			return h('div', {
				'data-stub': name,
				'data-label': this.inputLabel || this.label || '',
				onClick: () => this.$emit('click'),
			}, slots)
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: field('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: field('NcButton'),
	NcDateTimePickerNative: field('NcDateTimePickerNative'),
	NcLoadingIcon: field('NcLoadingIcon'),
	NcNoteCard: field('NcNoteCard'),
	NcSelect: field('NcSelect'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('../../src/services/journeysApi.js', () => ({ fetchJourney: vi.fn(), saveJourney: vi.fn() }))
vi.mock('../../src/services/campaignsApi.js', () => ({ fetchCampaignVocabularies: vi.fn(), saveCampaign: vi.fn() }))
vi.mock('../../src/components/SegmentBuilder.vue', () => ({ default: field('SegmentBuilder') }))

const campaigns = await import('../../src/services/campaignsApi.js')
const { default: SegmentFormDialog } = await import('../../src/dialogs/SegmentFormDialog.vue')
const { default: CampaignFormDialog } = await import('../../src/dialogs/CampaignFormDialog.vue')
const { default: JourneyFormDialog } = await import('../../src/dialogs/JourneyFormDialog.vue')

/**
 * Mount a hosted dialog open and let it load.
 *
 * @param {object} component The dialog.
 * @param {object} [props] Props besides show.
 * @return {Promise<object>} The wrapper.
 */
async function open(component, props = {}) {
	const w = mount(component, { props: { show: true, ...props }, global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

/**
 * Type or pick a value into the field with this label.
 *
 * @param {object} w The wrapper.
 * @param {string} label The field's label.
 * @param {*} value The value.
 * @return {Promise<void>}
 */
async function enter(w, label, value) {
	const target = w.findAllComponents({ name: 'NcTextField' })
		.concat(w.findAllComponents({ name: 'NcTextArea' }))
		.concat(w.findAllComponents({ name: 'NcSelect' }))
		.concat(w.findAllComponents({ name: 'NcDateTimePickerNative' }))
		.concat(w.findAllComponents({ name: 'SegmentBuilder' }))
		.find((c) => (c.props('inputLabel') || c.props('label') || c.vm.$options.name) === label)
	expect(target, label).toBeTruthy()
	target.vm.$emit('update:modelValue', value)
	await flushPromises()
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get = vi.fn()
	axios.post = vi.fn()
	axios.patch = vi.fn()
})

describe('SegmentFormDialog as rendered', () => {
	it('takes the name, audience, description and rules a user enters', async () => {
		const w = await open(SegmentFormDialog)

		expect(w.text()).toContain('Give the segment a name.')
		await enter(w, 'Segment name', 'Inactive leads')
		await enter(w, 'Description', 'No contact in a year')
		await enter(w, 'SegmentBuilder', { type: 'AND', children: [{ field: 'name' }] })
		expect(w.text()).toContain('Changing the audience clears the rules.')
		await enter(w, 'Audience', { value: 'customer', label: 'Customers' })

		expect(w.vm.model).toEqual({
			name: 'Inactive leads',
			description: 'No contact in a year',
			entityType: 'customer',
			rules: { type: 'AND', children: [] },
		})
		expect(w.text()).toContain('Create segment')
		w.findComponent({ name: 'SegmentBuilder' }).vm.$emit('statusChange', 'incomplete')
		await flushPromises()
		expect(w.text()).toContain('Complete or remove the unfinished conditions.')
	})

	it('shows a load error instead of the form, and says Save changes when editing', async () => {
		axios.get.mockRejectedValueOnce({ response: { data: { error: 'Segment not found.' } } })
		const w = await open(SegmentFormDialog, { item: { id: 's-1' } })

		expect(w.text()).toContain('Segment not found.')
		expect(w.text()).toContain('Save changes')
		expect(w.findComponent({ name: 'SegmentBuilder' }).exists()).toBe(false)
	})

	it('shows a refused save under the form', async () => {
		const w = await open(SegmentFormDialog)
		w.vm.saveError = 'Rules are invalid.'
		await flushPromises()

		expect(w.text()).toContain('Rules are invalid.')
	})
})

describe('CampaignFormDialog as rendered', () => {
	it('takes every field a user enters, and shows the minted campaign value', async () => {
		campaigns.fetchCampaignVocabularies.mockResolvedValue({ sources: ['newsletter'], mediums: ['email'] })
		const w = await open(CampaignFormDialog)

		await enter(w, 'Name', 'Autumn')
		await enter(w, 'Goal', 'Renewals')
		await enter(w, 'Source', 'newsletter')
		await enter(w, 'Medium', 'email')
		await enter(w, 'Status', 'running')
		await enter(w, 'Budget in euro', '250')
		await enter(w, 'Starts at', '2026-10-01')
		await enter(w, 'Ends at', '2026-10-31')
		await enter(w, 'Page summary', 'Short')
		await enter(w, 'Page body', 'Long')

		expect(w.vm.form).toMatchObject({ name: 'Autumn', goal: 'Renewals', articleSummary: 'Short', articleBody: 'Long' })
		expect([w.vm.source, w.vm.medium, w.vm.status, w.vm.budget, w.vm.startsAt, w.vm.endsAt])
			.toEqual(['newsletter', 'email', 'running', '250', '2026-10-01', '2026-10-31'])
		expect(w.findAll('.campaign-form__minted')).toHaveLength(0)

		w.vm.form.utmCampaign = 'autumn-2026'
		w.vm.error = 'Could not save.'
		await flushPromises()
		expect(w.find('.campaign-form__minted').text()).toContain('autumn-2026')
		expect(w.text()).toContain('Could not save.')
	})
})

describe('JourneyFormDialog as rendered', () => {
	it('takes every field, and shows the schedule and mailing fields only when they apply', async () => {
		const w = await open(JourneyFormDialog)

		await enter(w, 'Name', 'Welcome')
		await enter(w, 'Description', 'New contacts')
		await enter(w, 'Audience', 'seg-1')
		await enter(w, 'Wait', 'P1D')
		await enter(w, 'Field', 'status')
		await enter(w, 'Value', 'new')
		await enter(w, 'Task subject', 'Call them')
		await enter(w, 'Assign to', 'piet')
		expect(w.findAll('[data-label="Schedule"]')).toHaveLength(0)
		expect(w.findAll('[data-label="Template"]')).toHaveLength(0)

		const trigger = w.vm.triggerOptions.find((o) => o.value === 'shillinqSignal')
		await enter(w, 'Trigger', trigger)
		await enter(w, 'Schedule', '0 9 * * 1')
		const mailing = w.findAllComponents({ name: 'NcSelect' }).find((c) => c.props('inputLabel') === 'What happens')
			.props('options').find((o) => o.value === 'sendMailing')
		await enter(w, 'What happens', mailing)
		await enter(w, 'Template', 'tpl-1')
		await enter(w, 'Mailing list', 'list-1')
		await enter(w, 'Status', w.vm.status)
		await enter(w, 'Operator', w.vm.conditionOperator)
		await enter(w, 'Send intent', w.vm.intent)

		expect(w.vm.form).toMatchObject({ name: 'Welcome', description: 'New contacts', audienceSegment: 'seg-1', waitFor: 'P1D' })
		expect(w.vm.condition).toMatchObject({ field: 'status', value: 'new' })
		expect(w.vm.action).toMatchObject({ taskSubject: 'Call them', taskAssignee: 'piet', templateId: 'tpl-1', listId: 'list-1' })
		expect(w.vm.cron).toBe('0 9 * * 1')
		expect(w.findAll('[data-label="Task subject"]')).toHaveLength(0)

		w.vm.error = 'Could not save the journey.'
		await flushPromises()
		expect(w.text()).toContain('Could not save the journey.')
	})
})
