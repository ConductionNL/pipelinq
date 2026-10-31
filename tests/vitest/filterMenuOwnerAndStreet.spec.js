/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Filter menus and chips the boards draw: PqContracten and PqFlows
 * filter on the owner by name ("Eigenaar: Pieter Jansen"), and the
 * PqEvenement registrations chip names the street ("Straat: Lindelaan").
 *
 * @spec openspec/changes/filter-menu-owner-and-street/specs/filter-menu-owner-and-street/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
}
const base = read('src/manifest.json')
const contracts = read('src/manifest.d/96-contracts.json')
const events = read('src/manifest.d/78-campaign-events.json')
const register = read('lib/Settings/register.d/96-contract-renewal.json')
const en = read('l10n/en.json').translations
const nl = read('l10n/nl.json').translations

const page = (pages, id) => pages.find((p) => p.id === id)
const ownerEntry = { key: 'ownerId', label: 'Owner', referenceType: 'nextcloud-user' }

describe('PqContracten owner filter', () => {
	const list = page(contracts.pages, 'Contracts')

	it('offers the owner in the Filter menu by display name', () => {
		expect(list.config.filterMenu).toBe(true)
		expect(list.config.filterMenuFields).toEqual([ownerEntry])
	})

	it('lets the API count contracts per owner without changing the field', () => {
		const owner = register.components.schemas.salesContract.properties.ownerId
		expect(owner.facetable).toBe(true)
		expect(owner.format).toBe('user')
	})
})

describe('PqFlows owner filter', () => {
	const flows = page(base.pages, 'Flows')

	it('draws a Filter button with the owner by display name', () => {
		expect(flows.config.entitySource).toBe('flows')
		expect(flows.config.filterMenu).toBe(true)
		expect(flows.config.filterMenuFields).toEqual([{ ...ownerEntry, key: 'owner' }])
	})
})

describe('PqEvenement registrations chip', () => {
	const detail = events.pages.find((p) => (p.config?.widgets || []).some((w) => w.id === 'event-registrations'))
	const registrations = detail.config.widgets.find((w) => w.id === 'event-registrations')

	it('names the address filter "Street"', () => {
		expect(registrations.content.filterLabels).toEqual({ address: { label: 'Street' } })
	})
})

describe('the labels translate', () => {
	it.each([
		['Owner', 'Eigenaar'],
		['Street', 'Straat'],
	])('%s reads %s in Dutch', (key, dutch) => {
		expect(en[key]).toBe(key)
		expect(nl[key]).toBe(dutch)
	})
})
