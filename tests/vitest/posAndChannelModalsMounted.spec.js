// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Five modals mounted for real: the POS tender type form, the add-tender
 * dialog, the contact e-mail / phone and social profile forms, and the
 * dialog that links a website submission to a client. Their methods are
 * covered elsewhere against plain contexts; these tests render the templates
 * and assert what an operator sees and what each button sends: the titles
 * for add and edit, which fields show for which choice, when Save is
 * disabled, the errors that appear, and the events the parent receives.
 *
 * @spec openspec/specs/pos/spec.md
 * @spec openspec/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-a-colleague-links-a-submission-to-a-client-and-a-contact-in-one-step
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
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
}))
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDialog: await standIn('CnDialog', ['name', 'width', 'size']),
	CnPartyPicker: await standIn('CnPartyPicker', ['modelValue', 'inputLabel', 'selectedLabel', 'sources']),
}))

const { default: PosTenderTypeFormDialog } = await import('../../src/modals/PosTenderTypeFormDialog.vue')
const { default: AddTenderDialog } = await import('../../src/modals/AddTenderDialog.vue')
const { default: ContactEmailPhoneModal } = await import('../../src/modals/ContactEmailPhoneModal.vue')
const { default: ContactSocialProfileModal } = await import('../../src/modals/ContactSocialProfileModal.vue')
const { default: LinkSubmissionToClientModal } = await import('../../src/modals/LinkSubmissionToClientModal.vue')

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
 * The text field with the given label.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} label The label.
 * @return {object} The field.
 */
function field(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.props('label') === label)
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

beforeEach(() => {
	vi.clearAllMocks()
})

