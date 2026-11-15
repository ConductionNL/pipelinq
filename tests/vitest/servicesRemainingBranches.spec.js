/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The branches of `src/services/*` the other specs leave open: the BI export
 * run listing and test-run answer, the empty and unparseable inputs of the
 * cell formatters, the export formatters in the registry, and the booking
 * name formatters that look a customer up as a contact, then as a client.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

let exportApi
let formatters
let exportRunText

beforeAll(async () => {
	globalThis.OC = { requestToken: 'tok' }
	exportApi = (await import('../../src/services/exportApi.js')).exportApi
	formatters = await import('../../src/services/cellFormatters.js')
	exportRunText = await import('../../src/views/export/exportRunText.js')
})

afterEach(() => {
	vi.unstubAllGlobals()
})

/**
 * Let fetch answer with the given body.
 *
 * @param {object} body The JSON body.
 * @return {void}
 */
function answer(body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => body })))
}

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }

describe('exportApi runs', () => {
	it('lists runs with only the filters that are set', async () => {
		answer({ runs: [{ id: 'r1' }] })

		const runs = await exportApi.listRuns({ job_id: 'j1', status: '', date_from: '2026-11-01', date_to: null })

		expect(runs).toEqual([{ id: 'r1' }])
		expect(fetch).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/export/runs?job_id=j1&date_from=2026-11-01',
			{ method: 'GET', headers },
		)
	})

	it('lists every run without a query string, and none when the answer has no runs', async () => {
		answer({})

		expect(await exportApi.listRuns()).toEqual([])
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/export/runs', { method: 'GET', headers })
	})

	it('unwraps a test run’s result, or hands back the whole answer', async () => {
		answer({ result: { rows: 3 } })
		expect(await exportApi.testRun('j1')).toEqual({ rows: 3 })

		answer({ rows: 4 })
		expect(await exportApi.testRun('j1')).toEqual({ rows: 4 })
	})
})

describe('cell formatters', () => {
	it.each([null, undefined, ''])('render %j as empty', (value) => {
		expect(formatters.enumLabel(value, {}, { 'x-enum-labels': { '': 'Nothing' } })).toBe('')
		expect(formatters.yesNo(value)).toBe('')
		expect(formatters.shortDateTime(value)).toBe('')
		expect(formatters.timeOfDay(value)).toBe('')
		expect(formatters.longDate(value)).toBe('')
	})

	it('show a value that is not a date as stored', () => {
		expect(formatters.shortDateTime('soon', {}, {}, { locale: 'nl-NL' })).toBe('soon')
		expect(formatters.timeOfDay('later', {}, {}, { locale: 'nl-NL' })).toBe('later')
		expect(formatters.longDate('someday')).toBe('someday')
	})

	it('label an enum value from either key, and show an unlabelled one as stored', () => {
		expect(formatters.enumLabel('active', {}, { 'x-enum-labels': { active: 'Active' } })).toBe('Active')
		expect(formatters.enumLabel('active', {}, { enumLabels: { active: 'Live' } })).toBe('Live')
		expect(formatters.enumLabel(3, {}, {})).toBe('3')
		expect(formatters.enumLabel('x')).toBe('x')
	})

	it('read true, "true" and 1 as yes, anything else as no', () => {
		expect([true, 'true', 1].map(formatters.yesNo)).toEqual(['Yes', 'Yes', 'Yes'])
		expect([false, 'false', 0, 'yes'].map(formatters.yesNo)).toEqual(['No', 'No', 'No', 'No'])
	})

	it('register the export formatters as the export run text writes them', () => {
		const { exportMoment, exportSchedule } = formatters.CELL_FORMATTERS

		expect(exportMoment('')).toBe('')
		expect(exportMoment('not a moment')).toBe(exportRunText.exportMoment('not a moment'))
		expect(exportMoment('2026-03-04T10:15:00Z')).toBe(exportRunText.exportMoment('2026-03-04T10:15:00Z'))
		expect(exportSchedule('0 6 * * *')).toBe(exportRunText.exportSchedule('0 6 * * *'))
		expect(exportSchedule('')).toBe('')
	})
})

describe('createAppFormatters booking names', () => {
	/**
	 * Wait for the name lookups to settle.
	 *
	 * @return {Promise<void>}
	 */
	const settle = () => new Promise((resolve) => setTimeout(resolve, 0))

	it('names a customer as a contact, falling back to a client, and a service by its name', async () => {
		const store = {
			fetchObject: vi.fn(async (type, id) => {
				if (type === 'contact' && id === 'c1') {
					return { firstName: 'Fatima', lastName: 'El-Amrani' }
				}
				if (type === 'client' && id === 'k1') {
					return { name: 'Bakkerij Bakker' }
				}
				if (type === 'appointmentService' && id === 's1') {
					return { title: 'Paspoort aanvragen' }
				}
				return null
			}),
		}
		const map = formatters.createAppFormatters(store)

		expect(map.bookingCustomerName('c1')).toBe('…')
		expect(map.bookingCustomerName('k1')).toBe('…')
		expect(map.bookingServiceName('s1')).toBe('…')
		await settle()

		expect(map.bookingCustomerName('c1')).toBe('Fatima El-Amrani')
		expect(map.bookingCustomerName('k1')).toBe('Bakkerij Bakker')
		expect(map.bookingServiceName('s1')).toBe('Paspoort aanvragen')
		const calls = store.fetchObject.mock.calls.map(([type, id]) => `${type}:${id}`)
		expect([...calls].sort()).toEqual(['appointmentService:s1', 'client:k1', 'contact:c1', 'contact:k1'])
		expect(calls.indexOf('contact:k1')).toBeLessThan(calls.indexOf('client:k1'))
	})

	it('shows the id when no lookup names it, and nothing for an empty value', async () => {
		const store = { fetchObject: vi.fn(async () => { throw new Error('403') }) }
		const map = formatters.createAppFormatters(store)

		expect(map.bookingCustomerName('')).toBe('')
		map.bookingCustomerName('gone')
		await settle()

		expect(map.bookingCustomerName('gone')).toBe('gone')
		expect(store.fetchObject.mock.calls).toEqual([['contact', 'gone'], ['client', 'gone']])
	})
})
