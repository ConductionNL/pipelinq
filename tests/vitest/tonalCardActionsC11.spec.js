/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The light-blue card pills boards PqKassadienst and PqLead draw: the cash
 * difference's Approve and Reject, the drops card's Add drop, and the lead's
 * Add product. The facts actions take `variant: "tonal"` (nc-vue >= 2.160.0),
 * the object list `addVariant: "tonal"` (nc-vue feat/board-tonal-card-actions),
 * and the drops card's own button the shared `cn-button--tonal` class.
 *
 * @spec openspec/changes/tonal-card-actions-pos-and-lead/specs/tonal-card-actions-pos-and-lead/spec.md#requirement-card-actions-on-the-shift-and-lead-pages-are-tonal-pills
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const page = (id) => manifest.pages.find((p) => p.id === id).config
const widget = (pageId, id) => page(pageId).widgets.find((w) => w.id === id)

describe('tonal card actions on the shift and lead pages', () => {
	it('draws the cash difference Approve and Reject as tonal pills', () => {
		const actions = widget('CashShiftDetail', 'shift-variance').content.actions
		expect(actions.map((a) => [a.id, a.variant])).toEqual([['diff-approve', 'tonal'], ['diff-reject', 'tonal']])
	})

	it('draws the drops card Add drop as a tonal pill', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'components', 'pos', 'CashShiftDropsWidget.vue'), 'utf8')
		const button = source.slice(source.indexOf('<NcButton'), source.indexOf('</NcButton>'))
		expect(button).toMatch(/class="cn-button--tonal"/)
	})

	it('draws the lead products Add product as a tonal pill', () => {
		const products = page('LeadDetail').widgets.find((w) => w.content && w.content.addLabel === 'Add product')
		expect(products.content.addVariant).toBe('tonal')
	})
})
