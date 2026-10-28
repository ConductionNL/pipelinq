/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The newsletter results page drawn in the board look: header, tab panel,
 * tables and variant cards as boards PqNieuwsbriefPrestaties,
 * PqNieuwsbriefABTest and PqNieuwsbriefToeschrijving draw them, and the
 * site-traffic reads that fill the Toeschrijving tab.
 *
 * @spec openspec/changes/newsletter-results-board-look/specs/marketing/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { readTrafficRows } from '../../src/services/campaignTraffic.js'

const view = readFileSync(
	resolve(__dirname, '../../src/views/blasts/PerformanceDashboard.vue'),
	'utf8',
)
const style = view.slice(view.indexOf('<style'))

/**
 * The declarations of the first rule whose selector contains `selector`.
 *
 * @param {string} selector Part of the rule's selector.
 * @return {string}
 */
function rule(selector) {
	const at = style.indexOf(selector)
	if (at < 0) {
		return ''
	}
	return style.slice(style.indexOf('{', at) + 1, style.indexOf('}', at))
}

describe('the header in the board look', () => {
	it('links back to the blasts as the board\'s plain link, not a button', () => {
		expect(view).toMatch(/<router-link[\s\S]*?class="performance-dashboard__back"[\s\S]*?'Back to blasts'/)
		expect(rule('.performance-dashboard__back {')).toMatch(/font-weight: 600/)
	})

	it('ends the header 8px under its line, without the header\'s own bottom margin', () => {
		const header = rule('.cn-look-board :deep(.cn-page-header)')
		expect(header).toMatch(/padding-bottom: 8px/)
		expect(header).toMatch(/margin-bottom: 0/)
	})

	it('draws the header buttons at 40px', () => {
		expect(rule('.cn-look-board .performance-dashboard__header-actions :deep(.button-vue)')).toMatch(/min-height: 40px/)
	})
})

describe('the tab panel and its content in the board look', () => {
	it('draws the open panel as a white card joined to the tab', () => {
		const panel = rule('.cn-look-board :deep(.performance-dashboard__body)')
		expect(panel).toMatch(/background: var\(--color-main-background\)/)
		expect(panel).toMatch(/border-radius: 0 12px 12px 12px/)
		expect(panel).toMatch(/border: 1px solid var\(--color-border\)/)
	})

	it('draws the table head on the card\'s own ground, on a 1.2 line like the cells', () => {
		const head = rule('.cn-look-board .performance-dashboard__table th')
		expect(head).toMatch(/background: transparent/)
		expect(rule('.cn-look-board .performance-dashboard__table :is(th, td)')).toMatch(/line-height: 1.2/)
	})

	it('sets the variant title on a 1.2 line and the figures at the start', () => {
		expect(rule('.cn-look-board .performance-dashboard__ab-variant-title')).toMatch(/line-height: 1.2/)
		expect(rule('.performance-dashboard__ab-variant dt')).toMatch(/text-align: start/)
	})
})

describe('readTrafficRows', () => {
	const blasts = [{ id: 'a', name: 'A' }, { id: 'b', name: 'B' }, { id: 'c', name: 'C' }, { name: 'no id' }]

	it('reads the blasts a few at a time and keeps the list order', async () => {
		let inFlight = 0
		let peak = 0
		const delays = { a: 30, b: 5, c: 15 }
		const read = async (id) => {
			inFlight += 1
			peak = Math.max(peak, inFlight)
			await new Promise((done) => setTimeout(done, delays[id]))
			inFlight -= 1
			return { connected: true, campaign: `c-${id}`, email: { opened: 2, clicked: 1 }, site: { sessions: id === 'b' ? 0 : 3 } }
		}
		const result = await readTrafficRows(blasts, read, 2)
		expect(peak).toBe(2)
		expect(result.asked).toBe(3)
		expect(result.answered).toBe(3)
		expect(result.connected).toBe(true)
		expect(result.rows.map((row) => row.id)).toEqual(['a', 'c'])
		expect(result.rows[0]).toMatchObject({ name: 'A', campaign: 'c-a', opened: 2, clicked: 1, sessions: 3 })
	})

	it('stops asking once an answer says no portal is connected', async () => {
		const asked = []
		const read = async (id) => {
			asked.push(id)
			return { connected: false }
		}
		const result = await readTrafficRows(blasts, read, 1)
		expect(asked).toEqual(['a'])
		expect(result.connected).toBe(false)
		expect(result.rows).toEqual([])
	})

	it('counts a failed read as asked, not answered', async () => {
		const read = async () => {
			throw new Error('down')
		}
		const result = await readTrafficRows(blasts, read, 3)
		expect(result).toMatchObject({ asked: 3, answered: 0, connected: null, rows: [] })
	})
})
