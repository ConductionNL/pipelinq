// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The convert-to-case dialog's own choices (board PqTicketOmzetten): the two
 * options and the case type reach the confirm event as set, Cancel and the
 * dialog close without confirming, and the texts fall back when the ticket
 * has no reference, no requester or no portal name.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		emits: ['closing'],
		render() {
			return h('div', { class: 'cn-dialog', 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnResourceSelect: { name: 'CnResourceSelect', props: ['modelValue', 'register', 'schema'], emits: ['update:modelValue'], render: () => h('select') },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()])
		},
	},
	NcCheckboxRadioSwitch: {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('label', [h('input', { type: 'checkbox', checked: this.modelValue, onChange: () => this.$emit('update:modelValue', !this.modelValue) }), this.$slots.default?.()])
		},
	},
	NcLoadingIcon: { render: () => h('span', { class: 'loading' }) },
}))

const { default: Dialog } = await import('../../src/dialogs/ConvertToCaseDialog.vue')

const source = { register: 'dossiq', schema: 'caseType' }
const mountDialog = (props = {}) => mount(Dialog, { props: { ticket: { reference: 'PQ-2026-0417', title: 'Lantaarnpaal kapot' }, ...props } })
const testid = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`)

describe('the convert-to-case choices', () => {
	it('confirms with both options unticked and the picked case type', async () => {
		const wrapper = mountDialog({ caseTypeSource: source })
		await testid(wrapper, 'convert-to-case-keep-open').find('input').trigger('change')
		await testid(wrapper, 'convert-to-case-inform-customer').find('input').trigger('change')
		wrapper.findComponent({ name: 'CnResourceSelect' }).vm.$emit('update:modelValue', 'type-7')
		await testid(wrapper, 'convert-to-case-confirm').trigger('click')

		expect(wrapper.emitted('confirm')).toEqual([[{ caseType: 'type-7', keepOpen: false, informCustomer: false }]])
	})

	it('reads a cleared case type as none', async () => {
		const wrapper = mountDialog({ caseTypeSource: source })
		const select = wrapper.findComponent({ name: 'CnResourceSelect' })
		select.vm.$emit('update:modelValue', 'type-7')
		select.vm.$emit('update:modelValue', null)
		await testid(wrapper, 'convert-to-case-confirm').trigger('click')

		expect(wrapper.emitted('confirm')[0][0].caseType).toBe('')
	})

	it('asks for no case type without a source for them', () => {
		expect(mountDialog().findComponent({ name: 'CnResourceSelect' }).exists()).toBe(false)
	})

	it('closes from Cancel and from the dialog without confirming', async () => {
		const wrapper = mountDialog()
		await testid(wrapper, 'convert-to-case-cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(2)
		expect(wrapper.emitted('confirm')).toBeUndefined()
	})

	it('shows it is working and disables confirm while busy', () => {
		const wrapper = mountDialog({ busy: true })
		expect(testid(wrapper, 'convert-to-case-confirm').attributes('disabled')).toBeDefined()
		expect(testid(wrapper, 'convert-to-case-confirm').find('.loading').exists()).toBe(true)
	})
})

describe('the convert-to-case texts', () => {
	it('falls back to generic words without a portal, a requester or a reference', () => {
		const wrapper = mount(Dialog)
		expect(testid(wrapper, 'convert-to-case-inform-customer').text()).toBe('Inform the customer in the customer portal')
		expect(wrapper.text()).toContain('The customer sees the case number and who handles the case.')
		expect(wrapper.find('.cn-dialog').attributes('data-eyebrow')).toBe('Convert')
		expect(wrapper.find('.cn-dialog').attributes('data-subtitle')).toBe('')
		expect(wrapper.text()).not.toContain('Requester')
		expect(wrapper.text()).toContain('Create case in dossiq')
	})

	it('names the portal, the requester\'s first name and the reference when known', () => {
		const wrapper = mountDialog({ portalName: 'Mijn Zuiddrecht', requester: '  Sanne de Vries', requesterAddress: 'Lindelaan 14', targetApp: 'zaken' })
		expect(testid(wrapper, 'convert-to-case-inform-customer').text()).toBe('Inform the customer in Mijn Zuiddrecht')
		expect(wrapper.text()).toContain('Sanne sees the case number and who handles the case.')
		expect(wrapper.text()).toContain('Sanne de Vries, Lindelaan 14')
		expect(wrapper.find('.cn-dialog').attributes('data-eyebrow')).toBe('Convert · PQ-2026-0417')
		expect(wrapper.find('.cn-dialog').attributes('data-subtitle')).toBe('PQ-2026-0417 · Lantaarnpaal kapot')
		expect(wrapper.text()).toContain('Create case in zaken')
	})
})
