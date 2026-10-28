/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The prospects page as the PqProspects board draws it: the ideal customer
 * profile above the list, read from the prospects answer.
 *
 * @spec openspec/specs/prospect-discovery/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { icpFacts } from '../../src/services/icpFacts.js'
import { matchesProspectLens, prospectMatches } from '../../src/services/prospectLenses.js'

describe('icpFacts', () => {
	it('lists the set criteria in the board order, translated', () => {
		const facts = icpFacts(
			{
				sbiCodes: ['56', '47'],
				employeeCountMin: 5,
				employeeCountMax: 50,
				provinces: ['Zuid-Holland'],
				legalForms: ['BV', 'Eenmanszaak'],
				excludeInactive: true,
				keywords: ['horeca', 'retail'],
			},
			(text) => `[${text}]`,
		)
		expect(facts.map((f) => [f.label, f.value])).toEqual([
			['[SBI codes]', '56, 47'],
			['[Min. employees]', '5'],
			['[Max. employees]', '50'],
			['[Provinces]', 'Zuid-Holland'],
			['[Legal forms]', 'BV, Eenmanszaak'],
			['[Exclude inactive companies]', '[Yes]'],
			['[Keywords]', 'horeca, retail'],
		])
	})

	it('shows nothing for a profile that is not set', () => {
		expect(icpFacts(null, (t) => t)).toEqual([])
		expect(
			icpFacts(
				{ sbiCodes: [], employeeCountMin: 0, excludeInactive: true },
				(t) => t,
			),
		).toEqual([])
	})
})

describe('prospect lenses', () => {
	const now = new Date('2026-10-28T10:00:00Z')
	const companies = [
		{ tradeName: 'Conduction B.V.', fitScore: 40, sbiCode: '47', address: { city: 'Veenendaal' }, registrationDate: '2026-10-25' },
		{ tradeName: 'Acme Holding B.V.', fitScore: 75, sbiCode: '56', address: { city: 'Utrecht' }, registrationDate: '2020-01-01' },
		{ tradeName: 'De Bakkerij', fitScore: 90, sbiCode: '47', address: { city: 'Amsterdam' } },
	]
	const names = (list) => list.map((c) => c.tradeName)

	it('keeps every company in All, a score above 70 in Good fit, this week in New', () => {
		expect(names(companies.filter((c) => matchesProspectLens(c, 0, now)))).toHaveLength(3)
		expect(names(companies.filter((c) => matchesProspectLens(c, 1, now)))).toEqual(['Acme Holding B.V.', 'De Bakkerij'])
		expect(names(companies.filter((c) => matchesProspectLens(c, 2, now)))).toEqual(['Conduction B.V.'])
	})

	it('searches company, SBI code and place, and applies the Filter menu', () => {
		expect(names(companies.filter((c) => prospectMatches(c, 'veenendaal', {})))).toEqual(['Conduction B.V.'])
		expect(names(companies.filter((c) => prospectMatches(c, '56', {})))).toEqual(['Acme Holding B.V.'])
		expect(names(companies.filter((c) => prospectMatches(c, '', { 'address.city': ['Amsterdam', 'Utrecht'] })))).toEqual(['Acme Holding B.V.', 'De Bakkerij'])
	})
})

describe('prospects page', () => {
	const view = readFileSync(
		resolve(__dirname, '../../src/views/prospects/ProspectsView.vue'),
		'utf8',
	)

	it('is the shared index page with the profile above the list', () => {
		expect(view).toMatch(/<CnIndexPage/)
		expect(view).toMatch(/<template #below-header>/)
		expect(view).toMatch(/inheritAttrs: false/)
	})

	it('draws the header line, the collapsible profile with Edit profile, the lenses and Filter', () => {
		expect(view).toMatch(/:description="headerLine"/)
		expect(view).toMatch(/:collapsible="true"/)
		expect(view).toMatch(/<template #actions>[\s\S]*data-testid="prospects-edit-profile"/)
		expect(view).toMatch(/<template #toolbar-start>[\s\S]*<CnQuickFilterBar/)
		expect(view).toMatch(/:filterMenu="true"/)
		expect(view).not.toMatch(/<dl\b/)
	})

	it('keeps Add as client on every row', () => {
		expect(view).toMatch(/:data-testid="`prospect-add-\$\{row\.kvkNumber\}`"/)
	})
})
