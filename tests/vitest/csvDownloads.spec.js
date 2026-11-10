// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CSV files the blast results page and the search queries page hand to
 * the browser: the semicolon layout, the quoting and the file names.
 *
 * @spec openspec/specs/marketing-analytics/spec.md
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { downloadCsv, toCsv } from '../../src/services/csvExport.js'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {} }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnActionsMenu: {}, CnBuildiqEditButton: {}, CnDataTable: {}, CnPageHeader: {}, CnSegmentedControl: {}, CnStatusBadge: {}, CnTab: {}, CnTabs: {}, useLook: () => ({ isBoard: false, lookClass: '' }) }))
vi.mock('../../src/views/marketing/CompetitorWatches.vue', () => ({ default: {} }))
vi.mock('../../src/views/marketing/KeywordIntelligence.vue', () => ({ default: {} }))

let saved
let blastResults
let searchQueries

beforeAll(async () => {
	globalThis.t = (app, text) => text
	blastResults = (await import('../../src/views/blasts/PerformanceDashboard.vue')).default
	searchQueries = (await import('../../src/views/marketing/SearchQueries.vue')).default
}, 60000)

beforeEach(() => {
	saved = { parts: null, options: null, link: null }
	vi.stubGlobal('Blob', class {
		constructor(parts, options) {
			saved.parts = parts
			saved.options = options
		}
	})
	vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:csv')
	vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
	const create = document.createElement.bind(document)
	vi.spyOn(document, 'createElement').mockImplementation((tag) => {
		const el = create(tag)
		el.click = vi.fn()
		saved.link = el
		return el
	})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

const rows = [
	{ a: 'plain', b: 'semi;colon', c: 'line\nbreak' },
	{ a: 'say "hi"', b: null, c: undefined },
	{ a: 0, b: false, c: 12.5 },
]

const expected = 'a;b;c\nplain;"semi;colon";"line\nbreak"\n"say ""hi""";;\n0;false;12.5'

describe('toCsv', () => {
	it('quotes cells holding a quote, a semicolon or a newline, and leaves empty values empty', () => {
		expect(toCsv(rows, ['a', 'b', 'c'])).toBe(expected)
	})

	it('writes only the header for no rows', () => {
		expect(toCsv([], ['a', 'b'])).toBe('a;b')
	})
})

describe('downloadCsv', () => {
	it('hands a UTF-8 CSV with a byte order mark to the browser under the file name', () => {
		downloadCsv('x.csv', rows, ['a', 'b', 'c'])
		expect(saved.parts).toEqual(['﻿' + expected])
		expect(saved.options).toEqual({ type: 'text/csv;charset=utf-8' })
		expect(saved.link.download).toBe('x.csv')
		expect(saved.link.href).toBe('blob:csv')
		expect(saved.link.click).toHaveBeenCalledTimes(1)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:csv')
	})
})

describe('the pages that download CSV', () => {
	it('blast results: the overview columns as blast-performance.csv', () => {
		const view = blastResults
		const sortedOverviewRows = [{ name: 'May "news"', segmentName: 'All', status: 'sent', sent: 10, openRate: 0.5, clickRate: null, unsubscribed: 1 }]
		view.methods.downloadOverview.call({ sortedOverviewRows })
		expect(saved.parts).toEqual(['﻿name;segmentName;status;sent;openRate;clickRate;unsubscribed\n"May ""news""";All;sent;10;0.5;;1'])
		expect(saved.options).toEqual({ type: 'text/csv;charset=utf-8' })
		expect(saved.link.download).toBe('blast-performance.csv')
		expect(saved.link.click).toHaveBeenCalledTimes(1)
	})

	it('search queries: the query columns as search-queries.csv', () => {
		const view = searchQueries
		const sortedRows = [{ query: 'a;b', clicks: 3, impressions: 40, ctr: 0.075, position: 2.1, pages: 'p\nq' }]
		view.methods.download.call({ sortedRows })
		expect(saved.parts).toEqual(['﻿query;clicks;impressions;ctr;position;pages\n"a;b";3;40;0.075;2.1;"p\nq"'])
		expect(saved.options).toEqual({ type: 'text/csv;charset=utf-8' })
		expect(saved.link.download).toBe('search-queries.csv')
		expect(saved.link.click).toHaveBeenCalledTimes(1)
	})
})
