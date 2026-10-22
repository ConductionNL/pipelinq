// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four fixes that were tested on components the PL4 lane turned into
 * configuration: the Find client widget searching on the server, the Start
 * request widget storing a category's stable value, an overdue lead marked on
 * its row, and the pipeline list sorted by the library's index table (whose
 * sort headers are keyboard buttons with aria-sort in nextcloud-vue). This file
 * pins the config those behaviours now live in.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const indexPage = (id) => manifest.pages.find((p) => p.id === id && p.type === 'index').config
const widgets = read('src', 'ncDashboardWidgets.js')

describe('Nextcloud Dashboard widgets', () => {
	it('find client searches the server, not a first page filtered in the browser', () => {
		const block = widgets.slice(widgets.indexOf("id: 'pipelinq_find_client_widget'"))
		expect(block).toMatch(/source: \{ register: 'pipelinq', schema: 'client' \}/)
		expect(block).toMatch(/search: \{ label: 'Search'/)
	})

	it('start request stores the category value, not its translated label', () => {
		for (const value of ['general', 'technical', 'billing', 'support']) {
			expect(widgets).toContain(`{ value: '${value}', label: `)
		}
	})
})

describe('the lead list', () => {
	it('marks an open lead past its close date', () => {
		expect(indexPage('Leads').rowClass).toEqual([
			{ when: [{ field: 'expectedCloseDate', op: 'lt', value: '@today' }, { field: 'status', value: 'open' }], class: 'lead-overdue' },
		])
	})
})

describe('the pipeline list', () => {
	it('is the board and the library index table, so its sort headers are the library\'s', () => {
		expect(indexPage('Pipeline').viewModes).toEqual(['board', 'table'])
	})
})