describe('the POS tender type form', () => {
	it('opens empty for a new type with an editable code and Save disabled', () => {
		const wrapper = mountModal(PosTenderTypeFormDialog, { tenderType: null })

		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('New tender type')
		expect(field(wrapper, 'Code').props('disabled')).toBe(false)
		expect(field(wrapper, 'Code').props('helperText')).toBe('Machine-readable identifier (uppercase letters)')
		expect(button(wrapper, 'Save').props('disabled')).toBe(true)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('locks the code of an existing type and puts its values in the form', () => {
		const wrapper = mountModal(PosTenderTypeFormDialog, {
			tenderType: { '@self': { id: 'tt-1' }, name: 'Pin', code: 'card', glAccount: '1200', isActive: false, requiresPin: true },
		})

		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Edit tender type')
		expect(field(wrapper, 'Code').props('disabled')).toBe(true)
		expect(field(wrapper, 'Code').props('modelValue')).toBe('CARD')
		expect(field(wrapper, 'Code').props('helperText')).toBe('Code is immutable after creation')
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		expect(switches.map((s) => [s.text(), s.props('modelValue')])).toEqual([
			['Requires external reference (card auth, voucher serial)', false],
			['Requires PIN entry on terminal', true],
			['Allows change calculation on overpayment (CASH)', false],
			['Active', false],
		])
		expect(button(wrapper, 'Save').props('disabled')).toBe(false)
	})

	it('puts an existing type with PUT, upper-casing the code, and tells the parent', async () => {
		http.put.mockResolvedValueOnce({})
		const wrapper = mountModal(PosTenderTypeFormDialog, {
			tenderType: { id: 'tt-1', name: 'Pin', code: 'card', glAccount: '1200' },
		})
		wrapper.vm.form.code = 'card'
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(http.put).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/pos/tender-types/tt-1',
			expect.objectContaining({ code: 'CARD', name: 'Pin', glAccount: '1200' }),
		)
		expect(toasts.showSuccess).toHaveBeenCalledWith('Tender type updated')
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('shows the server error under the form when saving fails', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { error: 'Code CASH exists' } } })
		const wrapper = mountModal(PosTenderTypeFormDialog, { tenderType: null })
		Object.assign(wrapper.vm.form, { name: 'Cash', code: 'cash', glAccount: '1100' })
		await wrapper.vm.$nextTick()
		await button(wrapper, 'Save').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/pos/tender-types',
			expect.objectContaining({ code: 'CASH' }),
		)
		expect(wrapper.find('[role="alert"]').text()).toBe('Code CASH exists')
		expect(toasts.showError).toHaveBeenCalledWith('Code CASH exists')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(button(wrapper, 'Save').props('disabled')).toBe(false)
	})

	it('closes from Cancel and from the dialog', async () => {
		const wrapper = mountModal(PosTenderTypeFormDialog, { tenderType: null })
		await button(wrapper, 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('the add-tender dialog', () => {
	const TYPES = [
		{ id: 'cash', name: 'Contant', code: 'CASH', allowsChange: true },
		{ id: 'voucher', name: 'Cadeaubon', code: 'VOUCHER', requiresReference: true },
	]

	it('starts at the remaining amount and shows the totals', async () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 50, remaining: 20, tenderTypes: TYPES,
		})
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.amount).toBe(20)
		expect(field(wrapper, 'Amount (EUR)').props('placeholder')).toBe('EUR 20.00')
		expect(wrapper.find('.add-tender__totals').text()).toContain('Transaction total: EUR 50.00')
		expect(wrapper.find('.add-tender__totals').text()).toContain('Remaining: EUR 20.00')
		expect(button(wrapper, 'Use remaining: EUR 20.00')).toBeUndefined()
		expect(button(wrapper, 'Add tender').props('disabled')).toBe(true)
	})

	it('offers the remaining amount again once the amount was changed', async () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 50, remaining: 20, tenderTypes: TYPES,
		})
		wrapper.vm.amount = 5
		await wrapper.vm.$nextTick()

		await button(wrapper, 'Use remaining: EUR 20.00').trigger('click')
		expect(wrapper.vm.amount).toBe(20)
	})

	it('shows no remaining line and a zero placeholder when nothing remains', () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 50, remaining: 0, tenderTypes: TYPES,
		})
		expect(wrapper.vm.amount).toBe(0)
		expect(field(wrapper, 'Amount (EUR)').props('placeholder')).toBe('0.00')
		expect(wrapper.find('.add-tender__totals').text()).not.toContain('Remaining:')
	})

	it('shows the change due when a cash tender exceeds the total', async () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 42.5, remaining: 42.5, tenderTypes: TYPES,
		})
		wrapper.vm.selected = 'cash'
		wrapper.vm.amount = 50
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.add-tender__change').text()).toBe('Change due: EUR 7.50')
		expect(field(wrapper, 'Reference')).toBeUndefined()
		expect(button(wrapper, 'Add tender').props('disabled')).toBe(false)
	})

	it('shows no change due when a cash tender does not exceed the total', async () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 42.5, remaining: 42.5, tenderTypes: TYPES,
		})
		wrapper.vm.selected = 'cash'
		wrapper.vm.amount = 42.5
		await wrapper.vm.$nextTick()

		expect(wrapper.find('.add-tender__change').exists()).toBe(false)
		expect(button(wrapper, 'Add tender').props('disabled')).toBe(false)
	})

	it('asks for a reference for a voucher before it can be added', async () => {
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 30, remaining: 30, tenderTypes: TYPES,
		})
		wrapper.vm.selected = 'voucher'
		await wrapper.vm.$nextTick()

		expect(field(wrapper, 'Reference')).toBeDefined()
		expect(wrapper.find('.add-tender__change').exists()).toBe(false)
		expect(button(wrapper, 'Add tender').props('disabled')).toBe(true)

		wrapper.vm.reference = '  VB-123  '
		await wrapper.vm.$nextTick()
		http.post.mockResolvedValueOnce({})
		await button(wrapper, 'Add tender').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos-transactions/tx-1/tenders', {
			tenderType: 'voucher',
			amount: 30,
			reference: 'VB-123',
		})
		expect(wrapper.emitted('added')).toHaveLength(1)
	})

	it('shows why a tender could not be added', async () => {
		http.post.mockRejectedValueOnce(new Error('offline'))
		const wrapper = mountModal(AddTenderDialog, {
			transactionId: 'tx-1', transactionTotal: 30, remaining: 30, tenderTypes: TYPES,
		})
		wrapper.vm.selected = 'cash'
		await wrapper.vm.$nextTick()
		await button(wrapper, 'Add tender').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Failed to add tender')
		expect(wrapper.emitted('added')).toBeUndefined()
	})
})

