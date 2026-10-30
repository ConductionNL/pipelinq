/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Three tables that scrolled sideways at a 1100px window now fit: social
 * performance shows the publication date under the network and the follower
 * count under the engagement rate, the kassakoppeling audit log shows the
 * register under the operator, and the competitor watches let their cells
 * wrap. No value is dropped, only moved to a second line.
 *
 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md#REQ-AUDIT-003
 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {},
	CnPagination: {},
	CnStatusBadge: {},
}))
vi.mock('@nextcloud/vue', () => ({ NcNoteCard: {}, NcSelect: {} }))
vi.mock('@nextcloud/axios', () => ({ default: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: SocialPerformanceView } =
	await import('../../src/views/social/SocialPerformanceView.vue')

describe('social performance', () => {
	const view = {
		formatDate: (value) => 'date:' + value,
	}
	const columns = SocialPerformanceView.computed.columns.call(view)
	const byKey = Object.fromEntries(columns.map((c) => [c.key, c]))

	it('keeps six sortable columns', () => {
		expect(columns.map((c) => c.key)).toEqual([
			'network',
			'views',
			'likes',
			'comments',
			'shares',
			'engagementRate',
		])
		expect(columns.every((c) => c.sortable)).toBe(true)
	})

	it('shows the publication date under the network, wrapping', () => {
		expect(byKey.network.secondary({ publishedAt: '2026-03-01' })).toBe(
			'date:2026-03-01',
		)
		expect(byKey.network.cellClass).toBe('cn-cell--wrap')
	})

	it('shows the follower count under the engagement rate', () => {
		expect(byKey.engagementRate.secondary({ followerCount: 900 })).toBe(
			'Followers: 900',
		)
	})

	it('still offers all eight orders, the date and followers included', () => {
		const options = SocialPerformanceView.computed.sortOptions.call({
			columns,
		})
		expect(options.map((o) => o.value)).toEqual([
			'network',
			'publishedAt',
			'views',
			'likes',
			'comments',
			'shares',
			'followerCount',
			'engagementRate',
		])
		expect(
			SocialPerformanceView.computed.orderOptions.call({}).map((o) => o.value),
		).toEqual(['asc', 'desc'])
	})

	it('sorts the rows by a second-line value chosen in the select', () => {
		const state = { sortKey: 'engagementRate', sortOrder: 'desc' }
		SocialPerformanceView.methods.onSort.call(state, {
			key: 'followerCount',
			order: 'asc',
		})
		const rows = [
			{ id: 'a', followerCount: 4000, publishedAt: '2026-03-02' },
			{ id: 'b', followerCount: 900, publishedAt: '2026-03-03' },
			{ id: 'c', followerCount: 2500, publishedAt: '2026-03-01' },
		]
		const sorted = (key, order) =>
			SocialPerformanceView.computed.sortedRows
				.call({ sortKey: key, sortOrder: order, tableRows: rows })
				.map((r) => r.id)
		expect(sorted(state.sortKey, state.sortOrder)).toEqual(['b', 'c', 'a'])
		expect(sorted('publishedAt', 'desc')).toEqual(['b', 'a', 'c'])
	})
})

describe('competitor watches', () => {
	it('lets the table cells wrap', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/marketing/CompetitorWatches.vue'),
			'utf8',
		)
		const table = source.match(/\.competitors__table \{[\s\S]*?\n\}/)[0]
		expect(table).toMatch(/white-space: normal/)
	})
})
