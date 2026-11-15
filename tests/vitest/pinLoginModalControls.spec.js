// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The staff PIN sign-in's own controls: the 0 key, typing in the PIN
 * field, the staff picker handing over the id, and every way out of the
 * dialog (Cancel and closing it) telling the page to close it.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
const dialogsMock = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const storeMock = vi.hoisted(() => ({ openSession: vi.fn() }))

/**
 * A stand-in that keeps its props on the element and renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'disabled', 'helperText', 'label', 'name'],
		emits: ['update:modelValue', 'click', 'closing', 'keydown'],
		render() {
			return h('div', { 'data-stub': name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

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

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => dialogsMock)
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k]),
}))
vi.mock('../../src/store/modules/posSessionStore.js', () => ({ usePosSessionStore: () => storeMock }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = t

const { default: PinLoginModal } = await import('../../src/modals/PinLoginModal.vue')

/**
 * Mount with the translation helper and let mounted() settle.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = mount(component, { props, global: { mocks: { t } } })
	await flushPromises()
	return w
}

/**
 * The stand-ins of one component.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @return {Array<object>} The stand-ins.
 */
function all(w, name) {
	return w.findAllComponents({ name })
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('PinLoginModal controls', () => {
	beforeEach(() => {
		axiosMock.get.mockResolvedValue({ data: { staff: [{ id: 's1', displayName: 'Anna' }] } })
	})

	/**
	 * The button with this text or accessible name.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} label The text or aria-label.
	 * @return {object} The button.
	 */
	function button(w, label) {
		return all(w, 'NcButton').find((b) => b.text() === label || b.vm.$attrs['aria-label'] === label)
	}

	it('types a 0 with the 0 key, only once a staff member is picked', async () => {
		const w = await mountIt(PinLoginModal)
		expect(button(w, 'Digit 0').props('disabled')).toBe(true)

		await all(w, 'NcSelect')[0].vm.$emit('update:modelValue', 's1')
		await button(w, '1').vm.$emit('click')
		await button(w, 'Digit 0').vm.$emit('click')

		expect(w.vm.pin).toBe('10')
	})

	it('takes a PIN typed in the field', async () => {
		const w = await mountIt(PinLoginModal)
		await all(w, 'NcSelect')[0].vm.$emit('update:modelValue', 's1')

		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '4321')

		expect(w.vm.pin).toBe('4321')
		expect(w.vm.canSubmit).toBe(true)
	})

	it('hands the staff id, not the option, to the picker model', async () => {
		const w = await mountIt(PinLoginModal)
		const reduce = all(w, 'NcSelect')[0].vm.$attrs.reduce

		expect(reduce({ value: 's1', label: 'Anna' })).toBe('s1')
	})

	it('asks the page to close it on Cancel and when the dialog closes', async () => {
		const w = await mountIt(PinLoginModal)

		await button(w, 'Cancel').vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')

		expect(w.emitted('close')).toHaveLength(2)
		expect(axiosMock.post).not.toHaveBeenCalled()
	})
})
