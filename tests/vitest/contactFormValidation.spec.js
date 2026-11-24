// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact form's field checks: the name, the client, the e-mail
 * address and the phone number, each setting or clearing its own error.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-52
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnResourceSelect: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcTextField: {} }))
vi.mock('../../src/dialogs/ClientCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let form

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/contacts/ContactForm.vue')).default
}, 60000)

/**
 * @param {object} state The form state.
 * @return {object} A form context with every error set.
 */
function ctx(state) {
	return { errors: { name: 'x', client: 'x', email: 'x', phone: 'x' }, ...state }
}

describe('ContactForm.validateField', () => {
	it('checks each field and leaves the others alone', () => {
		const cases = [
			['name', { form: { name: '  ' } }, 'Name is required'],
			['name', { form: { name: 'Anna' } }, ''],
			['client', { selectedClient: null }, 'Client is required'],
			['client', { selectedClient: { id: 'c' } }, ''],
			['email', { form: { email: 'nope' } }, 'Invalid email format'],
			['email', { form: { email: 'a@b.nl' } }, ''],
			['email', { form: { email: '' } }, ''],
			['phone', { form: { phone: 'abc' } }, 'Invalid phone format'],
			['phone', { form: { phone: '+31 6 1234 5678' } }, ''],
			['phone', { form: { phone: '' } }, ''],
		]
		for (const [field, state, message] of cases) {
			const c = ctx(state)
			form.methods.validateField.call(c, field)
			expect(c.errors[field]).toBe(message)
			const others = Object.entries(c.errors).filter(([key]) => key !== field)
			expect(others.every(([, value]) => value === 'x')).toBe(true)
		}
		const c = ctx({ form: {} })
		form.methods.validateField.call(c, 'website')
		expect(c.errors).toEqual({ name: 'x', client: 'x', email: 'x', phone: 'x' })
	})
})
