/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The side History cards of a POS receipt (PqKassabon) and a cash shift
 * (PqKassadienst) say what happened in words, as the Dq history cards do
 * (`actionLabels`, `changeLabels`, stacked and compact): "Aangemaakt door
 * Pieter Jansen", "Bevestigd", "Afgerekend", "Gesloten en geteld", not the
 * raw "update" with "System" under it.
 *
 * @spec openspec/changes/pos-history-in-words/specs/pos-history-in-words/spec.md#requirement-the-pos-side-history-says-what-happened-in-words
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const nlJs = fs.readFileSync(path.join(ROOT, 'l10n', 'nl.js'), 'utf8')
const widget = (pageId, id) => manifest.pages.find((p) => p.id === pageId).config.widgets.find((w) => w.id === id)
/**
 * Every word a history card declares.
 *
 * @param {object} content The card's content.
 * @return {Array<string>} The labels.
 */
function words(content) {
	return [...Object.values(content.actionLabels), ...content.changeLabels.map((c) => c.label)]
}

/**
 * The label for a status change.
 *
 * @param {object} content The card's content.
 * @param {string} to The status reached.
 * @return {string} The label.
 */
function statusLabel(content, to) {
	return content.changeLabels.find((c) => c.field === 'status' && c.to === to).label
}

describe('POS side history in words', () => {
	const receipt = widget('PosTransactionDetail', 'pos-tx-side-history').content
	const shift = widget('CashShiftDetail', 'shift-side-history').content

	it('draws both cards stacked and compact, as the Dq side history', () => {
		for (const content of [receipt, shift]) {
			expect(content.layout).toBe('stacked')
			expect(content.density).toBe('compact')
		}
	})

	it('names the receipt events as the board does', () => {
		expect(nl[receipt.actionLabels.create]).toBe('Aangemaakt door {actor}')
		expect(nl[receipt.actionLabels.update]).toBe('Bijgewerkt')
		expect(nl[statusLabel(receipt, 'confirmed')]).toBe('Bevestigd')
		expect(nl[statusLabel(receipt, 'settled')]).toBe('Afgerekend')
		expect(nl[statusLabel(receipt, 'refunded')]).toBe('Terugbetaald')
	})

	it('names the shift events as the board does', () => {
		expect(nl[shift.actionLabels.create]).toBe('Geopend door {actor}')
		expect(nl[shift.actionLabels.update]).toBe('Bijgewerkt')
		expect(nl[statusLabel(shift, 'closed')]).toBe('Gesloten en geteld')
		expect(nl[statusLabel(shift, 'reconciled')]).toBe('Afgestemd')
	})

	it('has every word in both Dutch catalogues', () => {
		for (const text of [...words(receipt), ...words(shift)]) {
			expect(nl[text], text).toBeTruthy()
			expect(nlJs).toContain(`"${text}": "${nl[text]}"`)
		}
	})
})
