// @vitest-environment jsdom

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The small confirm and picker dialogs, mounted: the title and message each
 * renders, which event each button emits (and with what), the closing
 * event, and the picker state (current option, changed assignee).
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
			props: { name: { type: String, default: '' } },
			emits: ['closing'],
			setup(props, { slots }) {
				return () => h('div', { class: 'dialog', 'data-name': props.name }, [
					h('div', { class: 'body' }, slots.default ? slots.default() : []),
					h('div', { class: 'actions' }, slots.actions ? slots.actions() : []),
				])
			},
		}),
	}
})
vi.mock('@nextcloud/vue', async () => {
	const { defineComponent, h } = await import('vue')
	return {
		NcButton: defineComponent({
			name: 'NcButton',
			props: { variant: { type: String, default: 'secondary' }, disabled: { type: Boolean, default: false } },
			setup(props, { slots }) {
				return () => h('button', { disabled: props.disabled, 'data-variant': props.variant }, slots.default ? slots.default() : [])
			},
		}),
		NcSelect: defineComponent({
			name: 'NcSelect',
			props: { modelValue: { type: [String, Object], default: null }, options: { type: Array, default: () => [] }, inputLabel: { type: String, default: '' } },
			emits: ['update:modelValue'],
			setup: () => () => h('div', { class: 'select' }),
		}),
	}
})

const dialogs = {}

beforeAll(async () => {
	globalThis.t = (app, text, vars) => String(text).replace(/\{(\w+)\}/g, (match, key) => (vars && key in vars ? String(vars[key]) : match))
	config.global.mocks.t = globalThis.t
	for (const name of ['ConfirmDialog', 'DeletePipelineDialog', 'RemoveRelationshipDialog', 'DeleteServiceDialog', 'DeleteResourceDialog', 'StagePickerDialog', 'PriorityPickerDialog', 'AssigneePickerDialog']) {
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

/**
 * Close the dialog through its own closing event.
 *
 * @param {object} wrapper The wrapper.
 * @return {void}
 */
function closeDialog(wrapper) {
	wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
}

describe('ConfirmDialog', () => {
	it('shows the message and confirms with the default label and variant', async () => {
		const wrapper = mount(dialogs.ConfirmDialog, { props: { name: 'Archive', message: 'Archive this lead?' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Archive')
		expect(wrapper.find('.confirm-dialog__message').text()).toBe('Archive this lead?')
		const b = buttons(wrapper)
		expect([...b.keys()]).toEqual(['Cancel', 'Confirm'])
		expect(b.get('Confirm').attributes('data-variant')).toBe('error')
		await b.get('Confirm').trigger('click')
		await b.get('Cancel').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})

	it('uses the given confirm label and variant', () => {
		const wrapper = mount(dialogs.ConfirmDialog, { props: { name: 'N', message: 'M', confirmLabel: 'Yes, archive', variant: 'primary' } })
		expect(buttons(wrapper).get('Yes, archive').attributes('data-variant')).toBe('primary')
	})
})

describe('DeletePipelineDialog', () => {
	it('names the pipeline and warns about its items and stages', async () => {
		const wrapper = mount(dialogs.DeletePipelineDialog, { props: { pipeline: { title: 'Sales', stages: [{}, {}, {}] }, affectedCount: 4 } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Delete pipeline')
		expect(wrapper.find('.body p').text()).toBe('Are you sure you want to delete "Sales"?')
		expect(wrapper.findAll('.delete-warning').map((p) => p.text())).toEqual([
			'4 leads/requests are on this pipeline. They will be removed from the pipeline but not deleted.',
			'This pipeline has 3 stages. All stage configuration will be lost.',
		])
		await buttons(wrapper).get('Delete').trigger('click')
		await buttons(wrapper).get('Cancel').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})

	it('shows no warnings for an empty pipeline', () => {
		const wrapper = mount(dialogs.DeletePipelineDialog, { props: { pipeline: { title: 'Empty' } } })
		expect(wrapper.findAll('.delete-warning')).toHaveLength(0)
		expect(mount(dialogs.DeletePipelineDialog, { props: { pipeline: { title: 'E', stages: [] } } }).findAll('.delete-warning')).toHaveLength(0)
	})
})

describe('RemoveRelationshipDialog', () => {
	it('names both sides and confirms the removal', async () => {
		const wrapper = mount(dialogs.RemoveRelationshipDialog, { props: { fromName: 'Ann', toName: 'Acme' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Remove relationship')
		expect(wrapper.find('.body p').text()).toBe('Remove the relationship between Ann and Acme?')
		await buttons(wrapper).get('Remove').trigger('click')
		await buttons(wrapper).get('Cancel').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe.each([
	['DeleteServiceDialog', 'Delete service', 'Are you sure you want to delete "Intake"? Future bookings using this service will be left orphaned.'],
	['DeleteResourceDialog', 'Delete resource', 'Are you sure you want to delete "Intake"?'],
])('%s', (name, title, message) => {
	it('names the record and confirms or cancels', async () => {
		const wrapper = mount(dialogs[name], { props: { name: 'Intake' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe(title)
		expect(wrapper.find('.body p').text()).toBe(message)
		const b = buttons(wrapper)
		expect(b.get('Delete').attributes('data-variant')).toBe('error')
		await b.get('Delete').trigger('click')
		await b.get('Cancel').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('confirm')).toHaveLength(1)
		expect(wrapper.emitted('cancel')).toHaveLength(2)
	})
})

describe('StagePickerDialog', () => {
	it('lists the stages and selects the clicked one', async () => {
		const stages = [{ name: 'New' }, { name: 'Won' }]
		const wrapper = mount(dialogs.StagePickerDialog, { props: { stages } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Move to stage')
		expect([...buttons(wrapper).keys()]).toEqual(['New', 'Won'])
		await buttons(wrapper).get('Won').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('select')).toEqual([[stages[1]]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('PriorityPickerDialog', () => {
	it('marks the current priority and selects a value', async () => {
		const options = [{ value: 'low', label: 'Low' }, { value: 'high', label: 'High' }]
		const wrapper = mount(dialogs.PriorityPickerDialog, { props: { options, current: 'high' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Set priority')
		const b = buttons(wrapper)
		expect(b.get('Low').attributes('data-variant')).toBe('secondary')
		expect(b.get('High').attributes('data-variant')).toBe('primary')
		await b.get('Low').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('select')).toEqual([['low']])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('AssigneePickerDialog', () => {
	it('enables Assign only after the pick changes, and emits the pick', async () => {
		const wrapper = mount(dialogs.AssigneePickerDialog, { props: { options: ['ann', 'bob'], assignee: 'ann' } })
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Assign user')
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toBe('ann')
		expect(select.props('inputLabel')).toBe('Assignee')
		expect(buttons(wrapper).get('Assign').attributes('disabled')).toBeDefined()
		select.vm.$emit('update:modelValue', 'bob')
		await wrapper.vm.$nextTick()
		expect(buttons(wrapper).get('Assign').attributes('disabled')).toBeUndefined()
		await buttons(wrapper).get('Assign').trigger('click')
		await buttons(wrapper).get('Cancel').trigger('click')
		closeDialog(wrapper)
		expect(wrapper.emitted('select')).toEqual([['bob']])
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('treats clearing an unassigned item as no change', async () => {
		const wrapper = mount(dialogs.AssigneePickerDialog, { props: { options: ['ann'] } })
		wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', '')
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.changed).toBe(false)
		expect(buttons(wrapper).get('Assign').attributes('disabled')).toBeDefined()
	})
})
