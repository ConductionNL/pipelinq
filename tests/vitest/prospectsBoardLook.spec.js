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

	it('keeps Add as client on every row', () => {
		expect(view).toMatch(/:data-testid="`prospect-add-\$\{row\.kvkNumber\}`"/)
	})
})
