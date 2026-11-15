// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template wiring of five modals, mounted with stand-ins that emit what
 * the real controls emit: what a typed value or a chosen option does to the
 * form (numbers kept as numbers, the reference field that follows a type
 * which needs one, the pickers that follow "existing"), and that a dialog
 * closes both through its own closing and through Cancel.
 *
 * @spec openspec/specs/pos/spec.md
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.OC = { requestToken: 'tok' }
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m),
}))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn(), showWarning: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))
vi.mock('../../src/services/receiptTemplates.js', () => ({ loadActiveReceiptTemplates: vi.fn(async () => []) }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('../../src/services/forecastApi.js', () => ({ createOverride: vi.fn() }))
vi.mock('../../src/services/posTotals.js', () => ({ formatEur: (value) => `EUR ${Number(value).toFixed(2)}` }))

/**
 * A stand-in component: a div carrying its name as class, its string and
 * boolean props as data attributes, its default and actions slots, and a
 * click that emits `click` unless it is disabled.
 *
 * @param {string} name Component name.
 * @param {string[]} props Props it accepts.
 * @return {Promise<object>} The component.
 */
async function standIn(name, props) {
	const { defineComponent, h } = await import('vue')
	return defineComponent({
		name,
		props,
		emits: ['click', 'closing', 'update:modelValue'],
		render() {
			const attrs = { class: name }
			for (const prop of props) {
				const value = this.$props[prop]
				if (typeof value === 'string' || typeof value === 'boolean') {
					attrs[`data-${prop.toLowerCase()}`] = String(value)
				}
			}
			attrs.onClick = () => {
				if (!this.$props.disabled) {
					this.$emit('click')
				}
			}
			return h('div', attrs, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	})
}

vi.mock('@nextcloud/vue', async () => ({
	NcButton: await standIn('NcButton', ['variant', 'disabled']),
	NcCheckboxRadioSwitch: await standIn('NcCheckboxRadioSwitch', ['modelValue', 'type', 'value', 'name']),
	NcLoadingIcon: await standIn('NcLoadingIcon', []),
	NcNoteCard: await standIn('NcNoteCard', ['type']),
	NcSelect: await standIn('NcSelect', ['modelValue', 'options', 'inputLabel', 'placeholder', 'label', 'reduce', 'inputId', 'clearable', 'trackBy']),
	NcTextField: await standIn('NcTextField', ['modelValue', 'label', 'placeholder', 'disabled', 'helperText', 'error', 'type', 'required']),
	NcTextArea: await standIn('NcTextArea', ['modelValue', 'label', 'placeholder', 'error', 'helperText', 'required', 'rows']),
}))
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDialog: await standIn('CnDialog', ['name', 'width', 'size']),
	CnPartyPicker: await standIn('CnPartyPicker', ['modelValue', 'inputLabel', 'selectedLabel', 'sources']),
	CnTextWidget: await standIn('CnTextWidget', ['modelValue', 'label']),
}))


const { default: PosTenderTypeFormDialog } = await import('../../src/modals/PosTenderTypeFormDialog.vue')
const { default: AddTenderDialog } = await import('../../src/modals/AddTenderDialog.vue')
const { default: LinkSubmissionToClientModal } = await import('../../src/modals/LinkSubmissionToClientModal.vue')
const { default: CommitJustificationModal } = await import('../../src/modals/CommitJustificationModal.vue')
const { default: ForecastOverrideModal } = await import('../../src/modals/ForecastOverrideModal.vue')
const { default: PrintReceiptModal } = await import('../../src/modals/PrintReceiptModal.vue')
const { default: EmailReceiptModal } = await import('../../src/modals/EmailReceiptModal.vue')

/**
 * Mount a modal with the global t() its template calls.
 *
 * @param {object} component The modal.
 * @param {object} props Its props.
 * @return {object} The wrapper.
 */
function mountModal(component, props) {
	return mount(component, { props, global: { mocks: { t: globalThis.t } } })
}

/**
 * The stand-in of the given name whose prop has the given value.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The component name.
 * @param {string} prop The prop.
 * @param {*} value Its value.
 * @return {object} The stand-in.
 */
function standInWith(wrapper, name, prop, value) {
	return wrapper.findAllComponents({ name }).find((c) => c.props(prop) === value)
}

/**
 * The button whose text is the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.text() === label)
}

/**
 * Close the dialog both ways: the dialog's own closing, and its Cancel button.
 *
 * @param {object} wrapper The wrapper.
 * @return {Promise<number>} How many close events the parent received.
 */
async function closeBothWays(wrapper) {
	wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
	await button(wrapper, 'Cancel').trigger('click')
	return (wrapper.emitted('close') || []).length
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('the add-tender dialog, typed into', () => {
	const TYPES = [
		{ id: 'cash', name: 'Contant', code: 'CASH', allowsChange: true },
		{ id: 'voucher', name: 'Cadeaubon', code: 'VOUCHER', requiresReference: true },
	]

	it('takes the chosen type by id, a typed amount as a number and the reference it then asks for', async () => {
		const wrapper = mountModal(AddTenderDialog, { transactionId: 'tx-1', transactionTotal: 50, remaining: 20, tenderTypes: TYPES })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('reduce')(TYPES[1])).toBe('voucher')
		select.vm.$emit('update:modelValue', 'voucher')
		await wrapper.vm.$nextTick()
		standInWith(wrapper, 'NcTextField', 'label', 'Amount (EUR)').vm.$emit('update:modelValue', '12.5')
		await wrapper.vm.$nextTick()
		standInWith(wrapper, 'NcTextField', 'label', 'Reference').vm.$emit('update:modelValue', 'VB-77')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.selected).toBe('voucher')
		expect(wrapper.vm.amount).toBe(12.5)
		expect(wrapper.vm.reference).toBe('VB-77')
	})

	it('offers no types when none are given, and closes both ways', async () => {
		const wrapper = mountModal(AddTenderDialog, { transactionId: 'tx-1' })

		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual([])
		expect(await closeBothWays(wrapper)).toBe(2)
	})
})

