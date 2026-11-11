/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export destinations and export runs lists: the tab strip over the BI
 * export lists, and the Refresh spinner around the list fetch.
 *
 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn(), useListView: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/services/exportApi.js', () => ({ exportApi: {} }))

const pages = {}

beforeAll(async () => {
	globalThis.t = (app, text) => text
	for (const name of ['ExportDestinations', 'ExportRuns']) {
		pages[name] = (await import(`../../src/views/export/${name}.vue`)).default
	}
}, 60000)

/**
 * @param {object} component A component.
 * @param {string} key An option.
 * @return {object} The option, merged over the component's mixins.
 */
function merged(component, key) {
	return Object.assign({}, ...[...(component.mixins || []), component].map((part) => (typeof part[key] === 'function' ? part[key].call({}) : part[key]) || {}))
}

describe.each(['ExportDestinations', 'ExportRuns'])('%s', (name) => {
	it('starts with no busy row and no refresh, and draws the export tab strip', async () => {
		const data = merged(pages[name], 'data')
		expect(data.busyId).toBe(null)
		expect(data.refreshing).toBe(false)
		const { exportPageTabs } = await import('../../src/views/export/exportPageTabs.js')
		expect(merged(pages[name], 'computed').pageTabs.call({})).toEqual(exportPageTabs(globalThis.t))
	})

	it('spins while the list refreshes, and stops when the refresh fails', async () => {
		const onRefresh = merged(pages[name], 'methods').onRefresh
		const seen = []
		const ctx = { refreshing: false, refresh: vi.fn(async () => seen.push(ctx.refreshing)) }
		await onRefresh.call(ctx)
		expect(seen).toEqual([true])
		expect(ctx.refreshing).toBe(false)
		ctx.refresh = vi.fn(async () => { throw new Error('x') })
		await expect(onRefresh.call(ctx)).rejects.toThrow('x')
		expect(ctx.refreshing).toBe(false)
	})
})
