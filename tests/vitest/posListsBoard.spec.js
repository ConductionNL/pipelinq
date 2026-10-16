// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The point of sale lists as the PqKassabonnen and PqZRapporten boards draw
 * them (openspec/changes/screens-pos-lists-board).
 */

import { describe, expect, it } from 'vitest'
import overlay from '../../lib/Settings/register.d/99-zz-pos-board.json'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'

describe('the transactions list', () => {
	const config = simple.pages.find((p) => p.id === 'PosTransactions').config

	it('has the board chips with counts', () => {
		expect(config.quickFilters.map((q) => q.label)).toEqual(['All', 'Today', 'Parked', 'Refunded'])
		expect(config.quickFilters.every((q) => q.showCount)).toBe(true)
	})

	it('ends on the created time', () => {
		expect(config.columns.at(-1).key).toBe('@self.created')
	})
})

describe('the Z-reports list', () => {
	const page = manifest.pages.find((p) => p.id === 'ZReports')

	it('is titled Z-reports with the board chips', () => {
		expect(page.title).toBe('Z-reports')
		expect(page.config.quickFilters.map((q) => q.label)).toEqual(['All', 'Not booked', 'Failed'])
	})

	it('labels every status the column can show', () => {
		const labels = overlay.components.schemas.posZReport.properties.status['x-enum-labels']
		for (const value of Object.keys(page.config.columns.find((c) => c.key === 'status').widgetProps.colorMap)) {
			expect(labels[value], value).toBeDefined()
		}
	})
})