describe('the tender type form, typed into', () => {
	it('keeps the description, the three switches and the sort order as typed, the order as a number', async () => {
		const wrapper = mountModal(PosTenderTypeFormDialog, { tenderType: null })

		standInWith(wrapper, 'NcTextField', 'label', 'Description').vm.$emit('update:modelValue', 'Betaling met pin')
		const toggle = (label) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((s) => s.text() === label)
		toggle('Requires external reference (card auth, voucher serial)').vm.$emit('update:modelValue', true)
		toggle('Requires PIN entry on terminal').vm.$emit('update:modelValue', true)
		toggle('Active').vm.$emit('update:modelValue', false)
		standInWith(wrapper, 'NcTextField', 'label', 'Sort order').vm.$emit('update:modelValue', '7')
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.form.description).toBe('Betaling met pin')
		expect([wrapper.vm.form.requiresReference, wrapper.vm.form.requiresPin, wrapper.vm.form.isActive]).toEqual([true, true, false])
		expect(wrapper.vm.form.sortOrder).toBe(7)
	})

	it('closes both ways', async () => {
		expect(await closeBothWays(mountModal(PosTenderTypeFormDialog, { tenderType: null }))).toBe(2)
	})
})

describe('the link-to-a-client dialog, chosen in', () => {
	const TICKET = {
		id: 't-1',
		reporterName: 'Jan Jansen',
		reporterEmail: 'jan@example.nl',
		reporterOrganisation: 'Bakkerij Jansen',
		sourceUrl: 'https://example.nl/contact/',
		description: 'Graag een offerte.',
	}

	beforeEach(() => {
		http.get.mockImplementation((url) => {
			if (url.endsWith('/ticket/t-1')) {
				return Promise.resolve({ data: TICKET })
			}
			return Promise.resolve({ data: { results: [] } })
		})
	})

	it('switches client and contact to existing ones, picks them, and asks for a lead', async () => {
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		const radio = (name, value) => wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((r) => r.props('name') === name && r.props('value') === value)
		radio('link-client', 'existing').vm.$emit('update:modelValue', 'existing')
		radio('link-contact', 'existing').vm.$emit('update:modelValue', 'existing')
		await wrapper.vm.$nextTick()

		expect(button(wrapper, 'Link').props('disabled')).toBe(true)
		const pickers = wrapper.findAllComponents({ name: 'CnPartyPicker' })
		expect(pickers.map((picker) => picker.props('inputLabel'))).toEqual(['Search an existing client', 'Search an existing contact person'])
		pickers[0].vm.$emit('update:modelValue', 'client-9')
		pickers[1].vm.$emit('update:modelValue', 'contact-4')
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((r) => r.text() === 'Create a lead in the pipeline').vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect([wrapper.vm.clientId, wrapper.vm.contactId, wrapper.vm.createLead]).toEqual(['client-9', 'contact-4', true])
		expect(button(wrapper, 'Link').props('disabled')).toBe(false)

		radio('link-client', 'new').vm.$emit('update:modelValue', 'new')
		radio('link-contact', 'new').vm.$emit('update:modelValue', 'new')
		await wrapper.vm.$nextTick()
		expect(wrapper.findAllComponents({ name: 'CnPartyPicker' })).toHaveLength(0)
	})

	it('closes both ways', async () => {
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		expect(await closeBothWays(wrapper)).toBe(2)
	})
})

describe('the justification and forecast override dialogs', () => {
	it('closes when the dialog itself closes', () => {
		for (const [component, props] of [
			[CommitJustificationModal, {}],
			[ForecastOverrideModal, { periodId: 'p-1', ownerId: 'u-1', level: 'user' }],
		]) {
			const wrapper = mountModal(component, props)
			wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
			expect(wrapper.emitted('close')).toHaveLength(1)
		}
	})
})

describe('the receipt dialogs', () => {
	it('print and e-mail close both ways without sending anything', async () => {
		http.get.mockResolvedValue({ data: {} })
		http.post.mockResolvedValue({ data: {} })
		const fetched = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({}), text: async () => '' }))
		vi.stubGlobal('fetch', fetched)
		for (const component of [PrintReceiptModal, EmailReceiptModal]) {
			const wrapper = mount(component, {
				props: { transactionId: 'tx-1', templates: [{ id: 'r-1', name: 'Bon' }] },
				global: { mocks: { t: globalThis.t }, stubs: { ReceiptModalBody: true } },
			})
			await flushPromises()
			http.post.mockClear()

			fetched.mockClear()

			expect(await closeBothWays(wrapper)).toBe(2)
			expect(http.post).not.toHaveBeenCalled()
			expect(fetched).not.toHaveBeenCalled()
		}
		vi.unstubAllGlobals()
	})
})
