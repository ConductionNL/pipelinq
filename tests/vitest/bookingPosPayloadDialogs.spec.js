// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The cancel-booking, webhook-payload and POS staff/role dialogs, mounted:
 * the booking summary and deposit note, the reason the confirm button
 * emits, the pretty-printed payload, and the create/edit titles and form id
 * of the POS dialogs.
 *
 * @spec openspec/specs/fe-dialogs/spec.md
 */
import { config, mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		CnDialog: defineComponent({
			name: 'CnDialog',
			props: { name: { type: String, default: '' }, eyebrow: { type: String, default: '' }, subtitle: { type: String, default: '' } },
			emits: ['closing'],
			setup(props, { slots }) {
				return () => h('div', { class: 'dialog', 'data-name': props.name }, [
					h('div', { class: 'body' }, slots.default ? slots.default() : []),
					h('div', { class: 'actions' }, slots.actions ? slots.actions() : []),
				])
			},
		}),
		CnNoteCard: defineComponent({
			name: 'CnNoteCard',
			props: { type: { type: String, default: '' }, text: { type: String, default: '' } },
			setup: (props) => () => h('div', { class: 'note', 'data-type': props.type }, props.text),
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { variant: { type: String, default: 'secondary' } },
			setup(props, { slots }) {
				return () => h('button', { 'data-variant': props.variant }, slots.default ? slots.default() : [])
			},
		}),
	}
})

/**
 * A form stub that records its id and can report done.
 *
 * @param {string} name The component name.
 * @return {Promise<object>} The module.
 */
async function form(name) {
	const { defineComponent, h } = await import('vue')
	return {
		default: defineComponent({
			name,
			props: { id: { type: String, default: '' } },
			emits: ['done'],
			setup: (props) => () => h('form', { 'data-id': props.id }),
		}),
	}
}

vi.mock('../../src/views/pos/PosStaffForm.vue', () => form('PosStaffForm'))
vi.mock('../../src/views/pos/PosRoleForm.vue', () => form('PosRoleForm'))

const dialogs = {}

beforeAll(async () => {
	globalThis.t = (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? String(vars[key]) : match))
	config.global.mocks.t = globalThis.t
	for (const name of ['CancelBookingDialog', 'CtiPayloadDialog', 'PosStaffFormDialog', 'PosRoleFormDialog']) {
		dialogs[name] = (await import(`../../src/dialogs/${name}.vue`)).default
	}
}, 60000)

/**
 * The buttons of a wrapper, keyed by their text.
 *
 * @param {object} wrapper The wrapper.
 * @return {Map<string, object>} The buttons.
 */
function buttons(wrapper) {
	return new Map(wrapper.findAll('button').map((b) => [b.text().trim(), b]))
}

describe('CancelBookingDialog', () => {
	const booking = { subject: 'Intake', startAt: '2026-03-02T09:00:00', endAt: '2026-03-02T09:30:00' }

	it('summarises the booking and emits the typed reason on confirm', async () => {
		const wrapper = mount(dialogs.CancelBookingDialog, { props: { booking } })
		const dialog = wrapper.findComponent({ name: 'CnDialog' })
		expect(dialog.props('name')).toBe('Cancel booking')
		expect(dialog.props('eyebrow')).toBe('Booking · Intake')
		expect(dialog.props('subtitle')).toMatch(/^Intake, .+/)
		await wrapper.find('textarea').setValue('Customer is ill')
		await buttons(wrapper).get('Cancel booking').trigger('click')
		await buttons(wrapper).get('Back').trigger('click')
		dialog.vm.$emit('closing')
		expect(wrapper.emitted('confirm')).toEqual([['Customer is ill']])
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})

	it('only states the charge rule without a paid deposit', () => {
		const rule = 'Staff cancellations always skip the cancellation charge.'
		for (const extra of [{}, { depositAmount: 25 }, { depositPaidAt: '2026-01-01', depositAmount: 0 }, { depositPaidAt: '2026-01-01', depositAmount: 'x' }]) {
			const wrapper = mount(dialogs.CancelBookingDialog, { props: { booking: { ...booking, ...extra } } })
			expect(wrapper.find('.note').text()).toBe(rule)
			expect(wrapper.find('.note').attributes('data-type')).toBe('warning')
		}
	})

	it('adds the refunded deposit when one was paid', () => {
		const wrapper = mount(dialogs.CancelBookingDialog, { props: { booking: { ...booking, depositPaidAt: '2026-01-01', depositAmount: 25, currency: 'EUR' } } })
		expect(wrapper.find('.note').text()).toMatch(/^Staff cancellations always skip the cancellation charge\. The deposit of .*25.* is refunded\.$/)
	})

	it('falls back to a bare eyebrow and empty subtitle without booking details', () => {
		const dialog = mount(dialogs.CancelBookingDialog).findComponent({ name: 'CnDialog' })
		expect(dialog.props('eyebrow')).toBe('Booking')
		expect(dialog.props('subtitle')).toBe('')
	})
})

describe('CtiPayloadDialog', () => {
	it('pretty-prints the payload and closes', () => {
		const wrapper = mount(dialogs.CtiPayloadDialog, { props: { payload: { call: { from: '+31' } } } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Webhook payload')
		expect(wrapper.find('pre').text()).toBe('{\n  "call": {\n    "from": "+31"\n  }\n}')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('falls back to the plain text of a payload JSON cannot encode', () => {
		const payload = { size: 10n }
		const wrapper = mount(dialogs.CtiPayloadDialog, { props: { payload } })
		expect(wrapper.vm.pretty).toBe('[object Object]')
	})
})

describe.each([
	['PosStaffFormDialog', 'staffId', 'PosStaffForm', 'New POS staff member', 'Edit POS staff member'],
	['PosRoleFormDialog', 'roleId', 'PosRoleForm', 'New POS role', 'Edit POS role'],
])('%s', (name, idProp, formName, createTitle, editTitle) => {
	it('titles a new record and passes no id', () => {
		const wrapper = mount(dialogs[name])
		expect(wrapper.find('.dialog').attributes('data-name')).toBe(createTitle)
		expect(wrapper.find('form').attributes('data-id')).toBe('')
	})

	it('titles an existing record, passes its id and reports done', () => {
		const wrapper = mount(dialogs[name], { props: { [idProp]: 'rec-1' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe(editTitle)
		expect(wrapper.find('form').attributes('data-id')).toBe('rec-1')
		wrapper.findComponent({ name: formName }).vm.$emit('done')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('done')).toHaveLength(2)
	})
})
