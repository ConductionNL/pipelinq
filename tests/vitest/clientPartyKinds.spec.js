/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A client records what kind of party it is, from the party kinds the
 * administrator keeps: the client form offers the kinds the acceptance
 * `pipelinq:client:kind` names, those that fit the client's type.
 *
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-client-records-what-kind-of-party-it-is
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const { CLIENT_KIND_RECORD_TYPE, clientPartyKinds, kindOptions, partyKindsForType } = await import('../../src/services/clientPartyKinds.js')

const citizen = { id: 'k-burger', code: 'burger', label: 'Burger', identityShapes: ['naturalPerson'] }
const business = { id: 'k-bedrijf', code: 'bedrijf', label: 'Bedrijf', identityShapes: ['organization'] }
const other = { id: 'k-other', code: 'overig', label: 'Overig' }

beforeEach(() => {
	http.get.mockReset()
})

describe('clientPartyKinds', () => {
	it('reads the kinds the client acceptance names, as options', async () => {
		http.get.mockResolvedValueOnce({ data: { declared: true, kinds: [citizen, business, { code: 'no-id' }, { ...other, active: false }] } })
		expect(await clientPartyKinds()).toEqual([
			{ id: 'k-burger', label: 'Burger', shapes: ['naturalPerson'] },
			{ id: 'k-bedrijf', label: 'Bedrijf', shapes: ['organization'] },
		])
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/party-kinds', { params: { recordType: CLIENT_KIND_RECORD_TYPE } })
		expect(CLIENT_KIND_RECORD_TYPE).toBe('pipelinq:client:kind')
	})

	it('offers nothing when the registry cannot be read', async () => {
		http.get.mockRejectedValueOnce(new Error('down'))
		expect(await clientPartyKinds()).toEqual([])
	})
})

describe('partyKindsForType', () => {
	const options = kindOptions([citizen, business, other])

	it('offers an organisation the business kinds and a person the citizen kinds, and a kind without shapes to both', () => {
		expect(partyKindsForType(options, 'organization', null).map((o) => o.label)).toEqual(['Bedrijf', 'Overig'])
		expect(partyKindsForType(options, 'person', null).map((o) => o.label)).toEqual(['Burger', 'Overig'])
		expect(partyKindsForType(options, null, null).map((o) => o.label)).toEqual(['Burger', 'Bedrijf', 'Overig'])
	})

	it('keeps the kind already chosen when the type changes', () => {
		expect(partyKindsForType(options, 'organization', 'k-burger').map((o) => o.label)).toEqual(['Burger', 'Bedrijf', 'Overig'])
	})
})
