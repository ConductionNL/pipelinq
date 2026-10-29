/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * In a call the contact centre page draws board PqWerkplekKlant's three
 * columns: who is it on the left, this contact moment and the client's open
 * requests in the middle, the knowledge base, tasks and callback requests
 * and earlier contact on the right. Without a call the page keeps its own
 * dashboard layout.
 *
 * @spec openspec/changes/werkplek-in-call-columns/specs/kcc-werkplek/spec.md#requirement-the-in-call-page-draws-three-columns
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const page = simple.pages.find((p) => p.id === 'KccWerkplek')

describe('werkplek in-call layout (PqWerkplekKlant)', () => {
	const entry = (page.config.layoutWhen || [])[0]

	it('switches layout while a call is on', () => {
		expect(entry.when).toEqual({ workspace: 'callActive', op: 'eq', value: true })
	})

	it('puts each in-call card in the board\'s column, top to bottom', () => {
		const cards = entry.layout.filter((item) => item.widgetId !== 'simple-call-actions')
		const column = (x) => cards
			.filter((item) => item.gridX === x)
			.sort((a, b) => a.gridY - b.gridY)
			.map((item) => item.widgetId)
		expect(column(0)).toEqual(['simple-call-who'])
		expect(column(4)).toEqual(['interaction', 'client-cases'])
		expect(column(8)).toEqual(['knowledge', 'simple-call-tasks', 'client-contacts'])
		for (const item of cards) {
			expect(item.gridWidth, item.widgetId).toBe(4)
		}
	})

	it('places no two in-call cards on the same cell', () => {
		const taken = new Set()
		for (const item of entry.layout) {
			for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
				for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
					expect(taken.has(`${x},${y}`), item.widgetId).toBe(false)
					taken.add(`${x},${y}`)
				}
			}
		}
	})

	it('keeps the page layout without a call', () => {
		expect(page.config.layout.some((item) => item.widgetId === 'simple-first-today')).toBe(true)
	})
})
