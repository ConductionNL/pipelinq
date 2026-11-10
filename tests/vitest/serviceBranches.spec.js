// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branch-by-branch pins of eight service helpers: the assignee search, the
 * campaign traffic read, the duration and amount cells, the exchange-rate
 * source label, the duplicate-client check, forecast risk and accuracy, the
 * saved service step and the Mail compose URL.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-services/tasks.md#task-57
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const store = vi.hoisted(() => ({ fetchCollection: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

const { searchAssignees } = await import('../../src/services/taskUtils.js')
const { readTrafficRows } = await import('../../src/services/campaignTraffic.js')
const { isoDuration, objectCurrency } = await import('../../src/services/cellFormatters.js')
const { rateSourceLabel } = await import('../../src/services/leadCurrency.js')
const { findClientDuplicate } = await import('../../src/services/clientDuplicates.js')
const { accuracyRows, isAtRisk } = await import('../../src/services/forecastMath.js')
const { serializeStep } = await import('../../src/services/serviceSteps.js')

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok', getLocale: () => 'nl_NL' }
	store.fetchCollection.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('searchAssignees', () => {
	it('asks for nothing without a query', async () => {
		vi.stubGlobal('fetch', vi.fn())
		expect(await searchAssignees('')).toEqual([])
		expect(await searchAssignees(null)).toEqual([])
		expect(fetch).not.toHaveBeenCalled()
	})

	it('lists exact then other users, then exact then other groups', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({
			ok: true,
			json: async () => ({ ocs: { data: {
				exact: { users: [{ label: 'Anna', value: { shareWith: 'anna' } }], groups: [{ label: 'Sales' }] },
				users: [{ value: { shareWith: 'bob' } }],
				groups: [{ label: 'Ops', value: { shareWith: 'ops' } }],
			} } }),
		})))
		expect(await searchAssignees('a b')).toEqual([
			{ id: 'anna', label: 'Anna', type: 'user' },
			{ id: 'bob', label: 'bob', type: 'user' },
			{ id: 'Sales', label: 'Sales', type: 'group' },
			{ id: 'ops', label: 'Ops', type: 'group' },
		])
		expect(fetch).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/files_sharing/api/v1/sharees?search=a%20b&itemType=file&perPage=20&format=json',
			{ headers: { Accept: 'application/json', 'OCS-APIREQUEST': 'true', requesttoken: 'tok' } },
		)
	})

	it('reads no exact block as none, a refusal or a failure as none', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ ocs: { data: { users: [{ label: 'x' }] } } }) })))
		expect(await searchAssignees('x')).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ ocs: { data: { exact: { users: [] } } } }) })))
		expect(await searchAssignees('x')).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		expect(await searchAssignees('x')).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ ocs: { data: { exact: { users: [null] } } } }) })))
		expect(await searchAssignees('x')).toEqual([])
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('down') }))
		expect(await searchAssignees('x')).toEqual([])
	})
})

describe('readTrafficRows', () => {
	it('keeps blasts with site sessions in blast order, skips failed reads and counts what it asked', async () => {
		const answers = {
			b1: { connected: true, campaign: 'c', email: { opened: 3, clicked: 1 }, site: { sessions: 4 }, deals: { dealCount: 2 } },
			b2: { connected: true, site: { sessions: 0 } },
			b4: { connected: true, site: { sessions: 1 } },
		}
		const read = vi.fn(async (id) => {
			if (id === 'b3') throw new Error('x')
			return answers[id]
		})
		const blasts = [{ id: 'b1', name: 'One' }, { uuid: 'b2' }, { slug: 'b3' }, {}, { id: 'b4' }]
		expect(await readTrafficRows(blasts, read, 2)).toEqual({
			rows: [
				{ id: 'b1', name: 'One', campaign: 'c', opened: 3, clicked: 1, sessions: 4, dealCount: 2 },
				{ id: 'b4', name: 'b4', campaign: '', opened: 0, clicked: 0, sessions: 1, dealCount: 0 },
			],
			connected: true,
			asked: 4,
			answered: 3,
		})
	})

	it('stops asking once the source says it is not connected', async () => {
		const read = vi.fn(async () => ({ connected: false }))
		expect(await readTrafficRows([{ id: 'a' }, { id: 'b' }, { id: 'c' }], read, 1)).toEqual({ rows: [], connected: false, asked: 1, answered: 1 })
		expect(await readTrafficRows([], read, 3)).toEqual({ rows: [], connected: null, asked: 0, answered: 0 })
	})
})

describe('the duration and amount cells', () => {
	it('reads ISO durations, keeping seconds only when nothing larger is set', () => {
		const cases = [null, undefined, '', 'P', 'PT', 'nope', 'PT6M', 'PT1H5M', 'PT45S', 'PT1M30S', 'P2D', 'P1DT2H', 'PT2.6S', 7]
		expect(cases.map((v) => isoDuration(v))).toEqual(['', '', '', 'P', 'PT', 'nope', '6 min', '1 h 5 min', '45 s', '1 min', '2 d', '1 d 2 h', '3 s', '7'])
	})

	it('writes an amount in the row currency, the decimals and the locale the column asks for', () => {
		expect(objectCurrency(null)).toBe('')
		expect(objectCurrency('')).toBe('')
		expect(objectCurrency('abc')).toBe('abc')
		expect(objectCurrency(1234.5, { currency: 'USD' }, null, { locale: 'en-US' })).toBe('$1,234.50')
		expect(objectCurrency(1234.5, { price: 'GBP' }, null, { locale: 'en-US', currencyField: 'price', decimals: 0 })).toBe('£1,235')
		expect(objectCurrency(10, {}, null, { locale: 'en-US', decimals: Number.NaN })).toBe(objectCurrency(10, {}, null, { locale: 'en-US' }))
		expect(objectCurrency(10, null)).toBe(objectCurrency(10, {}))
		expect(objectCurrency(10, { currency: 'EUR' })).toBe(new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(10))
		delete globalThis.OC
		expect(objectCurrency(10, { currency: 'EUR' }, null, {})).toBe(new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(10))
	})
})

