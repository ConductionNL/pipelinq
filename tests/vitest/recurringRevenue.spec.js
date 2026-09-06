// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Unit tests for the client-side recurring-revenue helpers
// (contract-renewal-tracking). Mirrors the PHP RecurringRevenueService tests.

import { describe, expect, it } from 'vitest'
import {
	computeArr,
	computeClientMrr,
	computeMrr,
	normalizeToMonthly,
} from '../../src/services/recurringRevenue.js'

describe('recurringRevenue', () => {
	it('normalizes intervals to monthly (one-off excluded)', () => {
		expect(normalizeToMonthly('monthly', 750)).toBe(750)
		expect(normalizeToMonthly('quarterly', 3000)).toBe(1000)
		expect(normalizeToMonthly('annual', 12000)).toBe(1000)
		expect(normalizeToMonthly('one-off', 5000)).toBe(0)
	})

	it('computes MRR and ARR over active+expiring contracts only', () => {
		const contracts = [
			{ status: 'active', billingInterval: 'monthly', valuePerInterval: 750 },
			{
				status: 'active',
				billingInterval: 'quarterly',
				valuePerInterval: 3000,
			},
			{
				status: 'expiring',
				billingInterval: 'annual',
				valuePerInterval: 12000,
			},
			{ status: 'active', billingInterval: 'one-off', valuePerInterval: 5000 },
			{ status: 'draft', billingInterval: 'monthly', valuePerInterval: 999 },
			{ status: 'churned', billingInterval: 'monthly', valuePerInterval: 999 },
		]
		expect(computeMrr(contracts)).toBe(2750)
		expect(computeArr(contracts)).toBe(33000)
	})

	it('computes per-client MRR', () => {
		const contracts = [
			{
				status: 'active',
				clientRef: 'c1',
				billingInterval: 'monthly',
				valuePerInterval: 750,
			},
			{
				status: 'active',
				clientRef: 'c1',
				billingInterval: 'annual',
				valuePerInterval: 12000,
			},
			{
				status: 'active',
				clientRef: 'c2',
				billingInterval: 'monthly',
				valuePerInterval: 500,
			},
		]
		expect(computeClientMrr(contracts, 'c1')).toBe(1750)
		expect(computeClientMrr(contracts, 'c2')).toBe(500)
	})

	it('handles empty / missing contract sets', () => {
		expect(computeMrr([])).toBe(0)
		expect(computeMrr(null)).toBe(0)
		expect(computeArr(undefined)).toBe(0)
	})
})
