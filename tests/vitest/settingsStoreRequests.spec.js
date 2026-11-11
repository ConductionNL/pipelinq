/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The settings store's read and write: the request each sends, the state
 * each leaves, and how each fails.
 */
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { useSettingsStore } = await import('../../src/store/modules/settings.js')

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }
let fetchMock
let errorLog

beforeEach(() => {
	setActivePinia(createPinia())
	globalThis.OC = { requestToken: 'tok' }
	fetchMock = vi.fn()
	vi.stubGlobal('fetch', fetchMock)
	errorLog = vi.spyOn(console, 'error').mockImplementation(() => {})
	return () => {
		vi.unstubAllGlobals()
		errorLog.mockRestore()
	}
})

describe('settings store fetchSettings', () => {
	it('reads the settings and the flags', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ config: { a: 1 }, openRegisters: true, isAdmin: true }) })
		const store = useSettingsStore()
		const pending = store.fetchSettings()
		expect(store.loading).toBe(true)
		expect(await pending).toEqual({ config: { a: 1 }, openRegisters: true, isAdmin: true })
		expect(fetchMock).toHaveBeenCalledWith('/apps/pipelinq/api/settings', { method: 'GET', headers })
		expect(store.$state).toMatchObject({ config: { a: 1 }, openRegisters: true, isAdmin: true, initialized: true, loading: false, error: null })
	})

	it('takes a bare answer as the config, with the flags off', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ b: 2 }) })
		const store = useSettingsStore()
		await store.fetchSettings()
		expect(store.$state).toMatchObject({ config: { b: 2 }, openRegisters: false, isAdmin: false, initialized: true })
	})

	it('keeps the error of a refused or failed read', async () => {
		fetchMock.mockResolvedValue({ ok: false, statusText: 'Forbidden' })
		const store = useSettingsStore()
		expect(await store.fetchSettings()).toBeNull()
		expect(store.error).toBe('Failed to fetch settings: Forbidden')
		expect(store.initialized).toBe(false)
		expect(errorLog).toHaveBeenCalledWith('Error fetching Pipelinq settings:', new Error('Failed to fetch settings: Forbidden'))
		fetchMock.mockRejectedValue(new Error('offline'))
		expect(await store.fetchSettings()).toBeNull()
		expect(store.error).toBe('offline')
		expect(store.loading).toBe(false)
	})
})

describe('settings store saveSettings', () => {
	it('writes the settings and keeps what the server stored', async () => {
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ config: { a: 2 } }) })
		const store = useSettingsStore()
		expect(await store.saveSettings({ a: 2 })).toEqual({ a: 2 })
		expect(fetchMock).toHaveBeenCalledWith('/apps/pipelinq/api/settings', { method: 'POST', headers, body: '{"a":2}' })
		expect(store.config).toEqual({ a: 2 })
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({ c: 3 }) })
		expect(await store.saveSettings({})).toEqual({ c: 3 })
	})

	it('keeps the error of a refused or failed write', async () => {
		fetchMock.mockResolvedValue({ ok: false, statusText: 'Bad Request' })
		const store = useSettingsStore()
		expect(await store.saveSettings({})).toBeNull()
		expect(store.error).toBe('Failed to save settings: Bad Request')
		expect(errorLog).toHaveBeenCalledWith('Error saving Pipelinq settings:', new Error('Failed to save settings: Bad Request'))
		fetchMock.mockRejectedValue(new Error('offline'))
		expect(await store.saveSettings({})).toBeNull()
		expect(store.error).toBe('offline')
		expect(store.loading).toBe(false)
	})
})
