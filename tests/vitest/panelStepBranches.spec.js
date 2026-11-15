/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of three panels pinned before they were split into named
 * steps: the forecast settings save, the client autocomplete search and
 * the werkplek new task confirm.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ put: vi.fn(), get: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcSettingsSection: {},
	NcTextField: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnFormDialog: {} }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let forecast
let autocomplete
let taskDialog

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	forecast = (await import('../../src/components/admin/ForecastSettings.vue')).default
	autocomplete = (await import('../../src/components/widgets/ClientAutocomplete.vue')).default
	taskDialog = (await import('../../src/components/werkplek/WerkplekNewTaskDialog.vue')).default
}, 60000)

beforeEach(() => {
	http.put.mockReset()
	dialogs.showError.mockReset()
})

describe('ForecastSettings.save', () => {
	/**
	 * @param {object} state The rows and form.
	 * @return {object} A settings context.
	 */
	function ctx(state) {
		return {
			...forecast.methods,
			form: { period: 'quarter', rate_sources: ['ecb'] },
			rateRows: [],
			overrideRows: [],
			saving: false,
			message: 'old',
			messageType: '',
			...state,
		}
	}

	it('saves the form without the rate sources, with the rates and fixed rates', async () => {
		http.put.mockResolvedValue({})
		const c = ctx({
			rateRows: [{ currency: 'usd', rate: '1.1' }, { currency: '', rate: '' }],
			overrideRows: [{ currency: 'GBP', rate: '0.8', reason: 'Contract' }],
		})
		const pending = forecast.methods.save.call(c)
		expect(c.saving).toBe(true)
		expect(c.message).toBe('')
		await pending
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/settings/forecast', {
			period: 'quarter',
			exchange_rates: { USD: 1.1 },
			rate_overrides: { GBP: { rate: 0.8, reason: 'Contract' } },
		})
		expect(c.message).toBe('Forecast configuration saved.')
		expect(c.messageType).toBe('success')
		expect(c.saving).toBe(false)
	})

	it('refuses rows that do not make a rate, with the reason first', async () => {
		const cases = [
			[{ overrideRows: [{ currency: 'GBP', rate: '0.8', reason: '' }], rateRows: [{ currency: 'x', rate: '1' }] }, 'Give a reason for every fixed rate.'],
			[{ rateRows: [{ currency: 'EURO', rate: '1' }] }, 'Use a three-letter currency code for every rate.'],
			[{ rateRows: [{ currency: 'USD', rate: '0' }] }, 'Every rate must be a number above zero.'],
			[{ overrideRows: [{ currency: '', rate: '', reason: 'why' }] }, 'Use a three-letter currency code for every rate.'],
			[{ overrideRows: [{ currency: 'USD', rate: '-1', reason: 'why' }] }, 'Every rate must be a number above zero.'],
		]
		for (const [state, message] of cases) {
			const c = ctx(state)
			await forecast.methods.save.call(c)
			expect(c.message).toBe(message)
			expect(c.messageType).toBe('error')
			expect(c.saving).toBe(false)
		}
		expect(http.put).not.toHaveBeenCalled()
	})

	it('says when the save failed', async () => {
		http.put.mockRejectedValue(new Error('500'))
		const c = ctx({})
		await forecast.methods.save.call(c)
		expect(c.message).toBe('Could not save the forecast configuration.')
		expect(c.messageType).toBe('error')
		expect(c.saving).toBe(false)
	})
})

