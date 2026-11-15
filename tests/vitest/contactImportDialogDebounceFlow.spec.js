// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact import dialog's flow: a search waits for two characters and a
 * pause, the picked contacts are imported one by one with the import type and
 * client, and a failed search or import leaves the dialog usable.
 *
 * Only HTTP (fetch) and the Nextcloud globals are replaced.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => '/index.php' + path,
}))

const { default: ContactImportDialog } = await import('../../src/dialogs/ContactImportDialog.vue')

/**
 * A fetch answering per URL fragment.
 *
 * @param {Record<string, Function>} routes URL fragment to handler(url, init).
 * @return {Function} The fetch mock.
 */
function fetchRoutes(routes) {
	return vi.fn(async (url, init) => {
		for (const [fragment, handler] of Object.entries(routes)) {
			if (url.includes(fragment)) {
				return handler(url, init)
			}
		}
		throw new Error('unexpected ' + url)
	})
}

const ok = (data) => ({ ok: true, json: async () => data })

describe('the contact import dialog flow', () => {
	beforeEach(() => {
		vi.stubGlobal('OC', { requestToken: 'token-1' })
	})

	afterEach(() => {
		vi.unstubAllGlobals()
		vi.useRealTimers()
	})

	it('searches 300 ms after the last keystroke, and not below two characters', async () => {
		vi.useFakeTimers()
		const fetchMock = fetchRoutes({
			'/contacts-sync/search': () => ok({ results: [{ uid: 'a', name: 'Anouk' }] }),
		})
		vi.stubGlobal('fetch', fetchMock)
		const w = mount(ContactImportDialog)

		w.vm.onSearch('a')
		await vi.advanceTimersByTimeAsync(400)
		expect(fetchMock).not.toHaveBeenCalled()
		expect(w.vm.results).toEqual([])

		w.vm.onSearch('an')
		w.vm.onSearch('ano k')
		await vi.advanceTimersByTimeAsync(299)
		expect(fetchMock).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(1)
		await flushPromises()

		expect(fetchMock).toHaveBeenCalledTimes(1)
		const [url, init] = fetchMock.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/api/contacts-sync/search?q=ano%20k')
		expect(init.headers).toEqual({
			'Content-Type': 'application/json',
			requesttoken: 'token-1',
			'OCS-APIREQUEST': 'true',
		})
		expect(w.vm.results).toEqual([{ uid: 'a', name: 'Anouk' }])
		expect(w.vm.searching).toBe(false)

		w.vm.onClearSearch()
		expect(w.vm.query).toBe('')
		expect(w.vm.results).toEqual([])
	})

	it('keeps the results on a refused search and empties them on a failed one', async () => {
		vi.stubGlobal('fetch', fetchRoutes({ '/contacts-sync/search': () => ({ ok: false }) }))
		const w = mount(ContactImportDialog)
		await w.setData({ query: 'an', results: [{ uid: 'old' }] })

		await w.vm.doSearch()
		expect(w.vm.results).toEqual([{ uid: 'old' }])

		vi.stubGlobal('fetch', fetchRoutes({ '/contacts-sync/search': () => { throw new Error('offline') } }))
		await w.vm.doSearch()
		expect(w.vm.results).toEqual([])
		expect(w.vm.searching).toBe(false)

		vi.stubGlobal('fetch', fetchRoutes({ '/contacts-sync/search': () => ok({}) }))
		await w.vm.doSearch()
		expect(w.vm.results).toEqual([])
	})

	it('imports only the picked contacts, with the type and client, then closes', async () => {
		const bodies = []
		vi.stubGlobal('fetch', fetchRoutes({
			'/contacts-sync/import': (url, init) => {
				const body = JSON.parse(init.body)
				bodies.push(body)
				expect(init.method).toBe('POST')
				if (body.uid === 'c') {
					return { ok: false }
				}
				return ok({ object: { id: 'obj-' + body.uid } })
			},
		}))
		const w = mount(ContactImportDialog, { props: { importType: 'contact', clientId: 'client-7' } })
		await w.setData({
			results: [
				{ uid: 'a', addressBookKey: 'ab-1' },
				{ uid: 'b', addressBookKey: 'ab-1' },
				{ uid: 'c', addressBookKey: 'ab-2' },
			],
		})
		w.vm.toggle(w.vm.results[0])
		w.vm.toggle(w.vm.results[2])

		await w.vm.importSelected()

		expect(bodies).toEqual([
			{ uid: 'a', addressBookKey: 'ab-1', type: 'contact', clientId: 'client-7' },
			{ uid: 'c', addressBookKey: 'ab-2', type: 'contact', clientId: 'client-7' },
		])
		expect(w.emitted('imported')).toEqual([[{ id: 'obj-a' }]])
		expect(w.vm.results[0].alreadyLinked).toBe(true)
		expect(w.vm.results[2].alreadyLinked).toBeUndefined()
		expect(w.vm.selected).toEqual([])
		expect(w.vm.importing).toBe(false)
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('imports a client without a client id, and survives a failed request', async () => {
		const bodies = []
		vi.stubGlobal('fetch', fetchRoutes({
			'/contacts-sync/import': (url, init) => {
				bodies.push(JSON.parse(init.body))
				throw new Error('offline')
			},
		}))
		const w = mount(ContactImportDialog)
		await w.setData({ results: [{ uid: 'a', addressBookKey: 'ab-1' }] })
		w.vm.toggle(w.vm.results[0])

		await w.vm.importSelected()

		expect(bodies).toEqual([{ uid: 'a', addressBookKey: 'ab-1', type: 'client' }])
		expect(w.emitted('imported')).toBeUndefined()
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('describes a contact by email and phone, or as unknown', () => {
		const w = mount(ContactImportDialog)

		expect(w.vm.metaLine({ email: 'a@example.nl', phone: '06 1' })).toBe('a@example.nl · 06 1')
		expect(w.vm.metaLine({ phone: '06 1' })).toBe('06 1')
		expect(w.vm.metaLine({})).toBe('Unknown')
	})
})
