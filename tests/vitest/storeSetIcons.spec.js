/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqStore: every store card draws its icon chip (columns, headset, cash
 * register, cube, star). The Zuiddrecht set named concepts ("board",
 * "queue") that no icon registry answers, so every card drew the "?" fallback.
 * Each store item's icon is a name src/icons.js registers.
 *
 * @spec exclude board measure (pl3 PqStore card icons), example-set data
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const icons = fs.readFileSync(path.join(ROOT, 'src', 'icons.js'), 'utf8')
const set = JSON.parse(fs.readFileSync(path.join(ROOT, 'lib', 'Settings', 'example-sets', 'zuiddrecht.d', '73-store.json'), 'utf8'))
const items = set.objects.filter((o) => o.schema === 'commercial-template')

describe('store set icons resolve', () => {
	it('names a registered icon on every store item', () => {
		expect(items.length).toBeGreaterThan(5)
		for (const item of items) {
			expect(icons, `${item.key}: ${item.data.icon}`).toContain(`\n\t${item.data.icon},\n`)
		}
	})

	it('draws the board icons on the first cards', () => {
		const icon = (key) => items.find((item) => item.key === key).data.icon
		expect(icon('store-b2b-sales-pipeline')).toBe('ViewColumnOutline')
		expect(icon('store-service-desk-routing')).toBe('Headset')
	})
})
