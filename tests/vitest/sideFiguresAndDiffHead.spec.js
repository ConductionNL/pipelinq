/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqKassadienst and PqLead as configuration: the opening float and the
 * win chance stand as 26px figures in their side cards, and the cash
 * difference card carries the "Afstemming" line under its title with no
 * pill while the difference waits for a manager.
 *
 * @spec openspec/specs/side-figures-and-diff-head/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const en = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'en.json'), 'utf8')).translations
const page = (id) => manifest.pages.find((p) => p.id === id).config
const widget = (pageId, id) => page(pageId).widgets.find((w) => w.id === id)
const titleOf = (w) => w.content.fields.find((f) => f.as === 'title')

describe('side figures and the cash difference head', () => {
	it('sets the opening float as the board figure', () => {
		expect(titleOf(widget('CashShiftDetail', 'shift-opening')).size).toBe(26)
	})

	it('sets the win chance as the board figure', () => {
		expect(titleOf(widget('LeadDetail', 'side-win-chance')).size).toBe(26)
	})

	it('names the cash difference card with the board subtitle', () => {
		const variance = widget('CashShiftDetail', 'shift-variance')
		expect(nl[variance.subtitle]).toBe('Afstemming')
		expect(en[variance.subtitle]).toBe(variance.subtitle)
	})

	it('draws no header pill while the difference waits, and keeps the outcome pills', () => {
		const pill = widget('CashShiftDetail', 'shift-variance').content.headerPill
		expect(pill.map.pending).toBe('')
		expect(nl[pill.map.approved]).toBe('Goedgekeurd')
		expect(nl[pill.map.rejected]).toBe('Afgewezen')
	})
})