describe('ClientAutocomplete.searchClients', () => {
	/**
	 * @param {object} state The search state.
	 * @return {object} An autocomplete context.
	 */
	function ctx(state) {
		return {
			...autocomplete.methods,
			config: { client: { register: 'pipelinq', schema: 'client' } },
			query: 'Ac',
			results: ['old'],
			showDropdown: true,
			...state,
		}
	}

	it('asks for ten matching clients and shows them', async () => {
		const fetchMock = vi.fn(async () => ({ ok: true, json: async () => ({ results: [{ id: 1 }] }) }))
		vi.stubGlobal('fetch', fetchMock)
		const c = ctx({ query: 'Acme & co' })
		await autocomplete.methods.searchClients.call(c)
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/client?_search=Acme+%26+co&_limit=10', {
			headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
		})
		expect(c.results).toEqual([{ id: 1 }])
		expect(c.showDropdown).toBe(true)

		fetchMock.mockResolvedValue({ ok: true, json: async () => [{ id: 2 }] })
		await autocomplete.methods.searchClients.call(c)
		expect(c.results).toEqual([{ id: 2 }])
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		await autocomplete.methods.searchClients.call(c)
		expect(c.results).toEqual([])
		expect(c.showDropdown).toBe(false)
		fetchMock.mockResolvedValue({ ok: true, json: async () => null })
		await autocomplete.methods.searchClients.call(c)
		expect(c.results).toEqual([])
		vi.unstubAllGlobals()
	})

	it('clears the list without a config or a long enough query, and on a failure', async () => {
		const fetchMock = vi.fn(async () => ({ ok: false }))
		vi.stubGlobal('fetch', fetchMock)
		for (const state of [{ config: null }, { config: {} }, { query: '' }, { query: 'A' }]) {
			const c = ctx(state)
			await autocomplete.methods.searchClients.call(c)
			expect(c.results).toEqual([])
			expect(c.showDropdown).toBe(false)
		}
		expect(fetchMock).not.toHaveBeenCalled()
		const error = vi.spyOn(console, 'error').mockImplementation(() => {})
		const c = ctx({})
		await autocomplete.methods.searchClients.call(c)
		expect(c.results).toEqual([])
		expect(c.showDropdown).toBe(false)
		expect(error).toHaveBeenCalledWith('ClientAutocomplete search error:', new Error('Search failed'))
		error.mockRestore()
		vi.unstubAllGlobals()
	})
})

describe('WerkplekNewTaskDialog.onConfirm', () => {
	/**
	 * @param {object} state The dialog's state.
	 * @return {object} A dialog context.
	 */
	function ctx(state) {
		return {
			...taskDialog.methods,
			clientId: 'c1',
			contactMomentSummary: '',
			t: (app, text) => text,
			objectStore: { saveObject: vi.fn(async () => ({ id: 't1' })) },
			$emit: vi.fn(),
			...state,
		}
	}

	it('stores the task as open with the client and a summary', async () => {
		const c = ctx({})
		await taskDialog.methods.onConfirm.call(c, { title: 'Call', description: 'About X' })
		expect(c.objectStore.saveObject).toHaveBeenCalledWith('crmTask', {
			title: 'Call', description: 'About X', clientId: 'c1', contactMomentSummary: 'About X', status: 'open',
		})
		expect(c.$emit).toHaveBeenCalledWith('saved', { id: 't1' })

		const withSummary = ctx({ contactMomentSummary: 'From call' })
		await taskDialog.methods.onConfirm.call(withSummary, { title: 'Call', status: 'done', clientId: 'x' })
		expect(withSummary.objectStore.saveObject).toHaveBeenCalledWith('crmTask', {
			title: 'Call', clientId: 'c1', contactMomentSummary: 'From call', status: 'open',
		})
		const bare = ctx({})
		await taskDialog.methods.onConfirm.call(bare, {})
		expect(bare.objectStore.saveObject).toHaveBeenCalledWith('crmTask', { clientId: 'c1', contactMomentSummary: '', status: 'open' })
	})

	it('says so when the task was not stored, and survives a failing notice', async () => {
		const empty = ctx({ objectStore: { saveObject: vi.fn(async () => null) } })
		await taskDialog.methods.onConfirm.call(empty, {})
		expect(dialogs.showError).toHaveBeenCalledWith('Could not create task. Please try again.')
		expect(empty.$emit).not.toHaveBeenCalled()

		const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
		const failure = new Error('500')
		const failing = ctx({ objectStore: { saveObject: vi.fn(async () => { throw failure }) } })
		dialogs.showError.mockImplementation(() => { throw new Error('no toast') })
		await taskDialog.methods.onConfirm.call(failing, {})
		expect(warn).toHaveBeenCalledWith('[WerkplekNewTaskDialog] save failed', failure)
		await expect(taskDialog.methods.onConfirm.call(ctx({ objectStore: { saveObject: vi.fn(async () => null) } }), {})).resolves.toBeUndefined()
		warn.mockRestore()
	})

	it('builds the payload before saving, so a missing form throws', async () => {
		await expect(taskDialog.methods.onConfirm.call(ctx({}), null)).rejects.toThrow(TypeError)
	})
})
