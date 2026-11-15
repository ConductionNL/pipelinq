/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Boards PqLoyaliteit ("Verdeling over niveaus"), PqOperationeel
 * ("Klantoverzicht"), PqOfferte ("Regels") and PqKassa ("Aankoopgeschiedenis",
 * the payments table) draw a sort and a filter button in every head cell of
 * their card tables, which makes the head row 42.5px instead of 37.5px.
 *
 * @spec openspec/changes/card-table-heads-sort-filter/specs/dashboard-widgets/spec.md#requirement-card-tables-sort-and-filter-from-their-heads
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
/**
 * @param {Array<string>} file The manifest file under src/.
 * @param {string} pageRoute The page's route.
 * @param {string} widgetId The widget's id.
 * @return {object} The widget.
 */
function widgetOf(file, pageRoute, widgetId) {
	const manifest = readJson('src', ...file)
	const page = manifest.pages.find((candidate) => candidate.route === pageRoute)
	return page.config.widgets.find((widget) => widget.id === widgetId)
}

describe('card tables that sort and filter from their heads', () => {
	it.each([
		[['manifest.d', '70-loyalty-program.json'], '/loyalty/reporting', 'loyalty-tier-table'],
		[['manifest.json'], '/operational', 'client-overview'],
		[['manifest.d', '82-quotes.json'], '/quotes/:id', 'quote-lines'],
	])('%s %s %s draws sort and filter buttons in every head', (file, route, id) => {
		const widget = widgetOf(file, route, id)
		expect(['object-table', 'object-list']).toContain(widget.type)
		expect(widget.content.sortable).toBe(true)
		expect(widget.content.filterable).toBe(true)
	})
})

describe('the till\'s purchase history and payments tables', () => {
	const source = fs.readFileSync(path.join(ROOT, 'src/views/pos/PosTransactionForm.vue'), 'utf8')
	const tables = [...source.matchAll(/<CnDataTable([^>]*)>/g)].map((match) => match[1])

	it('sorts and filters both tables from their heads, in the browser', () => {
		expect(tables).toHaveLength(2)
		for (const attrs of tables) {
			expect(attrs).toMatch(/\bfilterable\b/)
			expect(attrs).toMatch(/\blocalHeaders\b/)
		}
	})

	it.each(['historyColumns', 'paymentColumns'])('makes every %s column sortable', (computed) => {
		const body = source.match(new RegExp(`${computed}\\(\\) \\{([\\s\\S]*?)\\n\\t\\t\\},`))[1]
		const columns = body.match(/\{ key: /g).length
		expect(body.match(/sortable: true/g) || []).toHaveLength(columns)
	})
})
