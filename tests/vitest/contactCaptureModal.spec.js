// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Paste or scan: pasted text becomes proposed fields, a line no rule placed
 * can be given a field, the photo option is offered only when the server can
 * read photos (and explained when it cannot), and Use these fields opens the
 * page's create dialog prefilled without saving anything.
 *
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	captureStatus: vi.fn(),
	parseText: vi.fn(),
	parsePhoto: vi.fn(),
}))
vi.mock('../../src/services/contactCaptureApi.js', () => api)

/**
 * A stand-in that keeps its props and renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'disabled', 'helperText', 'variant', 'type', 'name'],
		emits: ['update:modelValue', 'click', 'closing'],
		render() {
			return h('div', { 'data-stub': name, 'data-testid': this.$attrs['data-testid'] }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))

/**
 * Translate as the identity, filling `{name}` placeholders.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @param {object} [vars] The placeholder values.
 * @return {string} The text.
 */
function t(app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
}
globalThis.t = t

const { default: ContactCaptureModal } = await import('../../src/modals/ContactCaptureModal.vue')
const { createValues, captureForm } = await import('../../src/utils/contactCapture.js')
const { prefilledClientFields } = await import('../../src/utils/clientFormFields.js')

const SIGNATURE = 'Anna de Vries\nAccountmanager\nanna@voorbeeld.nl\nM 06 12345678'
const PROPOSAL = {
	fields: { email: 'anna@voorbeeld.nl', phone: '+31612345678' },
	emails: ['anna@voorbeeld.nl'],
	phones: ['+31612345678'],
	unassigned: ['Anna de Vries', 'Accountmanager'],
	labelledBy: 'rules',
}

/**
 * Mount the dialog with a status answer and an app-root opener.
 *
 * @param {object} [props] The props.
 * @param {boolean} [photo] Whether photos can be read.
 * @return {Promise<{wrapper: object, open: Function}>} The wrapper and the opener spy.
 */
async function mountModal(props = {}, photo = false) {
	api.captureStatus.mockResolvedValue({ photo, maxPhotoBytes: 1000 })
	const open = vi.fn()
	const wrapper = mount(ContactCaptureModal, {
		props,
		global: { mocks: { t }, provide: { cnOpenModal: open } },
	})
	await flushPromises()
	return { wrapper, open }
}

/**
 * Paste the signature and read it.
 *
 * @param {object} wrapper The dialog.
 * @return {Promise<void>}
 */
async function pasteAndRead(wrapper) {
	await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', SIGNATURE)
	await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-testid') === 'contact-capture-read').vm.$emit('click')
	await flushPromises()
}

/**
 * The field input for a key.
 *
 * @param {object} wrapper The dialog.
 * @param {string} key The field key.
 * @return {object} The NcTextField stub.
 */
function field(wrapper, key) {
	return wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.attributes('data-testid') === `contact-capture-field-${key}`)
}

