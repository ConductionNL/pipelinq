/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * In a call board PqWerkplekKlant draws its three columns 332 / 415 / 332
 * wide, not three equal shares. The in-call layout weighs its column groups
 * (nextcloud-vue dashboard-column-weights); the page's own layout keeps the
 * twelve shares.
 *
 * @spec openspec/changes/werkplek-in-call-column-weights/specs/kcc-werkplek/spec.md#requirement-the-in-call-columns-are-drawn-at-the-boards-widths
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const simple = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const page = simple.pages.find((p) => p.id === 'KccWerkplek')

describe('werkplek in-call column widths (PqWerkplekKlant)', () => {
	const entry = (page.config.layoutWhen || [])[0]

	it('weighs the three column groups as the board draws them, card plus its two 10px margins', () => {
		expect(entry.gridColumnWeights).toEqual([
			{ span: 4, weight: 352 },
			{ span: 4, weight: 435 },
			{ span: 4, weight: 352 },
		])
	})

	it('keeps the twelve shares outside a call', () => {
		expect(page.config.gridColumnWeights).toBeUndefined()
	})
})