describe('the contact e-mail and phone form', () => {
	it('titles and labels itself for adding an e-mail address', () => {
		const wrapper = mountModal(ContactEmailPhoneModal, { channelType: 'email' })
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Add email address')
		expect(field(wrapper, 'Email address').props('type')).toBe('email')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('work')
	})

	it('titles and labels itself for editing a phone number, with its values', () => {
		const wrapper = mountModal(ContactEmailPhoneModal, {
			channelType: 'phone',
			entry: { kind: 'mobile', value: '+31 6 1234 5678', primary: true, verified: false },
		})
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Edit phone number')
		expect(field(wrapper, 'Phone number').props('type')).toBe('text')
		expect(field(wrapper, 'Phone number').props('modelValue')).toBe('+31 6 1234 5678')
		expect(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((s) => s.props('modelValue'))).toEqual([true, false])
	})

	it('shows the format error on the field and clears it when the value is typed again', async () => {
		const wrapper = mountModal(ContactEmailPhoneModal, { channelType: 'email' })
		field(wrapper, 'Email address').vm.$emit('update:modelValue', 'not-an-address')
		await wrapper.find('[data-testid="channel-modal-save"]').trigger('click')

		expect(field(wrapper, 'Email address').props('error')).toBe(true)
		expect(field(wrapper, 'Email address').props('helperText')).toBe('Invalid email format')
		expect(wrapper.emitted('save')).toBeUndefined()

		field(wrapper, 'Email address').vm.$emit('update:modelValue', 'jan@example.nl')
		await wrapper.vm.$nextTick()
		expect(field(wrapper, 'Email address').props('error')).toBe(false)

		await wrapper.find('[data-testid="channel-modal-save"]').trigger('click')
		expect(wrapper.emitted('save')).toEqual([[{ kind: 'work', value: 'jan@example.nl', primary: false, verified: false }]])
	})

	it('closes from Cancel and from the dialog', async () => {
		const wrapper = mountModal(ContactEmailPhoneModal, { channelType: 'phone' })
		await button(wrapper, 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('the social profile form', () => {
	it('titles itself for adding and starts on LinkedIn', () => {
		const wrapper = mountModal(ContactSocialProfileModal, {})
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Add social profile')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('modelValue')).toBe('linkedin')
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toContain('mastodon')
	})

	it('titles itself for editing and shows the profile', () => {
		const wrapper = mountModal(ContactSocialProfileModal, {
			profile: { network: 'mastodon', handle: 'jan', url: 'https://social.example/@jan', followsUs: true },
		})
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe('Edit social profile')
		expect(field(wrapper, 'Handle').props('modelValue')).toBe('jan')
		expect(field(wrapper, 'Profile URL').props('modelValue')).toBe('https://social.example/@jan')
		expect(wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((s) => [s.text(), s.props('modelValue')])).toEqual([
			['Verified', false],
			['Followed by us', false],
			['Follows us', true],
		])
	})

	it('shows the URL error on the field and clears it when the URL is typed again', async () => {
		const wrapper = mountModal(ContactSocialProfileModal, {})
		field(wrapper, 'Handle').vm.$emit('update:modelValue', 'jan')
		field(wrapper, 'Profile URL').vm.$emit('update:modelValue', 'social.example/jan')
		await button(wrapper, 'Save').trigger('click')

		expect(field(wrapper, 'Profile URL').props('helperText')).toBe('Invalid URL format')
		expect(field(wrapper, 'Profile URL').props('error')).toBe(true)

		field(wrapper, 'Profile URL').vm.$emit('update:modelValue', '')
		await wrapper.vm.$nextTick()
		expect(field(wrapper, 'Profile URL').props('error')).toBe(false)

		await button(wrapper, 'Save').trigger('click')
		expect(wrapper.emitted('save')[0][0]).toMatchObject({ network: 'linkedin', handle: 'jan', url: '' })
	})

	it('closes from Cancel and from the dialog', async () => {
		const wrapper = mountModal(ContactSocialProfileModal, {})
		await button(wrapper, 'Cancel').trigger('click')
		wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

describe('linking a website submission to a client', () => {
	const TICKET = {
		reporterName: 'Jan Jansen',
		reporterEmail: 'Jan@Example.nl',
		reporterOrganisation: 'Bakkerij Jansen',
		sourceUrl: 'https://example.nl/contact/',
		description: 'Graag een offerte.',
	}

	/**
	 * Answer the ticket read and the two suggestion searches.
	 *
	 * @param {object|null} ticket The ticket, or null when it cannot be read.
	 * @param {object|null} client The client with the organisation's name.
	 * @param {object|null} contact The contact with the e-mail address.
	 * @return {void}
	 */
	function answer(ticket, client = null, contact = null) {
		http.get.mockImplementation((url) => {
			if (url.endsWith('/ticket/t-1')) {
				return ticket ? Promise.resolve({ data: ticket }) : Promise.reject(new Error('404'))
			}
			if (url.endsWith('/client')) {
				return Promise.resolve({ data: { results: client ? [client] : [] } })
			}
			if (url.endsWith('/contact')) {
				return Promise.resolve({ data: { results: contact ? [contact] : [] } })
			}
			return Promise.reject(new Error('unexpected ' + url))
		})
	}

	it('shows the loading icon until the ticket is read', async () => {
		answer(TICKET)
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(true)
		await flushPromises()
		expect(wrapper.findComponent({ name: 'NcLoadingIcon' }).exists()).toBe(false)
	})

	it('says the ticket could not be read and cannot link', async () => {
		answer(null)
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		expect(wrapper.findComponent({ name: 'NcNoteCard' }).text()).toBe('This ticket could not be read.')
		expect(wrapper.find('.link-submission__sent').exists()).toBe(false)
		expect(button(wrapper, 'Link').props('disabled')).toBe(true)
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('shows the submission as sent and plans a new client and contact when none match', async () => {
		answer(TICKET)
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		const facts = wrapper.findAll('.link-submission__fact').map((row) => [row.find('dt').text(), row.find('dd').text(), row.classes('link-submission__fact--wide')])
		expect(facts).toEqual([
			['Name', 'Jan Jansen', false],
			['Email', 'jan@example.nl', false],
			['Organisation', 'Bakkerij Jansen', false],
			['Page', 'example.nl/contact', false],
			['Message', 'Graag een offerte.', true],
		])
		const radios = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).map((r) => r.text())
		expect(radios).toContain('New client "Bakkerij Jansen"')
		expect(radios).toContain('New contact person "Jan Jansen"')
		expect(wrapper.find('.link-submission__hint').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'CnPartyPicker' }).exists()).toBe(false)
		expect(button(wrapper, 'Link').props('disabled')).toBe(false)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/client', { params: { name: 'Bakkerij Jansen', _limit: 1 } })
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/contact', { params: { email: 'jan@example.nl', _limit: 1 } })
	})

	it('suggests the existing client and contact and picks them in the pickers', async () => {
		answer(TICKET, { id: 7, name: 'Bakkerij Jansen BV' }, { '@self': { id: 'c-9' }, name: 'J. Jansen' })
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		const hints = wrapper.findAll('.link-submission__hint').map((hint) => hint.text())
		expect(hints).toEqual([
			'A client with this name already exists: Bakkerij Jansen BV',
			'A contact person with this e-mail address already exists: J. Jansen',
		])
		const pickers = wrapper.findAllComponents({ name: 'CnPartyPicker' })
		expect(pickers.map((p) => [p.props('modelValue'), p.props('selectedLabel'), p.props('inputLabel')])).toEqual([
			['7', 'Bakkerij Jansen BV', 'Search an existing client'],
			['c-9', 'J. Jansen', 'Search an existing contact person'],
		])
		expect(pickers[0].props('sources')[0]).toMatchObject({ schema: 'client', register: 'pipelinq' })
	})

	it('suggests only the client when no contact has the e-mail address', async () => {
		answer(TICKET, { id: 7, name: 'Bakkerij Jansen BV' }, null)
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()

		expect(wrapper.findAll('.link-submission__hint').map((hint) => hint.text())).toEqual([
			'A client with this name already exists: Bakkerij Jansen BV',
		])
		expect(wrapper.vm.contactMode).toBe('new')
		expect(wrapper.findAllComponents({ name: 'CnPartyPicker' })).toHaveLength(1)
	})

	it('cannot link to an existing client until one is picked', async () => {
		answer(TICKET)
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()
		wrapper.vm.clientMode = 'existing'
		await wrapper.vm.$nextTick()

		const picker = wrapper.findComponent({ name: 'CnPartyPicker' })
		expect(picker.props('selectedLabel')).toBe('')
		expect(button(wrapper, 'Link').props('disabled')).toBe(true)
	})

	it('shows why linking failed and leaves the dialog open', async () => {
		answer({ ...TICKET, reporterOrganisation: '' })
		const wrapper = mountModal(LinkSubmissionToClientModal, { ticketId: 't-1' })
		await flushPromises()
		wrapper.vm.clientMode = 'existing'
		await wrapper.vm.link()
		await flushPromises()

		expect(wrapper.findAllComponents({ name: 'NcNoteCard' }).map((n) => n.text())).toEqual(['No existing client chosen.'])
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(button(wrapper, 'Link').exists()).toBe(true)
	})
})
