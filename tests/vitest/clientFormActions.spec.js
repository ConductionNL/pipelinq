// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client form's actions: the duplicate check and when it runs, loading
 * the correspondence languages and labelling them, the account-owner search,
 * and what a save hands to its host.
 *
 * @spec exclude Client-side form behaviour.
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

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
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => `/index.php${path}`,
	generateOcsUrl: (path) => `/ocs/v2.php/${path}`,
}))
vi.mock('../../src/components/LabelSelect.vue', () => ({ default: {} }))
vi.mock('../../src/services/clientDuplicates.js', () => ({ findClientDuplicate: vi.fn() }))

let form
let axios
let findClientDuplicate

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/clients/ClientForm.vue')).default
	axios = (await import('@nextcloud/axios')).default
	findClientDuplicate = (await import('../../src/services/clientDuplicates.js')).findClientDuplicate
}, 60000)

beforeEach(() => {
	vi.clearAllMocks()
})

afterEach(() => {
	vi.useRealTimers()
})

/**
 * A bare form context: its data, its computed values as getters and its
 * methods bound to it.
 *
 * @param {object} extra State to set over the defaults
 * @return {object} The context
 */
function context(extra = {}) {
	const ctx = { ...form.data.call({ initialName: '' }), client: {}, boardLayout: false, $emit: vi.fn(), ...extra }
	for (const [name, getter] of Object.entries(form.computed)) {
		Object.defineProperty(ctx, name, { get: getter.bind(ctx), configurable: true })
	}
	for (const [name, method] of Object.entries(form.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.markSaveAttempted = vi.fn()
	return ctx
}

describe('ClientForm duplicate check', () => {
	it('keys on the trimmed, lower-cased e-mail and name', () => {
		const ctx = context()
		ctx.form = { ...ctx.form, email: ' A@B.nl ', name: ' Acme ' }
		expect(ctx.duplicateKey).toBe('a@b.nl|acme')
	})

	it('shows a client found by e-mail with a link to it', async () => {
		findClientDuplicate.mockResolvedValue({ id: 'c-1', name: 'Acme', field: 'email' })
		const resolve = vi.fn(() => ({ href: '/clients/c-1' }))
		const ctx = context({ $router: { resolve } })
		ctx.form = { ...ctx.form, email: 'a@b.nl', name: 'Acme' }
		await ctx.checkDuplicate()
		expect(findClientDuplicate).toHaveBeenCalledWith({ email: 'a@b.nl', name: 'Acme' })
		expect(resolve).toHaveBeenCalledWith({ name: 'ClientDetail', params: { id: 'c-1' } })
		expect(ctx.duplicate).toEqual({ id: 'c-1', name: 'Acme', field: 'email address', href: '/clients/c-1' })
	})

	it('names the field for a name match and has no link without a router', async () => {
		findClientDuplicate.mockResolvedValue({ id: 'c-2', field: 'name' })
		const ctx = context()
		await ctx.checkDuplicate()
		expect(ctx.duplicate).toEqual({ id: 'c-2', field: 'name', href: '' })
	})

	it('clears the warning when nothing matches', async () => {
		findClientDuplicate.mockResolvedValue(null)
		const ctx = context({ duplicate: { id: 'old' } })
		await ctx.checkDuplicate()
		expect(ctx.duplicate).toBeNull()
	})

	it('drops an answer for a name the user has since changed', async () => {
		let answer
		findClientDuplicate.mockReturnValue(new Promise((resolve) => { answer = resolve }))
		const ctx = context({ duplicate: null })
		ctx.form = { ...ctx.form, name: 'Acm' }
		const pending = ctx.checkDuplicate()
		ctx.form = { ...ctx.form, name: 'Acme' }
		answer({ id: 'c-3', field: 'name' })
		await pending
		expect(ctx.duplicate).toBeNull()
	})

	it('checks after a 400 ms pause, only for a new client on the board form', () => {
		vi.useFakeTimers()
		const watcher = form.watch.duplicateKey
		const board = context({ boardLayout: true })
		board.checkDuplicate = vi.fn()
		watcher.call(board)
		vi.advanceTimersByTime(399)
		expect(board.checkDuplicate).not.toHaveBeenCalled()
		watcher.call(board)
		vi.advanceTimersByTime(400)
		expect(board.checkDuplicate).toHaveBeenCalledTimes(1)

		const plain = context({ boardLayout: false })
		plain.checkDuplicate = vi.fn()
		watcher.call(plain)
		const existing = context({ boardLayout: true, client: { id: 'c-1' } })
		existing.checkDuplicate = vi.fn()
		watcher.call(existing)
		vi.advanceTimersByTime(1000)
		expect(plain.checkDuplicate).not.toHaveBeenCalled()
		expect(existing.checkDuplicate).not.toHaveBeenCalled()
	})
})

describe('ClientForm watchers', () => {
	it('reports its validity to the host', () => {
		const ctx = context()
		form.watch.isValid.handler.call(ctx, false)
		expect(ctx.$emit).toHaveBeenCalledWith('update:valid', false)
	})

	it('fills the form from a client with fields, and ignores an empty one', () => {
		const ctx = context()
		ctx.populateForm = vi.fn()
		form.watch.client.handler.call(ctx, {})
		form.watch.client.handler.call(ctx, null)
		expect(ctx.populateForm).not.toHaveBeenCalled()
		form.watch.client.handler.call(ctx, { name: 'Acme' })
		expect(ctx.populateForm).toHaveBeenCalledWith({ name: 'Acme' })
	})

	it('is valid only without errors', () => {
		const ctx = context()
		ctx.form = { ...ctx.form, name: 'Anna', type: 'person' }
		expect(ctx.isValid).toBe(true)
		ctx.form = { ...ctx.form, email: 'nope' }
		expect(ctx.isValid).toBe(false)
	})
})

describe('ClientForm languages', () => {
	it('loads the languages and preselects the user language for a new client', async () => {
		axios.get.mockResolvedValue({ data: { languages: ['en', 'nl'], instanceDefault: 'en' } })
		const ctx = context()
		await ctx.loadLanguages()
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/correspondence-languages')
		expect(ctx.languages).toEqual(['en', 'nl'])
		expect(ctx.form.correspondenceLanguage).toBe('nl')
	})

	it('keeps the language of an existing client or one already chosen', async () => {
		axios.get.mockResolvedValue({ data: { languages: ['en', 'nl'], instanceDefault: 'en' } })
		const existing = context({ client: { id: 'c-1' } })
		existing.form = { ...existing.form, correspondenceLanguage: '' }
		await existing.loadLanguages()
		expect(existing.form.correspondenceLanguage).toBe('')
		const chosen = context()
		chosen.form = { ...chosen.form, correspondenceLanguage: 'en' }
		await chosen.loadLanguages()
		expect(chosen.form.correspondenceLanguage).toBe('en')
	})

	it('has no languages when the answer holds none or the request fails', async () => {
		axios.get.mockResolvedValueOnce({ data: { languages: 'en' } })
		const ctx = context()
		await ctx.loadLanguages()
		expect(ctx.languages).toEqual([])
		ctx.languages = ['nl']
		axios.get.mockRejectedValueOnce(new Error('down'))
		await ctx.loadLanguages()
		expect(ctx.languages).toEqual([])
	})

	it('labels each language in the user language, keeping an unknown code', () => {
		const ctx = context({ languages: ['nl', 'pt_BR', 'not a tag!'] })
		const names = new Intl.DisplayNames(['nl'], { type: 'language' })
		expect(ctx.languageOptions).toEqual([
			{ id: 'nl', label: names.of('nl') },
			{ id: 'pt_BR', label: names.of('pt-BR') },
			{ id: 'not a tag!', label: 'not a tag!' },
		])
	})
})

describe('ClientForm account owner search', () => {
	it('does not search for fewer than two characters', async () => {
		const ctx = context()
		await ctx.searchUsers('')
		await ctx.searchUsers('a')
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('offers the users the autocomplete answers, labelled', async () => {
		axios.get.mockResolvedValue({ data: { ocs: { data: [{ id: 'ann', label: 'Ann' }, { id: 'bob' }] } } })
		const ctx = context()
		const pending = ctx.searchUsers('an')
		expect(ctx.searchingUsers).toBe(true)
		await pending
		expect(axios.get).toHaveBeenCalledWith('/ocs/v2.php/core/autocomplete/get', {
			params: { search: 'an', itemType: 'pipelinq', itemId: 'client', 'shareTypes[]': 0, limit: 20 },
		})
		expect(ctx.userOptions).toEqual([{ id: 'ann', displayName: 'Ann' }, { id: 'bob', displayName: 'bob' }])
		expect(ctx.searchingUsers).toBe(false)
	})

	it('keeps the options it had when the search fails', async () => {
		axios.get.mockRejectedValue(new Error('down'))
		const ctx = context({ userOptions: [{ id: 'me', displayName: 'Me' }] })
		await ctx.searchUsers('an')
		expect(ctx.userOptions).toEqual([{ id: 'me', displayName: 'Me' }])
		expect(ctx.searchingUsers).toBe(false)
	})
})

describe('ClientForm.onSave', () => {
	it('marks the attempt and emits nothing while the form is invalid', () => {
		const ctx = context()
		ctx.onSave()
		expect(ctx.markSaveAttempted).toHaveBeenCalledTimes(1)
		expect(ctx.$emit).not.toHaveBeenCalledWith('save', expect.anything())
	})

	it('emits a new organisation with its schema type', () => {
		const ctx = context()
		ctx.form = { ...ctx.form, name: 'Acme', type: 'organization' }
		ctx.onSave()
		const [[event, data]] = ctx.$emit.mock.calls
		expect(event).toBe('save')
		expect(data).toEqual({ ...ctx.form, '@type': 'schema:Organization' })
		expect(data).not.toHaveProperty('id')
		expect(data).not.toBe(ctx.form)
	})

	it('keeps the id of the edited client and types a person', () => {
		const ctx = context({ client: { id: 'c-9' } })
		ctx.form = { ...ctx.form, name: 'Anna', type: 'person' }
		ctx.onSave()
		expect(ctx.$emit).toHaveBeenCalledWith('save', { ...ctx.form, id: 'c-9', '@type': 'schema:Person' })
	})

	it('types an unknown kind as a person', () => {
		const ctx = context()
		ctx.form = { ...ctx.form, name: 'X', type: 'robot' }
		ctx.onSave()
		expect(ctx.$emit).toHaveBeenCalledWith('save', expect.objectContaining({ '@type': 'schema:Person' }))
	})
})