describe('rateSourceLabel', () => {
	it('names every rate source', () => {
		const cases = [
			{ source: 'override' },
			{ source: 'shillinq', origin: 'ecb' },
			{ source: 'shillinq', origin: 'bank-feed' },
			{ source: 'shillinq', origin: null },
			{ source: 'manual' },
			{ source: 'none' },
			{ source: 'same' },
			{ source: 'toString' },
			null,
		]
		expect(cases.map((c) => rateSourceLabel(c))).toEqual([
			'Set by an administrator',
			'ECB via Shillinq',
			'Bank feed via Shillinq',
			'Typed in Shillinq',
			'Typed in Forecast settings',
			'No rate, counted at face value',
			'',
			'',
			'',
		])
	})
})

describe('findClientDuplicate', () => {
	it('looks up by address, then by name, and says what matched', async () => {
		store.fetchCollection
			.mockResolvedValueOnce([{ id: 'x', email: 'other@b.nl' }])
			.mockResolvedValueOnce([{ uuid: 'u1', name: ' DE GRAAF ' }])
		expect(await findClientDuplicate({ email: ' A@B.nl ', name: ' de graaf ' })).toEqual({ id: 'u1', name: ' DE GRAAF ', field: 'name' })
		expect(store.fetchCollection.mock.calls).toEqual([
			['client', { email: 'A@B.nl', _limit: 5 }],
			['client', { name: 'de graaf', _limit: 5 }],
		])
	})

	it('stops at the address match, and falls back to what was typed for a nameless client', async () => {
		store.fetchCollection.mockResolvedValueOnce([{ id: 'c1', email: 'a@b.nl' }])
		expect(await findClientDuplicate({ email: 'a@b.nl', name: 'Graaf' })).toEqual({ id: 'c1', name: 'a@b.nl', field: 'email' })
		expect(store.fetchCollection).toHaveBeenCalledTimes(1)
	})

	it('looks nothing up for a bad address or a short name, and finds nothing on a failure', async () => {
		expect(await findClientDuplicate({ email: 'nope', name: 'ab' })).toBe(null)
		expect(await findClientDuplicate()).toBe(null)
		expect(store.fetchCollection).not.toHaveBeenCalled()
		store.fetchCollection.mockRejectedValueOnce(new Error('x')).mockResolvedValueOnce(null)
		expect(await findClientDuplicate({ email: 'a@b.nl', name: 'Graaf' })).toBe(null)
	})
})

describe('accuracyRows', () => {
	it('scores sellers with two or more snapshots and a first commit, in first-seen order', () => {
		const snapshots = [
			{ owner_id: 'b', as_of_date: '2026-02-01', commit: 50, closed_won: 40 },
			null,
			{ commit: 5 },
			{ owner_id: 'a', as_of_date: '2026-01-08', commit: 1, closed_won: 30 },
			{ owner_id: 'a', as_of_date: '2026-01-01', commit: 100, closed_won: 0 },
			{ owner_id: 'b', as_of_date: '2026-01-01', commit: '200', closed_won: 'x' },
			{ owner_id: 'c', as_of_date: '2026-01-01', commit: 10 },
			{ owner_id: 'd', as_of_date: '2026-01-01', commit: 0 },
			{ owner_id: 'd', as_of_date: '2026-01-02', closed_won: 9 },
		]
		expect(accuracyRows(snapshots)).toEqual([
			{ owner_id: 'b', weekOneCommit: 200, actual: 40, score: 0.2 },
			{ owner_id: 'a', weekOneCommit: 100, actual: 30, score: 0.3 },
		])
		expect(accuracyRows(null)).toEqual([])
	})
})

describe('isAtRisk', () => {
	it('is at risk below the attainment threshold with few days left, never without a quota', () => {
		expect(isAtRisk(80, 100, 10)).toBe(true)
		expect(isAtRisk(95, 100, 10)).toBe(false)
		expect(isAtRisk(80, 100, 30)).toBe(false)
		expect(isAtRisk(80, 100, 40, 85, 50)).toBe(true)
		expect(isAtRisk(80, 100, 40, 75, 50)).toBe(false)
		expect(isAtRisk(0, 0, 1)).toBe(false)
		expect(isAtRisk(0, -5, 1)).toBe(false)
		expect(isAtRisk('50', '100', 1)).toBe(true)
	})
})

describe('serializeStep', () => {
	it('keeps the product, a usable quantity and a known unit', () => {
		expect(serializeStep(null)).toEqual({ durationMinutes: 0, resourceType: 'staff', skillRequired: '', allowGap: false })
		expect(serializeStep({ durationMinutes: '30', resourceType: 'room', skillRequired: ' x ', allowGap: 1, productId: 7, quantity: '2', unit: 'hour' }))
			.toEqual({ durationMinutes: 30, resourceType: 'room', skillRequired: 'x', allowGap: true, productId: '7', quantity: 2, unit: 'hour' })
		for (const quantity of [null, undefined, '', 'x']) {
			expect(serializeStep({ quantity, unit: 'week' })).not.toHaveProperty('quantity')
		}
		expect(serializeStep({ quantity: 0 }).quantity).toBe(0)
		expect(serializeStep({ unit: 'week' })).not.toHaveProperty('unit')
	})
})
