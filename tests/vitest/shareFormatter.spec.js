// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The share formatter: the newsletter list's open and click columns.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */

import { describe, expect, it } from 'vitest'
import { share } from '../../src/services/shareFormatter.js'

describe('share formatter', () => {
	const row = { totals: { opened: 71, delivered: 119, clicked: 0 } }

	it('reads a count as a whole percentage of the base on the row', () => {
		expect(share(71, row, null, { over: 'totals.delivered' })).toBe('60%')
	})

	it('shows 0% for a measured zero', () => {
		expect(share(0, row, null, { over: 'totals.delivered' })).toBe('0%')
	})

	it('is empty without a base, or with a base of zero', () => {
		expect(share(5, row, null, {})).toBe('')
		expect(share(5, { totals: { delivered: 0 } }, null, { over: 'totals.delivered' })).toBe('')
	})

	it('is empty without a count', () => {
		expect(share(null, row, null, { over: 'totals.delivered' })).toBe('')
	})
})
