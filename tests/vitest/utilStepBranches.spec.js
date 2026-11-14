/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of six helpers pinned before they were split into named
 * steps: the due state, the analytics period, the keyword confirm body,
 * the response-rate request, the comparison date and the contact
 * write-back.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))

const { dueState } = await import('../../src/utils/dueState.js')
const { rangeToPeriod } = await import('../../src/services/analyticsPeriod.js')
const { confirmPayload } = await import('../../src/services/keywordIntel.js')
const { fetchResponseRate } = await import('../../src/services/satisfactionApi.js')
const { formatComparedOn, labelFor } = await import('../../src/utils/capabilityComparison.js')
const { writeBack } = await import('../../src/services/contactSyncApi.js')

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('dueState', () => {
	// Wednesday 2026-06-10, mid-day local time.
	const now = new Date(2026, 5, 10, 12, 0, 0)

	it('reads every state', () => {
		expect(dueState(null, now)).toEqual({ state: null, daysLate: 0 })
		expect(dueState('', now)).toEqual({ state: null, daysLate: 0 })
		expect(dueState('nope', now)).toEqual({ state: null, daysLate: 0 })
		expect(dueState(new Date(2026, 5, 7, 23, 0), now)).toEqual({ state: 'overdue', daysLate: 3 })
		expect(dueState(new Date(2026, 5, 10, 8, 0), now)).toEqual({ state: 'today', daysLate: 0 })
		expect(dueState(new Date(2026, 5, 14, 20, 0), now)).toEqual({ state: 'week', daysLate: 0 })
		expect(dueState(new Date(2026, 5, 15, 1, 0), now)).toEqual({ state: 'later', daysLate: 0 })
		const sunday = new Date(2026, 5, 14, 12, 0, 0)
		expect(dueState(new Date(2026, 5, 15, 9, 0), sunday)).toEqual({ state: 'later', daysLate: 0 })
	})
})

describe('rangeToPeriod', () => {
	it('maps a preset, a span or nothing to a period', () => {
		expect(rangeToPeriod(null)).toBe('month')
		for (const preset of ['week', 'month', 'quarter', 'year']) {
			expect(rangeToPeriod({ preset })).toBe(preset)
		}
		expect(rangeToPeriod({ preset: 'custom', from: 'x', to: '2026-01-02' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-01', to: 'x' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-02', to: '2026-01-01' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-01-01' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-01-11' })).toBe('week')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-01-12' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-02-15' })).toBe('month')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-02-16' })).toBe('quarter')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-06-30' })).toBe('quarter')
		expect(rangeToPeriod({ from: '2026-01-01', to: '2026-07-01' })).toBe('year')
	})
})

describe('confirmPayload', () => {
	it('builds the body with known values and fallbacks', () => {
		expect(confirmPayload(
			{ query: ' crm ', term: 'x', topPage: '/a' },
			{ status: 'use-more', intent: 'buy', targetPageRef: '/b', property: 'site', notes: 'n' },
			'striking-distance',
		)).toEqual({
			term: 'crm', status: 'use-more', proposalKind: 'striking-distance', intent: 'buy', targetPageRef: '/b', property: 'site', notes: 'n',
		})
		expect(confirmPayload({ term: 'erp', topPage: '/a' }, { status: 'drop' }, 'odd')).toEqual({
			term: 'erp', status: 'watch', proposalKind: 'manual', intent: '', targetPageRef: '/a', property: '', notes: '',
		})
		expect(confirmPayload(null, null, undefined)).toEqual({
			term: '', status: 'watch', proposalKind: 'manual', intent: '', targetPageRef: '', property: '', notes: '',
		})
		expect(Object.keys(confirmPayload({}, {}, 'manual'))).toEqual(['term', 'status', 'proposalKind', 'intent', 'targetPageRef', 'property', 'notes'])
	})
})

describe('fetchResponseRate', () => {
	it('asks with the filters given and reads an object answer', async () => {
		http.get.mockResolvedValue({ data: { rate: 0.4 } })
		expect(await fetchResponseRate({ days: 30, surveyId: 's1' })).toEqual({ rate: 0.4 })
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/satisfaction/response-rate', { params: { days: 30, surveyId: 's1' } })
		await fetchResponseRate()
		expect(http.get).toHaveBeenLastCalledWith('/apps/pipelinq/api/satisfaction/response-rate', { params: {} })
		await fetchResponseRate({ days: -1, surveyId: '' })
		expect(http.get).toHaveBeenLastCalledWith('/apps/pipelinq/api/satisfaction/response-rate', { params: {} })
		http.get.mockResolvedValue({ data: 'x' })
		expect(await fetchResponseRate({})).toEqual({})
		http.get.mockResolvedValue({ data: null })
		expect(await fetchResponseRate({})).toEqual({})
	})
})

describe('formatComparedOn', () => {
	it('writes the day out, or keeps what it cannot read', () => {
		expect(formatComparedOn('2026-03-05')).toBe('March 5, 2026')
		expect(formatComparedOn('2026-03-05', 'nl_NL')).toBe('5 maart 2026')
		expect(formatComparedOn('', 'en')).toBe('')
		expect(formatComparedOn('   ', 'en')).toBe('')
		expect(formatComparedOn(null, 'en')).toBe('')
		expect(formatComparedOn('soon', 'en')).toBe('soon')
		expect(formatComparedOn('2026-03-05', 'not a locale!')).toBe('2026-03-05')
	})
})

describe('labelFor', () => {
	it('picks the Dutch label for a Dutch locale when it is filled in', () => {
		expect(labelFor(null, 'nl')).toBe('')
		expect(labelFor({ name: 'A', name_nl: 'B' }, 'NL-be')).toBe('B')
		expect(labelFor({ name: 'A', name_nl: 'B' }, null)).toBe('A')
		expect(labelFor({ name: 'A', name_nl: 'B' })).toBe('A')
		expect(labelFor({ name: 'A', name_nl: '  ' }, 'nl')).toBe('A')
		expect(labelFor({ name: 'A', name_nl: 7 }, 'nl')).toBe('A')
		expect(labelFor({ name_nl: '' }, 'nl')).toBe('')
		expect(labelFor({ name: null }, 'en')).toBe('')
	})
})

describe('writeBack', () => {
	it('returns the contacts UID, or says the contact kept its old details', async () => {
		const notify = vi.fn()
		http.post.mockResolvedValue({ data: { contactsUid: 'u1' } })
		expect(await writeBack('client', 'c1', notify)).toBe('u1')
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/contacts-sync/write-back', { objectType: 'client', objectId: 'c1' })
		expect(notify).not.toHaveBeenCalled()

		http.post.mockResolvedValue({ data: { skipped: true } })
		expect(await writeBack('contact', 'k1', notify)).toBeNull()
		expect(notify).not.toHaveBeenCalled()

		http.post.mockResolvedValue({ data: {} })
		expect(await writeBack('contact', 'k1', notify)).toBeNull()
		expect(notify).toHaveBeenCalledWith('Your changes are saved here, but not in Nextcloud Contacts. The contact there still has the old details.')

		http.post.mockRejectedValue(new Error('500'))
		expect(await writeBack('contact', 'k1', notify)).toBeNull()
		expect(notify).toHaveBeenCalledTimes(2)

		http.post.mockResolvedValue({ data: null })
		expect(await writeBack('contact', 'k1', notify)).toBeNull()
		expect(notify).toHaveBeenCalledTimes(3)
	})
})
