// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Z-report section's parts and the POS detail pages on the relation
 * frame (openspec/changes/screens-pos-details-board).
 */

import { describe, expect, it } from 'vitest'
import ZReportBookkeepingSection from '../../src/components/pos/ZReportBookkeepingSection.vue'
import manifest from '../../src/manifest.json'

const shows = (part, block) => ZReportBookkeepingSection.methods.shows.call({ part }, block)

describe('the Z-report section parts', () => {
	it('renders every block for all', () => {
		for (const block of ['bookkeeping', 'tax', 'payments']) {
			expect(shows('all', block)).toBe(true)
		}
	})

	it('renders one block for a part', () => {
		expect(shows('tax', 'tax')).toBe(true)
		expect(shows('tax', 'bookkeeping')).toBe(false)
		expect(shows('tax', 'payments')).toBe(false)
	})
})

describe('the POS detail pages', () => {
	it.each([
		['PosTransactionDetail', 'PosTransactions'],
		['CashShiftDetail', 'CashShifts'],
		['ZReportDetail', 'ZReports'],
	])('%s sits on the relation frame', (id, list) => {
		const config = manifest.pages.find((p) => p.id === id).config
		expect(config.breadcrumb.route).toBe(list)
		expect(config.titleField).toBe('reference')
		expect(config.layout).toHaveLength(1)
		const placed = new Set(config.widgets.map((w) => w.id))
		for (const side of config.sideColumn) {
			expect(placed.has(side), side).toBe(true)
		}
	})
})
