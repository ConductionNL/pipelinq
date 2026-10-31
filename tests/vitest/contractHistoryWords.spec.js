/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqContract: the History cards say what happened in words, as the Dq
 * history cards do ("Status gewijzigd naar Verloopt binnenkort",
 * "Verlengingslead aangemaakt"); the header action reads "Naar facturering
 * sturen"; the renewal lead button in the next-step card has no icon.
 *
 * @spec openspec/changes/contract-history-and-invoicing-words/specs/contract-detail/spec.md#requirement-the-contract-history-says-what-happened-in-words
 * @spec openspec/changes/contract-history-and-invoicing-words/specs/contract-detail/spec.md#requirement-the-contract-actions-read-as-the-board
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const fragment = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.d', '96-contracts.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations
const nlJs = fs.readFileSync(path.join(ROOT, 'l10n', 'nl.js'), 'utf8')
const page = fragment.pages.find((p) => p.id === 'ContractDetail')
const widget = (id) => page.config.widgets.find((w) => w.id === id)
const action = (id) => page.config.headerActions.find((a) => a.id === id)

/**
 * The label a status change gets: the status rule with the new value in words.
 *
 * @param {object} content The card's content.
 * @param {string} to The status reached.
 * @return {string} The Dutch label.
 */
function statusLine(content, to) {
	const rule = content.changeLabels.find((c) => c.field === 'status')
	return nl[rule.label].replace('{changed.status}', nl[content.valueLabels.status[to]])
}

describe('contract history in words', () => {
	for (const id of ['side-history', 'contract-history']) {
		const content = widget(id).content

		it(`${id}: stacked and compact, as the Dq history`, () => {
			expect(content.layout).toBe('stacked')
			expect(content.density).toBe('compact')
			expect(content.excludeActions).toEqual(['read'])
		})

		it(`${id}: names the events as the board does`, () => {
			expect(statusLine(content, 'expiring')).toBe('Status gewijzigd naar Verloopt binnenkort')
			const lead = content.changeLabels.find((c) => c.field === 'renewalLeadRef')
			expect(nl[lead.label]).toBe('Verlengingslead aangemaakt')
			const invoice = content.changeLabels.find((c) => c.field === 'invoiceReference')
			expect(nl[invoice.label]).toBe('Naar facturering gestuurd, {changed.invoiceReference}')
			expect(nl[content.actionLabels.create]).toBe('Aangemaakt door {actor}')
			expect(nl[content.actionLabels.update]).toBe('Bijgewerkt')
		})

		it(`${id}: has Dutch words for every status`, () => {
			for (const word of Object.values(content.valueLabels.status)) {
				expect(nl[word], word).toBeTruthy()
			}
		})
	}

	it('keeps the side card to four rows', () => {
		expect(widget('side-history').content.limit).toBe(4)
	})
})

describe('contract actions as the board', () => {
	it('reads Send to invoicing as "Naar facturering sturen"', () => {
		expect(action('send-to-invoicing').label).toBe('Send to invoicing')
		expect(nl['Send to invoicing']).toBe('Naar facturering sturen')
		expect(nlJs).toContain('"Send to invoicing": "Naar facturering sturen"')
	})

	it('draws the renewal lead button without an icon', () => {
		expect(action('open-renewal-lead').icon).toBeUndefined()
	})
})
