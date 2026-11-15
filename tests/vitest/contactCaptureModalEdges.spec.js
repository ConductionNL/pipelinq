// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Paste or scan, the edges: every refusal is explained in its own words, an
 * empty paste or an empty file choice sends nothing, a typed field value is
 * kept, Use these fields does nothing before a proposal, and closing or
 * cancelling closes the dialog.
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

/**
 * Mount the dialog; photos can be read, up to 1000 bytes.
 *
 * @param {object} [provide] What the app root provides.
 * @return {Promise<object>} The wrapper.
 */
async function mountModal(provide = {}) {
	api.captureStatus.mockResolvedValue({ photo: true, maxPhotoBytes: 1000 })
	const wrapper = mount(ContactCaptureModal, { props: { schema: 'contact' }, global: { mocks: { t }, provide } })
	await flushPromises()
	return wrapper
}

/**
 * The button with a test id.
 *
 * @param {object} wrapper The dialog.
 * @param {string} id The test id.
 * @return {object} The NcButton stub.
 */
function button(wrapper, id) {
	return wrapper.findAllComponents({ name: 'NcButton' }).find((b) => b.attributes('data-testid') === id)
}

describe('Paste or scan, the edges', () => {
	beforeEach(() => {
		api.captureStatus.mockReset()
		api.parseText.mockReset()
		api.parsePhoto.mockReset()
	})

	it.each([
		[413, 'This is too much to read. Paste only the signature, or take a smaller photo.'],
		[415, 'Choose a photo: the file is not an image.'],
		[422, 'No text was found on the photo. Try a sharper photo or paste the text.'],
		[502, 'The photo could not be read. Try again or paste the text.'],
		[500, 'The text could not be read. Try again.'],
		[undefined, 'The text could not be read. Try again.'],
	])('explains a refusal with status %s and keeps photos on offer', async (status, message) => {
		const wrapper = await mountModal()
		api.parsePhoto.mockRejectedValue(status === undefined ? new Error('offline') : { response: { status } })

		await wrapper.vm.onPhoto({ target: { files: [{ size: 10 }], value: 'card.jpg' } })
		await flushPromises()

		expect(wrapper.find('[data-testid="contact-capture-error"]').text()).toBe(message)
		expect(wrapper.find('[data-testid="contact-capture-photo"]').exists()).toBe(true)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('reads a photo chosen through the file input and clears the input for the next choice', async () => {
		api.parsePhoto.mockResolvedValue({ fields: { email: 'a@b.nl' }, unassigned: 'not a list' })
		const wrapper = await mountModal()
		const input = wrapper.find('[data-testid="contact-capture-photo"]')
		const photo = new File(['x'], 'card.jpg', { type: 'image/jpeg' })
		Object.defineProperty(input.element, 'files', { value: [photo], configurable: true })

		await input.trigger('change')
		await flushPromises()

		expect(api.parsePhoto).toHaveBeenCalledWith(photo)
		expect(wrapper.vm.form.email).toBe('a@b.nl')
		expect(wrapper.vm.unassigned).toEqual([])
	})

	it('sends nothing when the file choice is empty or there is no text', async () => {
		const wrapper = await mountModal()

		await wrapper.vm.onPhoto({ target: { files: [] } })
		await wrapper.vm.onPhoto(undefined)
		await wrapper.vm.readText()
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', '   ')
		await wrapper.vm.readText()

		expect(api.parsePhoto).not.toHaveBeenCalled()
		expect(api.parseText).not.toHaveBeenCalled()
		expect(button(wrapper, 'contact-capture-read').props('disabled')).toBe(true)
	})

	it('keeps an empty proposal as an empty form and leaves the pasted text alone', async () => {
		api.parseText.mockResolvedValue(null)
		const wrapper = await mountModal()
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Anna')

		await wrapper.vm.readText()
		await flushPromises()

		expect(wrapper.vm.proposal).toEqual({})
		expect(wrapper.vm.text).toBe('Anna')
		expect(Object.values(wrapper.vm.form).every((value) => value === '')).toBe(true)
		expect(wrapper.findAll('[data-testid="contact-capture-line"]')).toHaveLength(0)
	})

	it('keeps what is typed in a field and places a line by its option id', async () => {
		api.parseText.mockResolvedValue({ fields: {}, unassigned: ['Anna de Vries'] })
		const wrapper = await mountModal()
		await wrapper.findComponent({ name: 'NcTextArea' }).vm.$emit('update:modelValue', 'Anna de Vries')
		await wrapper.vm.readText()
		await flushPromises()

		const email = wrapper.findAllComponents({ name: 'NcTextField' }).find((f) => f.attributes('data-testid') === 'contact-capture-field-email')
		await email.vm.$emit('update:modelValue', 'anna@voorbeeld.nl')
		expect(wrapper.vm.form.email).toBe('anna@voorbeeld.nl')

		const picker = wrapper.findComponent({ name: 'NcSelect' })
		expect(picker.vm.$attrs.reduce({ id: 'name', label: 'Name' })).toBe('name')
	})

	it('does nothing for Use these fields before anything was read', async () => {
		const open = vi.fn()
		const wrapper = await mountModal({ cnOpenModal: open })

		wrapper.vm.useFields()

		expect(open).not.toHaveBeenCalled()
		expect(wrapper.emitted('useFields')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('closes when the dialog is dismissed or Cancel is pressed', async () => {
		const wrapper = await mountModal()

		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		await button(wrapper, 'contact-capture-cancel').vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
