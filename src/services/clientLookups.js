// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The client lookups of the new request dialog (board PqNieuwVerzoek): the
 * open tickets of the found clients, and a client made from a BRP person or a
 * KVK company. Pure functions; the dialog does the calls.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */

import { isTerminalStatus } from './requestStatus.js'

/**
 * The KVK legal form as the client schema's `legalForm` enum names it, by the
 * lower-cased Handelsregister description. The same table organisation
 * enrichment uses (OrganisationEnrichmentService::LEGAL_FORMS).
 */
const LEGAL_FORMS = {
	eenmanszaak: 'eenmanszaak',
	'vennootschap onder firma': 'vof',
	maatschap: 'maatschap',
	'commanditaire vennootschap': 'cv',
	'besloten vennootschap': 'bv',
	'naamloze vennootschap': 'nv',
	coöperatie: 'cooperatie',
	cooperatie: 'cooperatie',
	vereniging: 'vereniging',
	stichting: 'stichting',
}

/**
 * The id a reference holds, whether it is an id or an object.
 *
 * @param {string|object|null} reference The reference.
 * @return {string} The id, or ''.
 */
function idOf(reference) {
	if (reference && typeof reference === 'object') {
		return String(reference.id || reference['@self']?.id || '')
	}
	return reference ? String(reference) : ''
}

/**
 * How many open tickets each client has: a ticket counts while its status is
 * not a final one.
 *
 * @param {Array<object>} tickets The tickets of the clients.
 * @return {{[clientId: string]: number}} Client id to the number of open tickets.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function openTicketCounts(tickets) {
	const counts = {}
	for (const ticket of Array.isArray(tickets) ? tickets : []) {
		const client = idOf(ticket?.client)
		if (client === '' || isTerminalStatus(ticket?.status)) {
			continue
		}
		counts[client] = (counts[client] || 0) + 1
	}
	return counts
}

/**
 * The street line and place of a BRP residence.
 *
 * @param {object|null} residence The person's `residence`.
 * @return {string} "Street 12a-2, 1234 AB Place", or ''.
 */
function residenceLine(residence) {
	const r = residence && typeof residence === 'object' ? residence : {}
	const number = [r.huisnummer, r.huisletter].filter((v) => v !== null && v !== undefined && v !== '').join('')
	const house = [number, r.huisnummertoevoeging].filter(Boolean).join('-')
	const street = [r.straat, house].filter(Boolean).join(' ')
	const place = [r.postcode, r.woonplaats].filter(Boolean).join(' ')
	return [street, place].filter(Boolean).join(', ')
}

/**
 * The full name of a BRP person: given names, prefix, surname.
 *
 * @param {object|null} person The `persoon` of a BRP lookup.
 * @return {string} The name, or ''.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function brpPersonName(person) {
	const p = person || {}
	return [p.givenNames, p.namePrefix, p.surname].map((v) => (v || '').trim()).filter(Boolean).join(' ')
}

/**
 * A resident client from a BRP person: the name and the address only. The
 * BSN is never part of it.
 *
 * @param {object|null} person The `persoon` of a BRP lookup.
 * @return {{name: string, type: string, address: string}} The client fields.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function clientFromBrpPerson(person) {
	const fields = { name: brpPersonName(person), type: 'person' }
	const address = residenceLine(person?.residence)
	if (address !== '') {
		fields.address = address
	}
	return fields
}

/**
 * The place of a KVK company.
 *
 * @param {object} company A KVK search row.
 * @return {string} The place, or ''.
 */
function kvkPlace(company) {
	const address = company?.adres?.binnenlandsAdres || company?.adres || {}
	return String(address.plaats || company?.plaats || '')
}

/**
 * A KVK company as a row of the lookup's result list.
 *
 * @param {object} company A KVK search row.
 * @return {{id: string, label: string, secondary: string}} The row.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function kvkCompanyRow(company) {
	const kvkNumber = String(company?.kvkNummer || '')
	return {
		id: kvkNumber,
		label: String(company?.naam || kvkNumber),
		secondary: [kvkNumber, company?.rechtsvorm, kvkPlace(company)].filter(Boolean).join(' · '),
	}
}

/**
 * An organisation client from a KVK company: name, KVK number, legal form
 * and place. A legal form the schema does not name is left out.
 *
 * @param {object} company A KVK search row.
 * @return {{name: string, type: string, kvkNumber: string, legalForm?: string, place?: string}} The client fields.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function clientFromKvkCompany(company) {
	const fields = {
		name: String(company?.naam || '').trim(),
		type: 'organization',
		kvkNumber: String(company?.kvkNummer || ''),
	}
	const legalForm = LEGAL_FORMS[String(company?.rechtsvorm || '').trim().toLowerCase()]
	if (legalForm) {
		fields.legalForm = legalForm
	}
	const place = kvkPlace(company)
	if (place !== '') {
		fields.place = place
	}
	return fields
}

/**
 * The existing client a lookup found, if any: for a company the one with its
 * KVK number, for a person the resident with the same name and address.
 *
 * @param {Array<object>} candidates Clients found by name or KVK number.
 * @param {object} fields The client fields the lookup made.
 * @return {object|null} The matching client, or null.
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
export function matchingClient(candidates, fields) {
	const same = (a, b) => String(a || '').trim().toLowerCase() === String(b || '').trim().toLowerCase()
	for (const client of Array.isArray(candidates) ? candidates : []) {
		if (fields.type === 'organization') {
			if (fields.kvkNumber && client?.kvkNumber === fields.kvkNumber) {
				return client
			}
			continue
		}
		if (client?.type !== 'organization' && same(client?.name, fields.name) && same(client?.address, fields.address)) {
			return client
		}
	}
	return null
}
