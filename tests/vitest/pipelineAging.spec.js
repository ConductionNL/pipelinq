// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Lead aging and overdue helpers: the stale threshold, the overdue day count,
 * the aging class and the age label.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => 'tr:' + text }))

const utils = await import('../../src/services/pipelineUtils.js')

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(new Date('2026-06-15T12:00:00Z'))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('getStaleThreshold', () => {
	it('reads a positive configured value', () => {
		expect(utils.getStaleThreshold({ lead_stale_threshold_days: '21' })).toBe(21)
	})

	it('falls back to 14 for missing, zero, negative or unparseable values', () => {
		expect(utils.getStaleThreshold(null)).toBe(14)
		expect(utils.getStaleThreshold({})).toBe(14)
		expect(utils.getStaleThreshold({ lead_stale_threshold_days: '0' })).toBe(14)
		expect(utils.getStaleThreshold({ lead_stale_threshold_days: -3 })).toBe(14)
		expect(utils.getStaleThreshold({ lead_stale_threshold_days: 'soon' })).toBe(14)
	})
})

describe('isStale', () => {
	it('only ages leads', () => {
		const old = { _dateModified: '2026-05-01T12:00:00Z' }
		expect(utils.isStale(old, 'lead')).toBe(true)
		expect(utils.isStale(old, 'request')).toBe(false)
	})

	it('compares the age to the threshold inclusively', () => {
		const tenDays = { _dateModified: '2026-06-05T12:00:00Z' }
		expect(utils.isStale(tenDays, 'lead', 10)).toBe(true)
		expect(utils.isStale(tenDays, 'lead', 11)).toBe(false)
		expect(utils.isStale({}, 'lead', 1)).toBe(false)
	})
})

describe('getOverdueDays', () => {
	it('counts whole days past the expected close date', () => {
		expect(utils.getOverdueDays({ expectedCloseDate: '2026-06-10T00:00:00Z', stage: 'open' })).toBe(5)
	})

	it('is zero for a lead that is not overdue', () => {
		expect(utils.getOverdueDays({ expectedCloseDate: '2026-07-01' })).toBe(0)
		expect(utils.getOverdueDays({ expectedCloseDate: '2026-06-01', status: 'won' })).toBe(0)
		expect(utils.getOverdueDays({ expectedCloseDate: '2026-06-01', stage: 'Done' }, [{ name: 'Done', isClosed: true }])).toBe(0)
		expect(utils.getOverdueDays(null)).toBe(0)
	})

	it('is zero when less than a day has passed', () => {
		expect(utils.getOverdueDays({ expectedCloseDate: '2026-06-15T06:00:00Z' })).toBe(0)
	})
})

describe('getAgingClass and formatAge', () => {
	it('escalates at one and two weeks', () => {
		expect(utils.getAgingClass(6)).toBe('')
		expect(utils.getAgingClass(7)).toBe('aging-warning')
		expect(utils.getAgingClass(13)).toBe('aging-warning')
		expect(utils.getAgingClass(14)).toBe('aging-alert')
	})

	it('labels today, one day and many days', () => {
		expect(utils.formatAge(0)).toBe('tr:Today')
		expect(utils.formatAge(1)).toBe('1d')
		expect(utils.formatAge(12)).toBe('12d')
	})
})
