// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two POS dialogs: the staff PIN sign-in at a terminal (who can be picked,
 * the keypad, what a refused sign-in says, and the session it opens) and
 * creating or editing a tender type (which endpoint it writes, the payload,
 * and what a failure shows).
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
const { default: PosTenderTypeFormDialog } = await import('../../src/modals/PosTenderTypeFormDialog.vue')

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

/**
 * An axios-style error carrying a response.
 *
 * @param {number} status The HTTP status.
 * @param {string} [message] The server's error text.
 * @return {Error} The error.
 */
function httpError(status, message) {
	const error = new Error('HTTP ' + status)
	error.response = { status, data: message === undefined ? {} : { error: message } }
	return error
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('PinLoginModal', () => {
	beforeEach(() => {
		axiosMock.get.mockResolvedValue({
			data: {
				staff: [
					{ id: 's1', displayName: 'Anna' },
					{ id: 's2', isActive: false, displayName: 'Gone' },
					{ id: 's3' },
				],
			},
		})
	})

	/**
	 * The keypad button for one label.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} label The button text.
	 * @return {object} The button.
	 */
	function key(w, label) {
		return all(w, 'NcButton').find((b) => b.text() === label)
	}

	/**
	 * Pick a staff member and type a PIN on the keypad.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} pin The digits.
	 * @return {Promise<void>}
	 */
	async function signInAs(w, pin) {
		await all(w, 'NcSelect')[0].vm.$emit('update:modelValue', 's1')
		for (const digit of pin) {
			await key(w, digit).vm.$emit('click')
		}
	}

	it('offers only active staff, by name or else id', async () => {
		const w = await mountIt(PinLoginModal)
		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/staff')
		expect(all(w, 'NcSelect')[0].props('options')).toEqual([
			{ value: 's1', label: 'Anna' },
			{ value: 's3', label: 's3' },
		])
		expect(all(w, 'NcSelect')[0].props('disabled')).toBe(false)
	})

	it('offers nobody when the staff list cannot be read', async () => {
		axiosMock.get.mockRejectedValue(httpError(403))
		const w = await mountIt(PinLoginModal)
		expect(all(w, 'NcSelect')[0].props('options')).toEqual([])
		expect(w.vm.loading).toBe(false)
	})

	it('keeps the keypad off until a staff member is picked, and caps the PIN at six digits', async () => {
		const w = await mountIt(PinLoginModal)
		expect(key(w, '1').props('disabled')).toBe(true)

		await signInAs(w, '1234567')
		expect(w.vm.pin).toBe('123456')
		expect(key(w, '1').props('disabled')).toBe(true)
		w.vm.press('9')
		expect(w.vm.pin).toBe('123456')

		await key(w, '⌫').vm.$emit('click')
		expect(w.vm.pin).toBe('12345')
		await key(w, 'Clear').vm.$emit('click')
		expect(w.vm.pin).toBe('')
		expect(key(w, '⌫').props('disabled')).toBe(true)
	})

	it('needs four to six digits before signing in', async () => {
		const w = await mountIt(PinLoginModal)
		await signInAs(w, '123')
		const signIn = all(w, 'NcButton').at(-1)
		expect(signIn.props('disabled')).toBe(true)
		await signIn.vm.$emit('click')
		expect(axiosMock.post).not.toHaveBeenCalled()

		await key(w, '4').vm.$emit('click')
		expect(signIn.props('disabled')).toBe(false)
	})

	it('signs in, opens the POS session, and closes', async () => {
		const session = { staffId: 's1', token: 'x' }
		axiosMock.post.mockResolvedValue({ data: { session } })
		const w = await mountIt(PinLoginModal)
		await signInAs(w, '2468')

		await all(w, 'NcButton').at(-1).vm.$emit('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/staff/auth', { staffId: 's1', pin: '2468' })
		expect(storeMock.openSession).toHaveBeenCalledWith(session)
		expect(w.emitted('loginSuccess')).toEqual([[session]])
		expect(w.emitted('close')).toEqual([[]])
		expect(w.vm.submitting).toBe(false)
	})

	it('treats an answer without a staff session as a failed sign-in and clears the PIN', async () => {
		axiosMock.post.mockResolvedValue({ data: { session: {} } })
		const w = await mountIt(PinLoginModal)
		await signInAs(w, '2468')
		await all(w, 'NcButton').at(-1).vm.$emit('click')
		await flushPromises()

		expect(storeMock.openSession).not.toHaveBeenCalled()
		expect(w.emitted('loginSuccess')).toBeUndefined()
		expect(w.find('.pin-login__error').text()).toBe('An unexpected error occurred')
		expect(w.vm.pin).toBe('')
	})

	it.each([
		[httpError(403, 'Onjuiste PIN'), 'Onjuiste PIN', ''],
		[httpError(403), 'Incorrect PIN', ''],
		[httpError(403, 'Account geblokkeerd tot 12:30'), '', 'Account geblokkeerd tot 12:30'],
		[httpError(403, 'Locked until 12:30'), '', 'Locked until 12:30'],
		[httpError(500, 'Database down'), 'Database down', ''],
		[httpError(500), 'An unexpected error occurred', ''],
	])('shows a refused sign-in (%#)', async (error, message, locked) => {
		axiosMock.post.mockRejectedValue(error)
		const w = await mountIt(PinLoginModal)
		await signInAs(w, '1111')
		await all(w, 'NcButton').at(-1).vm.$emit('click')
		await flushPromises()

		expect(w.vm.errorMessage).toBe(message)
		expect(w.vm.lockedUntil).toBe(locked)
		if (locked) {
			expect(w.text()).toContain(`Account is locked. Try again after ${locked}.`)
		}
		expect(w.vm.pin).toBe('')
		expect(w.emitted('close')).toBeUndefined()
	})

	it('clears an error when a digit is pressed, and closes on cancel', async () => {
		axiosMock.post.mockRejectedValue(httpError(403))
		const w = await mountIt(PinLoginModal)
		await signInAs(w, '1111')
		await all(w, 'NcButton').at(-1).vm.$emit('click')
		await flushPromises()
		expect(w.vm.errorMessage).toBe('Incorrect PIN')

		await key(w, '5').vm.$emit('click')
		expect(w.vm.errorMessage).toBe('')
		await key(w, 'Cancel').vm.$emit('click')
		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('PosTenderTypeFormDialog', () => {
	/**
	 * The Save button.
	 *
	 * @param {object} w The wrapper.
	 * @return {object} The button.
	 */
	function save(w) {
		return all(w, 'NcButton')[1]
	}

	it('starts a new tender type empty and active, with an editable code', async () => {
		const w = await mountIt(PosTenderTypeFormDialog)
		expect(all(w, 'CnDialog')[0].props('name')).toBe('New tender type')
		expect(w.vm.form).toEqual({
			name: '',
			code: '',
			description: '',
			glAccount: '',
			requiresReference: false,
			requiresPin: false,
			allowsChange: false,
			isActive: true,
			sortOrder: 0,
		})
		expect(all(w, 'NcTextField')[1].props('disabled')).toBe(false)
		expect(all(w, 'NcTextField')[1].props('helperText')).toBe('Machine-readable identifier (uppercase letters)')
		expect(save(w).props('disabled')).toBe(true)
	})

	it('needs a name, a code and a GL account before saving', async () => {
		const w = await mountIt(PosTenderTypeFormDialog)
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', 'Contant')
		await all(w, 'NcTextField')[1].vm.$emit('update:modelValue', 'cash')
		expect(save(w).props('disabled')).toBe(true)
		await all(w, 'NcTextField')[3].vm.$emit('update:modelValue', '1100')
		expect(save(w).props('disabled')).toBe(false)
	})

	it('creates a tender type with an uppercased code, confirms, and reports saved', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const w = await mountIt(PosTenderTypeFormDialog)
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', 'Contant')
		await all(w, 'NcTextField')[1].vm.$emit('update:modelValue', 'cash')
		await all(w, 'NcTextField')[3].vm.$emit('update:modelValue', '1100')
		await all(w, 'NcCheckboxRadioSwitch')[2].vm.$emit('update:modelValue', true)

		await save(w).vm.$emit('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos/tender-types', {
			name: 'Contant',
			code: 'CASH',
			description: '',
			glAccount: '1100',
			requiresReference: false,
			requiresPin: false,
			allowsChange: true,
			isActive: true,
			sortOrder: 0,
		})
		expect(dialogsMock.showSuccess).toHaveBeenCalledWith('Tender type created')
		expect(w.emitted('saved')).toEqual([[]])
		expect(w.vm.saving).toBe(false)
	})

	it('pre-fills an existing tender type, locks its code, and updates it by its record id', async () => {
		axiosMock.put.mockResolvedValue({ data: {} })
		const w = await mountIt(PosTenderTypeFormDialog, {
			tenderType: {
				'@self': { id: 'tt-9' },
				name: 'Cadeaubon',
				code: 'voucher',
				glAccount: '2100',
				requiresReference: 1,
				isActive: false,
				sortOrder: '3',
			},
		})
		expect(all(w, 'CnDialog')[0].props('name')).toBe('Edit tender type')
		expect(w.vm.form).toMatchObject({ code: 'VOUCHER', requiresReference: true, isActive: false, sortOrder: 3 })
		expect(all(w, 'NcTextField')[1].props('disabled')).toBe(true)
		expect(all(w, 'NcTextField')[1].props('helperText')).toBe('Code is immutable after creation')

		await save(w).vm.$emit('click')
		await flushPromises()

		expect(axiosMock.put).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/pos/tender-types/tt-9',
			expect.objectContaining({ name: 'Cadeaubon', code: 'VOUCHER', isActive: false }),
		)
		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(dialogsMock.showSuccess).toHaveBeenCalledWith('Tender type updated')
	})

	it('treats a tender type without any id as new', async () => {
		const w = await mountIt(PosTenderTypeFormDialog, { tenderType: { name: 'Draft' } })
		expect(w.vm.isNew).toBe(true)
		expect(w.vm.form.name).toBe('Draft')
	})

	it.each([
		[httpError(409, 'Code CASH already exists'), 'Code CASH already exists'],
		[new Error('offline'), 'Failed to save tender type'],
	])('shows why a save failed and does not report saved (%#)', async (error, message) => {
		axiosMock.post.mockRejectedValue(error)
		const w = await mountIt(PosTenderTypeFormDialog, { tenderType: { name: 'A', code: 'A', glAccount: '1' } })
		await save(w).vm.$emit('click')
		await flushPromises()

		expect(w.find('.pos-tender-type-form__error').text()).toBe(message)
		expect(dialogsMock.showError).toHaveBeenCalledWith(message)
		expect(w.emitted('saved')).toBeUndefined()
		expect(w.vm.saving).toBe(false)
	})

	it('closes on cancel and on closing', async () => {
		const w = await mountIt(PosTenderTypeFormDialog)
		await all(w, 'NcButton')[0].vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')
		expect(w.emitted('close')).toEqual([[], []])
	})
})
