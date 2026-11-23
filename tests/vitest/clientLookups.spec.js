/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pure half of the new request dialog's client lookups: open tickets per
 * client, and the client a BRP person or a KVK company makes.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

let lookups
let api

beforeAll(async () => {
	globalThis.t = (app, text) => text
	lookups = await import('../../src/services/clientLookups.js')
	vi.doMock('@nextcloud/axios', () => ({ default: {} }))
	vi.doMock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
	vi.doMock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
	vi.doMock('../../src/services/contactSyncApi.js', () => ({ createWithContact: vi.fn() }))
	api = await import('../../src/services/clientLookupsApi.js')
}, 60000)

describe('openTicketCounts', () => {
	it('counts the tickets of each client that are not closed', () => {
		expect(lookups.openTicketCounts([
			{ client: 'c-1', status: 'new' },
			{ client: { id: 'c-1' }, status: 'awaiting_customer' },
			{ client: 'c-1', status: 'completed' },
			{ client: 'c-2', status: 'in_progress' },
			{ client: 'c-2', status: 'rejected' },
			{ client: 'c-3', status: 'converted' },
			{ client: '', status: 'new' },
		])).toEqual({ 'c-1': 2, 'c-2': 1 })
	})

	it('answers nothing for no tickets', () => {
		expect(lookups.openTicketCounts(null)).toEqual({})
	})
})

describe('clientFromBrpPerson', () => {
	it('makes a resident from the name and the address, never the BSN', () => {
		const person = {
			bsnHash: 'abc',
			bsn: '999993653',
			givenNames: 'Sanne',
			namePrefix: 'de',
			surname: 'Vries',
			residence: { straat: 'Lindelaan', huisnummer: 14, huisletter: 'a', huisnummertoevoeging: '2', postcode: '1234 AB', woonplaats: 'Dorp' },
		}
		expect(lookups.clientFromBrpPerson(person)).toEqual({
			name: 'Sanne de Vries',
			type: 'person',
			address: 'Lindelaan 14a-2, 1234 AB Dorp',
		})
	})

	it('leaves the address out when the BRP gave none', () => {
		expect(lookups.clientFromBrpPerson({ givenNames: 'Jan', surname: 'Bakker', residence: null })).toEqual({ name: 'Jan Bakker', type: 'person' })
	})
})

describe('the KVK company', () => {
	const company = { kvkNummer: '12345678', naam: 'Bakkerij De Graaf', rechtsvorm: 'Besloten Vennootschap', adres: { binnenlandsAdres: { plaats: 'Utrecht' } } }

	it('lists as its name with the KVK number, legal form and place', () => {
		expect(lookups.kvkCompanyRow(company)).toEqual({ id: '12345678', label: 'Bakkerij De Graaf', secondary: '12345678 · Besloten Vennootschap · Utrecht' })
	})

	it('makes an organisation client with its KVK number, legal form and place', () => {
		expect(lookups.clientFromKvkCompany(company)).toEqual({
			name: 'Bakkerij De Graaf',
			type: 'organization',
			kvkNumber: '12345678',
			legalForm: 'bv',
			place: 'Utrecht',
		})
	})

	it('leaves out a legal form the client schema does not name', () => {
		const fields = lookups.clientFromKvkCompany({ kvkNummer: '87654321', naam: 'Kerk', rechtsvorm: 'Kerkgenootschap' })
		expect(fields).toEqual({ name: 'Kerk', type: 'organization', kvkNumber: '87654321' })
	})
})

describe('matchingClient', () => {
	it('finds a company by its KVK number only', () => {
		const fields = { name: 'Bakkerij De Graaf', type: 'organization', kvkNumber: '12345678' }
		const same = { id: 'c-2', name: 'De Graaf', type: 'organization', kvkNumber: '12345678' }
		expect(lookups.matchingClient([{ id: 'c-1', name: 'Bakkerij De Graaf', type: 'organization' }, same], fields)).toBe(same)
		expect(lookups.matchingClient([{ id: 'c-1', name: 'Bakkerij De Graaf', type: 'organization' }], fields)).toBeNull()
	})

	it('finds a resident by name and address, not by name alone', () => {
		const fields = { name: 'Sanne de Vries', type: 'person', address: 'Lindelaan 14, Dorp' }
		const other = { id: 'c-1', name: 'Sanne de Vries', type: 'person', address: 'Markt 1, Stad' }
		const same = { id: 'c-2', name: 'sanne de vries', type: 'person', address: 'Lindelaan 14, Dorp' }
		const company = { id: 'c-3', name: 'Sanne de Vries', type: 'organization', address: 'Lindelaan 14, Dorp' }
		expect(lookups.matchingClient([other, company, same], fields)).toBe(same)
		expect(lookups.matchingClient([other, company], fields)).toBeNull()
	})
})

describe('clientLookupsApi', () => {
	it('reads the BRP access as yes only when the server says so', async () => {
		const http = { get: vi.fn().mockResolvedValue({ data: { allowed: true } }) }
		await expect(api.fetchBrpAccess(http)).resolves.toBe(true)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/brp/access')
		await expect(api.fetchBrpAccess({ get: vi.fn().mockResolvedValue({ data: { allowed: 'yes' } }) })).resolves.toBe(false)
		await expect(api.fetchBrpAccess({ get: vi.fn().mockRejectedValue(new Error('403')) })).resolves.toBe(false)
	})

	it('creates the client contact-first and hands it back with its id', async () => {
		const create = vi.fn().mockResolvedValue({ '@self': { id: 'c-9' }, contactsUid: 'u-1' })
		const fields = { name: 'Bakkerij De Graaf', type: 'organization', kvkNumber: '12345678' }
		await expect(api.createLookedUpClient(fields, create)).resolves.toEqual({ ...fields, '@self': { id: 'c-9' }, contactsUid: 'u-1', id: 'c-9' })
		expect(create).toHaveBeenCalledWith('client', fields)
		await expect(api.createLookedUpClient(fields, vi.fn().mockResolvedValue({}))).resolves.toBeNull()
	})
})
