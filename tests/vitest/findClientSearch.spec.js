// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Find client dashboard widget searches on the server. It used to load
 * the first 200 clients and filter them in the browser, so client 201 onwards
 * could never be found.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-40
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

let widget

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'token' }
	widget = (await import('../../src/views/widgets/FindClientWidget.vue')).default
})

/**
 * A widget context with the client schema configured.
 *
 * @param {string} query What is in the search box.
 * @return {object}
 */
function context(query) {
	const ctx = {
		...widget.data.call({}),
		config: { client: { register: 'pipelinq', schema: 'client' } },
		searchQuery: query,
	}
	ctx.fetchRaw = widget.methods.fetchRaw.bind(ctx)
	ctx.clientsByPhone = widget.methods.clientsByPhone.bind(ctx)
	return ctx
}

describe('FindClientWidget', () => {
	it('asks the server for the search term instead of a fixed first page', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [{ id: 'c-250', name: 'Late client' }] }) })
		globalThis.fetch = fetchMock
		const ctx = context(' late ')
		await widget.methods.searchClients.call(ctx)
		const url = new URL(fetchMock.mock.calls[0][0], 'https://nc.example')
		expect(url.searchParams.get('_search')).toBe('late')
		expect(url.searchParams.get('_limit')).toBe('20')
		expect(ctx.clients).toEqual([{ id: 'c-250', name: 'Late client' }])
	})

	it('keeps the newest answer when an older search answers last', async () => {
		let releaseOld
		const fetchMock = vi.fn()
			.mockImplementationOnce(() => new Promise((resolve) => { releaseOld = () => resolve({ ok: true, json: async () => ({ results: [{ id: 'old' }] }) }) }))
			.mockResolvedValueOnce({ ok: true, json: async () => ({ results: [{ id: 'new' }] }) })
		globalThis.fetch = fetchMock
		const ctx = context('a')
		const older = widget.methods.searchClients.call(ctx)
		ctx.searchQuery = 'ab'
		await widget.methods.searchClients.call(ctx)
		releaseOld()
		await older
		expect(ctx.clients).toEqual([{ id: 'new' }])
	})

	it('shows what the server answered without filtering it again', () => {
		const ctx = { clients: Array.from({ length: 25 }, (_, i) => ({ id: String(i), name: 'x' })), searchQuery: 'nothing matches this' }
		expect(widget.computed.filteredClients.call(ctx)).toHaveLength(20)
	})

	it('finds a client by a phone number however it was typed, through the server match', async () => {
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ matches: [{ id: 'c-1', name: 'Sanne de Vries', phone: '06 12 34 56 78', _matchType: 'client' }, { id: 'p-1', name: 'A contact', _matchType: 'contact' }] }),
		})
		globalThis.fetch = fetchMock
		const ctx = context(' 0612345678 ')
		await widget.methods.searchClients.call(ctx)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/apps/pipelinq/api/cti/screen-pop')
		expect(init.method).toBe('POST')
		expect(JSON.parse(init.body)).toEqual({ fromNumber: '0612345678' })
		expect(ctx.clients.map((c) => c.id)).toEqual(['c-1'])
	})

	it('searches the typed number as text when the phone match is refused', async () => {
		const fetchMock = vi.fn()
			.mockResolvedValueOnce({ ok: false, status: 403, json: async () => ({}) })
			.mockResolvedValueOnce({ ok: true, json: async () => ({ results: [{ id: 'c-9' }] }) })
		globalThis.fetch = fetchMock
		const ctx = context('+31612345678')
		await widget.methods.searchClients.call(ctx)
		const url = new URL(fetchMock.mock.calls[1][0], 'https://nc.example')
		expect(url.searchParams.get('_search')).toBe('+31612345678')
		expect(ctx.clients).toEqual([{ id: 'c-9' }])
	})

	it('searches a name as text, not as a phone number', async () => {
		const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		globalThis.fetch = fetchMock
		await widget.methods.searchClients.call(context('Bakkerij 12'))
		expect(fetchMock.mock.calls[0][0]).not.toContain('screen-pop')
	})
})
