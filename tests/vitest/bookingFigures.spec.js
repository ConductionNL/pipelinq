/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The busy figures and the match line of appointment services and
 * resources: the resource page's week figure, what a service asks for, and
 * the services list's match line cell.
 *
 * @spec openspec/changes/services-and-resources-at-a-glance/specs/appointment-booking/spec.md#requirement-each-service-shows-the-resources-that-match-it-req-srg-002
 */

import { describe, expect, it, vi } from 'vitest'

const fill = (text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => fill(text, vars),
	translatePlural: (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count)),
}))
const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { createMatchLineFormatter, fetchMatchCounts, matchLine, requirementText, weekLoadOf } = await import('../../src/services/bookingFigures.js')

const PASSPORT = { id: 'svc-passport', requiredSkills: ['civil affairs'], requiredResourceTypes: ['room'] }

/** Let the formatter's pending read settle. */
const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('weekLoadOf', () => {
	it('reads the count and the share the server computed', () => {
		expect(weekLoadOf({ bookingsThisWeek: 22, bookedShare: 74 })).toEqual({ count: 22, percent: 74 })
	})

	it('keeps the count without a share when there is no bookable time', () => {
		expect(weekLoadOf({ bookingsThisWeek: 3, bookedShare: null })).toEqual({ count: 3, percent: null })
	})

	it('reads nothing as zero without a share', () => {
		expect(weekLoadOf(null)).toEqual({ count: 0, percent: null })
		expect(weekLoadOf({ bookingsThisWeek: 'x', bookedShare: '74' })).toEqual({ count: 0, percent: null })
	})
})

describe('requirementText', () => {
	it('names one skill and the resource types', () => {
		expect(requirementText(PASSPORT)).toBe('Skill civil affairs, a room')
	})

	it('names several skills together, skipping blanks and non-strings', () => {
		expect(requirementText({ requiredSkills: ['cut', ' ', 'color', 7], requiredResourceTypes: ['staff', 'equipment'] }))
			.toBe('Skills cut, color, a staff member, equipment')
	})

	it('is empty for a service that asks for nothing', () => {
		expect(requirementText({ requiredSkills: 'cut' })).toBe('')
		expect(requirementText(null)).toBe('')
	})
})

describe('matchLine', () => {
	it('reads the requirement and the number of matching resources', () => {
		expect(matchLine(PASSPORT, 4)).toBe('Skill civil affairs, a room · 4 resources match')
		expect(matchLine(PASSPORT, 1)).toBe('Skill civil affairs, a room · 1 resource matches')
	})

	it('says when no resource matches', () => {
		expect(matchLine({ requiredSkills: ['registrar'] }, 0)).toBe('Skill registrar · No resource matches')
		expect(matchLine({}, 0)).toBe('No resource matches')
	})
})

describe('createMatchLineFormatter', () => {
	it('shows the pending mark, then the line from one read of all counts', async () => {
		const fetchCounts = vi.fn().mockResolvedValue({ 'svc-passport': 4 })
		const format = createMatchLineFormatter({ fetchCounts })

		expect(format(PASSPORT.requiredSkills, PASSPORT)).toBe('…')
		expect(format([], { id: 'svc-other' })).toBe('…')
		await settle()

		expect(format(PASSPORT.requiredSkills, PASSPORT)).toBe('Skill civil affairs, a room · 4 resources match')
		expect(format([], { '@self': { id: 'svc-other' } })).toBe('No resource matches')
		expect(fetchCounts).toHaveBeenCalledTimes(1)
	})

	it('reads again once the counts are older than the maximum age', async () => {
		let now = 1000
		const fetchCounts = vi.fn().mockResolvedValueOnce({ 'svc-passport': 1 }).mockResolvedValueOnce({ 'svc-passport': 2 })
		const format = createMatchLineFormatter({ fetchCounts, maxAgeMs: 100, clock: () => now })

		format(null, PASSPORT)
		await settle()
		now = 1050
		expect(format(null, PASSPORT)).toBe('Skill civil affairs, a room · 1 resource matches')
		now = 1200
		format(null, PASSPORT)
		await settle()

		expect(format(null, PASSPORT)).toBe('Skill civil affairs, a room · 2 resources match')
		expect(fetchCounts).toHaveBeenCalledTimes(2)
	})

	it('reads a failed read as no matches rather than pending forever', async () => {
		const format = createMatchLineFormatter({ fetchCounts: vi.fn().mockRejectedValue(new Error('down')) })

		format(null, PASSPORT)
		await settle()

		expect(format(null, PASSPORT)).toBe('Skill civil affairs, a room · No resource matches')
	})
})

describe('fetchMatchCounts', () => {
	it('reads the counts of every service from the eligible-resources endpoint', async () => {
		axiosGet.mockResolvedValueOnce({ data: { counts: { 'svc-passport': 4 } } })

		await expect(fetchMatchCounts()).resolves.toEqual({ 'svc-passport': 4 })
		expect(axiosGet).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/appointment-services/eligible-resources')
	})

	it('reads an answer without counts as none', async () => {
		axiosGet.mockResolvedValueOnce({ data: {} })

		await expect(fetchMatchCounts()).resolves.toEqual({})
	})
})
