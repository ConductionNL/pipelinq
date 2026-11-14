// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client form's state: the empty form it starts with, the form it
 * fills from a client, and the field checks behind its errors.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-29
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcSelect: {},
	NcTextArea: {},
	NcTextField: {},
}))
vi.mock('@nextcloud/l10n', () => ({
	getLanguage: () => 'nl',
	translate: (app, text) => text,
}))
vi.mock('../../src/components/LabelSelect.vue', () => ({ default: {} }))
vi.mock('../../src/services/clientDuplicates.js', () => ({ findClientDuplicate: vi.fn() }))

let form

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/clients/ClientForm.vue')).default
}, 60000)

afterEach(() => {
	delete window.OC
})

/**
 * @param {object} fields The form fields to check.
 * @return {object} The errors the form shows for them.
 */
function errorsFor(fields) {
	const base = { name: 'Anna', type: 'person', email: '', phone: '', website: '' }
	return form.computed.errors.call({ form: { ...base, ...fields } })
}

describe('ClientForm.errors', () => {
	it('has no errors for a valid form', () => {
		expect(errorsFor({ email: 'a@b.nl', phone: '+31 6 1234 5678', website: 'https://a.nl' })).toEqual({})
	})

	it('checks the name, the type and each format, in form order', () => {
		const errors = errorsFor({ name: '  ', type: null, email: 'x', phone: 'abc', website: 'nope' })
		expect(Object.keys(errors)).toEqual(['name', 'type', 'email', 'phone', 'website'])
		expect(errors).toEqual({
			name: 'Name is required',
			type: 'Type is required',
			email: 'Invalid email format',
			phone: 'Invalid phone format',
			website: 'Invalid URL format',
		})
	})

	it('limits the name to 255 characters', () => {
		expect(errorsFor({ name: 'a'.repeat(255) })).toEqual({})
		expect(errorsFor({ name: 'a'.repeat(256) })).toEqual({ name: 'Name must be at most 255 characters' })
	})
})

describe('ClientForm.populateForm', () => {
	it('fills every field from the client, in form order', () => {
		const ctx = {}
		const labels = ['vip']
		form.methods.populateForm.call(ctx, {
			name: 'Acme',
			type: 'organization',
			email: 'a@b.nl',
			phone: '010',
			website: 'https://a.nl',
			address: 'Street 1',
			notes: 'n',
			industry: ['IT'],
			labels,
			accountOwner: 'u1',
			correspondenceLanguage: 'en',
			timezone: 'Europe/Amsterdam',
		})
		expect(Object.keys(ctx.form)).toEqual([
			'name', 'type', 'email', 'phone', 'website', 'address', 'notes',
			'industry', 'labels', 'accountOwner', 'correspondenceLanguage', 'timezone',
		])
		expect(ctx.form).toEqual({
			name: 'Acme',
			type: 'organization',
			email: 'a@b.nl',
			phone: '010',
			website: 'https://a.nl',
			address: 'Street 1',
			notes: 'n',
			industry: ['IT'],
			labels: ['vip'],
			accountOwner: 'u1',
			correspondenceLanguage: 'en',
			timezone: 'Europe/Amsterdam',
		})
		expect(ctx.form.labels).not.toBe(labels)
	})

	it('falls back to empty values for a client without them', () => {
		const ctx = {}
		form.methods.populateForm.call(ctx, { labels: 'not-a-list' })
		expect(ctx.form).toEqual({
			name: '',
			type: null,
			email: '',
			phone: '',
			website: '',
			address: '',
			notes: '',
			industry: [],
			labels: [],
			accountOwner: null,
			correspondenceLanguage: '',
			timezone: null,
		})
	})
})

describe('ClientForm.data', () => {
	it('starts empty, with the signed-in user as account owner', () => {
		window.OC = { getCurrentUser: () => ({ uid: 'u1', displayName: 'User One' }) }
		const state = form.data.call({ initialName: 'Typed' })
		expect(Object.keys(state.form)).toEqual([
			'name', 'type', 'email', 'phone', 'website', 'address', 'notes',
			'industry', 'labels', 'accountOwner', 'correspondenceLanguage', 'timezone',
		])
		expect(state.form).toEqual({
			name: 'Typed',
			type: null,
			email: '',
			phone: '',
			website: '',
			address: '',
			notes: '',
			industry: [],
			labels: [],
			accountOwner: 'u1',
			correspondenceLanguage: '',
			timezone: null,
		})
		expect(state.userOptions).toEqual([{ id: 'u1', displayName: 'User One' }])
		expect(state.duplicate).toBeNull()
		expect(state.duplicateTimer).toBeNull()
		expect(state.languages).toEqual([])
		expect(state.searchingUsers).toBe(false)
		expect(state.typeOptions.length).toBeGreaterThan(0)
	})

	it('labels the user by uid without a display name, and has no owner without a user', () => {
		window.OC = { getCurrentUser: () => ({ uid: 'u2' }) }
		expect(form.data.call({ initialName: '' }).userOptions).toEqual([{ id: 'u2', displayName: 'u2' }])
		delete window.OC
		const state = form.data.call({ initialName: '' })
		expect(state.userOptions).toEqual([])
		expect(state.form.accountOwner).toBeNull()
		expect(state.form.name).toBe('')
	})

	it('lists the time zones Intl knows, or none', () => {
		const original = Intl.supportedValuesOf
		try {
			Intl.supportedValuesOf = () => ['Europe/Amsterdam']
			expect(form.data.call({}).timezoneOptions).toEqual(['Europe/Amsterdam'])
			Intl.supportedValuesOf = undefined
			expect(form.data.call({}).timezoneOptions).toEqual([])
		} finally {
			Intl.supportedValuesOf = original
		}
	})
})
