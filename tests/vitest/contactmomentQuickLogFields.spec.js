// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ContactmomentQuickLog, mounted, on the form itself: every field writes its
 * ticket field, the request list and its labels, prefilling from a request,
 * the contact select following the client, the subject error once the field
 * was left, Save staying disabled while invalid, and Cancel.
 *
 * @spec openspec/changes/contact-moments-keep-draft/specs/contact-moment-drafts/spec.md#requirement-a-manual-contact-moment-is-kept-as-a-private-draft-req-cmd2-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
	getError: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))

/**
 * A stub that renders its default slot and re-emits clicks.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'disabled', 'placeholder', 'filters'],
		emits: ['update:modelValue', 'click', 'blur'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, disabled: this.disabled || undefined, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDurationField: stub('CnDurationField'),
	CnResourceSelect: stub('CnResourceSelect'),
}))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({ default: stub('ClientCreateDialog') }))
vi.mock('../../src/dialogs/ContactCreateDialog.vue', () => ({ default: stub('ContactCreateDialog') }))

globalThis.t = (app, text, vars) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => (vars && key in vars ? String(vars[key]) : whole))

const { default: ContactmomentQuickLog } = await import('../../src/components/ContactmomentQuickLog.vue')

const REQUESTS = [
	{ id: 'req-1', title: 'Paspoort aanvragen', client: 'c-9' },
	{ id: 'req-2', title: '' },
]

/**
 * Mount the quick log with the request list on the server.
 *
 * @param {object} props The props.
 * @return {Promise<object>} The wrapper.
 */
async function open(props = {}) {
	const wrapper = mount(ContactmomentQuickLog, { props, global: { mocks: { t: globalThis.t } } })
	await flushPromises()
	return wrapper
}

const all = (wrapper, name) => wrapper.findAllComponents({ name })
const saveButton = (wrapper) => all(wrapper, 'NcButton').at(-1)

describe('ContactmomentQuickLog form', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		delete window.OC
		storeMock.fetchCollection.mockResolvedValue(REQUESTS)
	})

	it('asks for request tickets only and labels them by title, or id without one', async () => {
		const wrapper = await open()

		expect(storeMock.fetchCollection).toHaveBeenCalledWith('ticket', { ticketType: 'request', _limit: 100 })
		expect(all(wrapper, 'NcSelect').at(2).props('options')).toEqual([
			{ value: 'req-1', label: 'Paspoort aanvragen' },
			{ value: 'req-2', label: 'req-2' },
		])
	})

	it('treats a missing request list as empty', async () => {
		storeMock.fetchCollection.mockResolvedValue(null)
		const wrapper = await open()

		expect(all(wrapper, 'NcSelect').at(2).props('options')).toEqual([])
	})

	it('shows its heading on its own and drops it inline', async () => {
		const standalone = await open()
		expect(standalone.find('h3').text()).toBe('Log contactmoment')
		expect(standalone.classes()).not.toContain('cn-board-form')

		const inline = await open({ inline: true })
		expect(inline.find('h3').exists()).toBe(false)
		expect(inline.classes()).toContain('cn-board-form')
	})

	it('writes every field into the ticket form', async () => {
		const wrapper = await open()
		const selects = all(wrapper, 'NcSelect')
		const areas = all(wrapper, 'NcTextArea')
		const resources = all(wrapper, 'CnResourceSelect')

		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Vraag over afval')
		await selects.at(0).vm.$emit('update:modelValue', 'telefoon')
		await selects.at(1).vm.$emit('update:modelValue', 'handled')
		await selects.at(2).vm.$emit('update:modelValue', 'req-2')
		await resources.at(1).vm.$emit('update:modelValue', 'p-1')
		await areas.at(0).vm.$emit('update:modelValue', 'Samenvatting')
		await areas.at(1).vm.$emit('update:modelValue', 'Notitie')
		await wrapper.findComponent({ name: 'CnDurationField' }).vm.$emit('update:modelValue', 'PT5M')

		expect(wrapper.vm.form).toMatchObject({
			title: 'Vraag over afval',
			channel: 'telefoon',
			outcome: 'handled',
			parentTicket: 'req-2',
			contact: 'p-1',
			description: 'Samenvatting',
			notes: 'Notitie',
			duration: 'PT5M',
		})

		await resources.at(1).vm.$emit('update:modelValue', '')
		await wrapper.findComponent({ name: 'CnDurationField' }).vm.$emit('update:modelValue', null)
		expect([wrapper.vm.form.contact, wrapper.vm.form.duration]).toEqual([null, ''])
	})

	it('keeps the contact select disabled until there is a client, and says why', async () => {
		const wrapper = await open()
		const contact = () => all(wrapper, 'CnResourceSelect').at(1)

		expect(contact().props('disabled')).toBe(true)
		expect(contact().props('placeholder')).toBe('Select a client first')

		wrapper.vm.form.client = 'c-1'
		await wrapper.vm.$nextTick()
		expect(contact().props('disabled')).toBe(false)
		expect(contact().props('placeholder')).toBe('Select or create a contact')
	})

	it('prefills the request and takes its client when the page has none', async () => {
		const wrapper = await open({ requestId: 'req-1' })

		expect([wrapper.vm.form.parentTicket, wrapper.vm.form.client]).toEqual(['req-1', 'c-9'])
	})

	it('keeps the page client over the client of the request', async () => {
		const wrapper = await open({ requestId: 'req-1', clientId: 'c-1' })

		expect([wrapper.vm.form.parentTicket, wrapper.vm.form.client]).toEqual(['req-1', 'c-1'])
	})

	it('leaves the client empty for a request without one, or one not in the list', async () => {
		expect((await open({ requestId: 'req-2' })).vm.form.client).toBeNull()
		expect((await open({ requestId: 'req-gone' })).vm.form).toMatchObject({ parentTicket: 'req-gone', client: null })
	})

	it('shows the subject error only after the field was left', async () => {
		const wrapper = await open()
		const subject = () => wrapper.findComponent({ name: 'NcTextField' })

		expect(subject().vm.$attrs.helperText).toBeUndefined()
		expect(subject().vm.$attrs.error).toBe(false)

		await subject().vm.$emit('blur')
		expect(subject().vm.$attrs.helperText).toBe('Subject is required')
		expect(subject().vm.$attrs.error).toBe(true)

		await subject().vm.$emit('update:modelValue', '  ')
		expect(subject().vm.$attrs.helperText).toBe('Subject is required')
		await subject().vm.$emit('update:modelValue', 'Vraag')
		expect(subject().vm.$attrs.helperText).toBeUndefined()
	})

	it('keeps Save disabled until both subject and channel are set, and does not save an invalid form', async () => {
		const wrapper = await open()

		expect(saveButton(wrapper).props('disabled')).toBe(true)
		await wrapper.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', 'Vraag')
		expect(saveButton(wrapper).props('disabled')).toBe(true)

		await wrapper.vm.onSave()
		expect(storeMock.saveObject).not.toHaveBeenCalled()
		expect(wrapper.vm.saving).toBe(false)

		await all(wrapper, 'NcSelect').at(0).vm.$emit('update:modelValue', 'email')
		expect(saveButton(wrapper).props('disabled')).toBe(false)
		expect(saveButton(wrapper).text()).toBe('Save')
	})

	it('emits cancel from the Cancel button', async () => {
		const wrapper = await open()

		await all(wrapper, 'NcButton').at(-2).trigger('click')

		expect(wrapper.emitted('cancel')).toHaveLength(1)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
