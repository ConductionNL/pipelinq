// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The journey form as a marketer fills it in: every field and choice reaches
 * the journey it saves, the schedule shows only for a bookkeeping trigger,
 * the mailing fields replace the task fields, and Cancel or closing the
 * dialog closes it without saving.
 *
 * Only HTTP, the toasts, the event bus and the component libraries are
 * replaced; the stubs render one native element per field and one button per
 * select option so the form can be driven as a user does.
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({ showWarning: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return {
		CnDialog: {
			name: 'CnDialog',
			emits: ['closing'],
			render() {
				return h('div', [
					h('button', { 'data-testid': 'dialog-close', onClick: () => this.$emit('closing') }),
					this.$slots.default?.(),
					this.$slots.actions?.(),
				])
			},
		},
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return {
		NcButton: {
			name: 'NcButton',
			props: ['disabled', 'variant'],
			emits: ['click'],
			render() {
				return h(
					'button',
					{ class: 'nc-button', disabled: this.disabled, onClick: () => this.$emit('click') },
					this.$slots.default?.(),
				)
			},
		},
		NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
		NcNoteCard: {
			name: 'NcNoteCard',
			render() {
				return h('div', { class: 'note' }, this.$slots.default?.())
			},
		},
		// One button per option, inside a group named by the select's label.
		NcSelect: {
			name: 'NcSelect',
			props: ['modelValue', 'options', 'inputLabel'],
			emits: ['update:modelValue'],
			render() {
				return h(
					'div',
					{ 'data-select': this.inputLabel, 'data-value': this.modelValue?.value },
					(this.options || []).map((option) =>
						h('button', { 'data-option': option.value, onClick: () => this.$emit('update:modelValue', option) }),
					),
				)
			},
		},
		NcTextField: {
			name: 'NcTextField',
			props: ['modelValue', 'label'],
			emits: ['update:modelValue'],
			render() {
				return h('input', {
					'data-label': this.label,
					value: this.modelValue,
					onInput: (e) => this.$emit('update:modelValue', e.target.value),
				})
			},
		},
	}
})

const { default: JourneyFormDialog } = await import('../../src/dialogs/JourneyFormDialog.vue')

/**
 * Open the form.
 *
 * @param {object} props Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function openForm(props = {}) {
	const wrapper = mount(JourneyFormDialog, {
		props: { show: true, ...props },
		global: { mocks: { t: globalThis.t } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Type into the field with this label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @param {string} value The text.
 * @return {Promise<void>}
 */
async function type(wrapper, label, value) {
	await wrapper.find(`input[data-label="${label}"]`).setValue(value)
}

/**
 * Pick an option in the select with this label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The select's label.
 * @param {string} value The option's value.
 * @return {Promise<void>}
 */
async function choose(wrapper, label, value) {
	await wrapper.find(`[data-select="${label}"] [data-option="${value}"]`).trigger('click')
}

/**
 * Press Save and return the journey sent to the server.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<object>} The request body.
 */
async function saveAndRead(wrapper) {
	const save = wrapper.findAll('button.nc-button').find((b) => b.text() === 'Save')
	await save.trigger('click')
	await flushPromises()
	expect(axiosMock.post).toHaveBeenCalledTimes(1)
	const [url, body] = axiosMock.post.mock.calls[0]
	expect(url).toBe('/index.php/apps/pipelinq/api/journeys')
	return body
}

beforeEach(() => {
	vi.clearAllMocks()
	axiosMock.post.mockResolvedValue({ data: {} })
})

describe('JourneyFormDialog fields', () => {
	it('sends what the marketer filled in for a task journey', async () => {
		const close = vi.fn()
		const refresh = vi.fn()
		const wrapper = await openForm({ close, refresh })

		await type(wrapper, 'Name', 'Renewal follow-up')
		await type(wrapper, 'Description', 'Customers up for renewal')
		await choose(wrapper, 'Trigger', 'contractRenewalWindow')
		await choose(wrapper, 'Status', 'active')
		await type(wrapper, 'Audience', 'segment-1')
		await type(wrapper, 'Wait', '5 days')
		await type(wrapper, 'Field', 'value')
		await choose(wrapper, 'Operator', 'notEquals')
		await type(wrapper, 'Value', '0')
		await type(wrapper, 'Task subject', 'Call about the renewal')
		await type(wrapper, 'Assign to', 'anna')

		expect(await saveAndRead(wrapper)).toEqual({
			name: 'Renewal follow-up',
			description: 'Customers up for renewal',
			audienceSegment: 'segment-1',
			waitFor: '5 days',
			status: 'active',
			trigger: { kind: 'contractRenewalWindow', cron: '0 7 * * *' },
			condition: { field: 'value', operator: 'notEquals', value: '0' },
			action: {
				kind: 'createTask',
				templateId: '',
				listId: '',
				intent: 'promotional',
				taskSubject: 'Call about the renewal',
				taskType: 'callback',
				taskAssignee: 'anna',
			},
		})
		expect(refresh).toHaveBeenCalledTimes(1)
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('asks for a schedule only for a bookkeeping signal, and sends it', async () => {
		const wrapper = await openForm()
		expect(wrapper.find('input[data-label="Schedule"]').exists()).toBe(false)

		await choose(wrapper, 'Trigger', 'shillinqSignal')
		await type(wrapper, 'Schedule', '0 6 * * 1')
		await type(wrapper, 'Name', 'Dunning watch')

		const body = await saveAndRead(wrapper)
		expect(body.trigger).toEqual({ kind: 'shillinqSignal', cron: '0 6 * * 1' })
	})

	it('swaps the task fields for the mailing fields and sends the mailing', async () => {
		const wrapper = await openForm()
		expect(wrapper.find('input[data-label="Template"]').exists()).toBe(false)

		await choose(wrapper, 'What happens', 'sendMailing')
		expect(wrapper.find('input[data-label="Task subject"]').exists()).toBe(false)
		await type(wrapper, 'Template', 'tpl-7')
		await type(wrapper, 'Mailing list', 'list-3')
		await choose(wrapper, 'Send intent', 'service')
		await type(wrapper, 'Name', 'Welcome')

		const { action } = await saveAndRead(wrapper)
		expect(action).toMatchObject({ kind: 'sendMailing', templateId: 'tpl-7', listId: 'list-3', intent: 'service' })
	})

	it('cannot be saved without a name', async () => {
		const wrapper = await openForm()

		const save = wrapper.findAll('button.nc-button').find((b) => b.text() === 'Save')
		expect(save.attributes('disabled')).toBeDefined()
	})
})

describe('JourneyFormDialog closing', () => {
	it('closes from Cancel and from the dialog without saving', async () => {
		const close = vi.fn()
		const wrapper = await openForm({ close })

		await wrapper.findAll('button.nc-button').find((b) => b.text() === 'Cancel').trigger('click')
		await wrapper.find('[data-testid="dialog-close"]').trigger('click')

		expect(close).toHaveBeenCalledTimes(2)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('shows why a save was refused', async () => {
		axiosMock.post.mockRejectedValue({ response: { data: { error: 'The audience segment does not exist' } } })
		const wrapper = await openForm()
		await type(wrapper, 'Name', 'Welcome')

		await wrapper.findAll('button.nc-button').find((b) => b.text() === 'Save').trigger('click')
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('The audience segment does not exist')
	})
})
