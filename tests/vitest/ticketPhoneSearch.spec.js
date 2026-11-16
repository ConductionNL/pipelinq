import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * @spec openspec/changes/contact-moments-phone-lookup/specs/contact-history-by-phone/spec.md#requirement-a-ticket-is-found-by-phone-number-however-it-was-typed-req-chp-002
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'
import { looksLikePhoneNumber, ticketPhoneSearch } from '../../src/utils/ticketPhoneSearch.js'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))

describe('ticketPhoneSearch', () => {
	beforeEach(() => axios.get.mockReset())

	it('reads typed numbers as phone numbers and words as text', () => {
		expect(looksLikePhoneNumber('06 1234 5678')).toBe(true)
		expect(looksLikePhoneNumber('+31 6 12345678')).toBe(true)
		expect(looksLikePhoneNumber('06-12345678')).toBe(true)
		expect(looksLikePhoneNumber('riolering')).toBe(false)
		expect(looksLikePhoneNumber('2026')).toBe(false)
	})

	it('searches a typed number as the normalised phoneE164 with the note', async () => {
		axios.get.mockResolvedValue({ data: { number: '+31612345678', results: [] } })

		const reading = await ticketPhoneSearch('06 1234 5678')

		expect(axios.get).toHaveBeenCalledWith('/apps/pipelinq/api/tickets/by-phone', { params: { number: '06 1234 5678' } })
		expect(reading).toEqual({ filters: { phoneE164: '+31612345678' }, note: 'Searching by phone number' })
	})

	it('searches text as typed without asking the server', async () => {
		expect(await ticketPhoneSearch('riolering')).toBeNull()
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('searches as typed when the server reads no number', async () => {
		axios.get.mockResolvedValue({ data: { number: null, results: [] } })
		expect(await ticketPhoneSearch('0000 0000')).toBeNull()
	})

	it('is the Tickets list search handler', () => {
		const page = manifest.pages.find((p) => p.id === 'Tickets')
		expect(page.config.searchHandler).toBe('ticketPhoneSearch')
		const registrySource = readFileSync(new URL('../../src/registry.js', import.meta.url), 'utf8')
		expect(registrySource).toMatch(/ticketPhoneSearch: \{\s*kind: 'handler',\s*handler: ticketPhoneSearch,/)
	})
})
