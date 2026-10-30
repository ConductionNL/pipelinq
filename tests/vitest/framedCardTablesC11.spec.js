/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqKassabon "Regels", PqKassadienst "Afstortingen" and PqLead
 * "Producten" draw the card's list as a framed table: a 1px frame inside the
 * card, a white head with a rule, 18px outer insets. The lists ask for it with
 * `tableFrame: true` (nc-vue feat/pq-c11-object-list-table-frame). The receipt
 * lines read as plain text in the main ink, as the board draws them.
 *
 * @spec openspec/changes/pos-lead-framed-tables/specs/pos-lead-framed-tables/spec.md#requirement-the-pos-and-lead-card-lists-are-framed-tables
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id).config
const widget = (pageId, id) => page(pageId).widgets.find((w) => w.id === id)

describe('framed card tables on the POS and lead pages', () => {
	it('frames the receipt lines and reads them as plain text', () => {
		const lines = widget('PosTransactionDetail', 'pos-tx-lines').content
		expect(lines.tableFrame).toBe(true)
		expect(lines.rowTitle).toBe('text')
	})

	it('frames the shift drops', () => {
		expect(widget('CashShiftDetail', 'shift-drops').content.tableFrame).toBe(true)
	})

	it('frames the lead products', () => {
		const products = page('LeadDetail').widgets.find((w) => w.content && w.content.addLabel === 'Add product')
		expect(products.content.tableFrame).toBe(true)
	})
})
