/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketContactmomenten draws a sort and a filter button in every head
 * of the ticket's contact moments table (head row 42.5, live 37.5, pl8 4).
 * Board PqZRapport draws the VAT and payment tables at 14px on the normal
 * line, 12px row padding with 18px outer insets, and a rule under every row
 * (pl8 7/8: live 15px on a 1.5 line with a rule between rows only). Read as
 * text.
 *
 * @spec openspec/changes/card-table-heads-contacts-zreport/specs/dashboard-widgets/spec.md#requirement-the-ticket-contact-moments-table-sorts-and-filters-from-its-heads
 * @spec openspec/changes/card-table-heads-contacts-zreport/specs/dashboard-widgets/spec.md#requirement-the-z-report-tables-draw-the-board-table-rows
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (file) => readFileSync(resolve(__dirname, '../../src', file), 'utf8')

/**
 * Every object in a JSON tree with this id.
 *
 * @param {unknown} node The tree.
 * @param {string} id The id.
 * @return {Array<object>} The matches.
 */
function findById(node, id) {
	if (!node || typeof node !== 'object') {
		return []
	}
	const own = node.id === id ? [node] : []
	return own.concat(...Object.values(node).map((child) => findById(child, id)))
}

describe('the ticket contact moments table', () => {
	it('sorts and filters from its heads', () => {
		const [widget] = findById(JSON.parse(read('menu-layout.simple.json')), 'ticket-contactmoments')
		expect(widget.type).toBe('object-list')
		expect(widget.content.sortable).toBe(true)
		expect(widget.content.filterable).toBe(true)
	})
})

describe('the z-report tables', () => {
	const style = read('components/pos/ZReportBookkeepingWidget.vue').split('<style')[1]
	const rule = (opener) => {
		const at = style.lastIndexOf(opener)
		return at === -1 ? '' : style.slice(at, style.indexOf('}', at))
	}

	it('draws 14px text on the normal line', () => {
		const table = rule('.z-bookkeeping__table {')
		expect(table).toContain('font-size: 14px;')
		expect(table).toContain('line-height: normal;')
	})

	it('pads a row 12px with 18px outer insets, a head 10px', () => {
		expect(rule('.z-bookkeeping__table td {')).toContain('padding: 12px;')
		expect(rule('.z-bookkeeping__table th {')).toContain('padding: 10px 12px;')
		expect(rule('.z-bookkeeping__table :is(th, td):first-child {')).toContain('padding-inline-start: 18px;')
		expect(rule('.z-bookkeeping__table :is(th, td):last-child {')).toContain('padding-inline-end: 18px;')
	})

	it('rules under every row, not between rows', () => {
		expect(rule('.z-bookkeeping__table td {')).toContain('border-bottom: 1px solid var(--color-border);')
		expect(style).not.toContain('tbody tr + tr td')
	})
})