describe('Paste or scan', () => {
	beforeEach(() => {
		api.captureStatus.mockReset()
		api.parseText.mockReset()
		api.parsePhoto.mockReset()
	})

	it('proposes fields for pasted text and lists the lines it could not place', async () => {
		api.parseText.mockResolvedValue(PROPOSAL)
		const { wrapper } = await mountModal()

		await pasteAndRead(wrapper)

		expect(api.parseText).toHaveBeenCalledTimes(1)
		expect(api.parseText).toHaveBeenCalledWith(SIGNATURE)
		expect(field(wrapper, 'email').props('modelValue')).toBe('anna@voorbeeld.nl')
		expect(field(wrapper, 'phone').props('modelValue')).toBe('+31612345678')
		const lines = wrapper.findAll('[data-testid="contact-capture-line"]')
		expect(lines.map((line) => line.find('.contact-capture__line-text').text())).toEqual(['Anna de Vries', 'Accountmanager'])
		const pickers = wrapper.findAllComponents({ name: 'NcSelect' })
		expect(pickers[1].props('inputLabel')).toBe('Field for Accountmanager')
	})

	it('maps an unassigned line to job title, and Use these fields opens the contact create dialog prefilled', async () => {
		api.parseText.mockResolvedValue(PROPOSAL)
		const { wrapper, open } = await mountModal({ schema: 'contact', createModal: 'ContactCreateDialog' })
		await pasteAndRead(wrapper)

		const pickers = wrapper.findAllComponents({ name: 'NcSelect' })
		await pickers[0].vm.$emit('update:modelValue', 'name')
		await pickers[1].vm.$emit('update:modelValue', 'jobTitle')

		expect(field(wrapper, 'jobTitle').props('modelValue')).toBe('Accountmanager')
		expect(field(wrapper, 'name').props('modelValue')).toBe('Anna de Vries')

		await wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-testid') === 'contact-capture-use').vm.$emit('click')

		expect(open).toHaveBeenCalledWith('ContactCreateDialog', {
			values: { name: 'Anna de Vries', role: 'Accountmanager', email: 'anna@voorbeeld.nl', phone: '+31612345678' },
		})
		// Opening the create dialog replaces this one; a close would shut the new one.
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('takes a line out of a field again when its picker is cleared', async () => {
		api.parseText.mockResolvedValue(PROPOSAL)
		const { wrapper } = await mountModal()
		await pasteAndRead(wrapper)

		const picker = wrapper.findAllComponents({ name: 'NcSelect' })[1]
		await picker.vm.$emit('update:modelValue', 'jobTitle')
		await picker.vm.$emit('update:modelValue', 'organisation')
		expect(field(wrapper, 'jobTitle').props('modelValue')).toBe('')
		expect(field(wrapper, 'organisation').props('modelValue')).toBe('Accountmanager')

		await picker.vm.$emit('update:modelValue', null)
		expect(field(wrapper, 'organisation').props('modelValue')).toBe('')
	})

	it('hides the photo input and says why when photos cannot be read', async () => {
		const { wrapper } = await mountModal({}, false)

		expect(wrapper.find('[data-testid="contact-capture-photo"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="contact-capture-no-photo"]').text()).toContain('Photos cannot be read on this server')
		expect(wrapper.findComponent({ name: 'NcTextArea' }).exists()).toBe(true)
	})

	it('offers a labelled camera input when photos can be read, and reads a chosen photo', async () => {
		api.parsePhoto.mockResolvedValue({ ...PROPOSAL, text: 'Recognised text' })
		const { wrapper } = await mountModal({}, true)

		const input = wrapper.find('[data-testid="contact-capture-photo"]')
		expect(input.attributes('capture')).toBe('environment')
		expect(input.attributes('accept')).toBe('image/*')
		expect(wrapper.find(`label[for="${input.attributes('id')}"]`).text()).toBe('Photo of a business card')

		const photo = new File(['x'], 'card.jpg', { type: 'image/jpeg' })
		await wrapper.vm.onPhoto({ target: { files: [photo], value: 'card.jpg' } })
		await flushPromises()

		expect(api.parsePhoto).toHaveBeenCalledWith(photo)
		expect(wrapper.findComponent({ name: 'NcTextArea' }).props('modelValue')).toBe('Recognised text')
		expect(field(wrapper, 'email').props('modelValue')).toBe('anna@voorbeeld.nl')
	})

	it('refuses too large a photo before sending it, and explains a server refusal', async () => {
		const { wrapper } = await mountModal({}, true)

		await wrapper.vm.onPhoto({ target: { files: [{ size: 5000 }], value: 'x' } })
		expect(api.parsePhoto).not.toHaveBeenCalled()
		expect(wrapper.find('[data-testid="contact-capture-error"]').text()).toContain('The photo is too large')

		api.parsePhoto.mockRejectedValue({ response: { status: 409 } })
		await wrapper.vm.onPhoto({ target: { files: [{ size: 10 }], value: 'x' } })
		await flushPromises()
		expect(wrapper.find('[data-testid="contact-capture-error"]').text()).toBe('Photos cannot be read on this server. Paste the text instead.')
		expect(wrapper.find('[data-testid="contact-capture-photo"]').exists()).toBe(false)
	})

	it('emits the values when there is no app-root opener', async () => {
		api.parseText.mockResolvedValue(PROPOSAL)
		api.captureStatus.mockResolvedValue({ photo: false, maxPhotoBytes: 0 })
		const wrapper = mount(ContactCaptureModal, { props: { schema: 'client' }, global: { mocks: { t } } })
		await flushPromises()
		await pasteAndRead(wrapper)

		wrapper.vm.useFields()

		expect(wrapper.emitted('useFields')).toEqual([[{ type: 'person', email: 'anna@voorbeeld.nl', phone: '+31612345678' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

describe('the create-form values of a proposal', () => {
	it('makes a client of the organisation and keeps the person in the notes', () => {
		const form = captureForm({
			name: 'Anna de Vries',
			organisation: 'Voorbeeld B.V.',
			jobTitle: 'Accountmanager',
			email: 'anna@voorbeeld.nl',
			website: 'https://voorbeeld.nl',
			street: 'Stationsplein 1',
			postalCode: '1012 AB',
			city: 'Amsterdam',
			kvkNumber: '12345678',
		})

		expect(createValues('client', form)).toEqual({
			type: 'organization',
			name: 'Voorbeeld B.V.',
			email: 'anna@voorbeeld.nl',
			website: 'https://voorbeeld.nl',
			address: 'Stationsplein 1, 1012 AB Amsterdam',
			notes: 'Contact person: Anna de Vries\nJob title: Accountmanager\nKvK number: 12345678',
		})
	})

	it('fills a contact with name, role, email and phone only', () => {
		const form = captureForm({ name: 'Anna', organisation: 'Voorbeeld', jobTitle: 'Sales', phone: '+31612345678', city: 'Utrecht' })

		expect(createValues('contact', form)).toEqual({ name: 'Anna', role: 'Sales', phone: '+31612345678' })
	})

	it('prefills only the client form fields, and only with text', () => {
		expect(prefilledClientFields({ name: 'Voorbeeld', type: 'organization', notes: ' ', role: 'x', industry: ['ICT'] }))
			.toEqual({ name: 'Voorbeeld', type: 'organization' })
		expect(prefilledClientFields(null)).toEqual({})
	})
})
