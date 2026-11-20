/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Every stat tile that compares with the previous period says which way is
 * good and what to show when there is no earlier period to compare with.
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../../')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const en = JSON.parse(readFileSync(resolve(root, 'l10n/en.json'), 'utf8')).translations
const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations

const tiles = manifest.pages
	.flatMap((p) => p.config?.widgets ?? [])
	.filter((w) => w.type === 'stat' && w.content?.previousField)

describe('stat tiles with a previous period', () => {
	it('exist', () => {
		expect(tiles.map((w) => w.id)).toEqual(expect.arrayContaining(['revenue', 'won-value', 'win-rate', 'avg-deal-size']))
	})

	it('declare a good direction and the no-earlier-period text', () => {
		for (const w of tiles) {
			expect(['up', 'down'], w.id).toContain(w.content.goodDirection)
			expect(w.content.noPreviousText, w.id).toBe('No earlier period to compare with')
		}
	})

	it('have the text in en and nl', () => {
		expect(en['No earlier period to compare with']).toBe('No earlier period to compare with')
		expect(nl['No earlier period to compare with']).toBe('Geen eerdere periode om mee te vergelijken')
	})
})
