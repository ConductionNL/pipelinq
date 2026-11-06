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
})
