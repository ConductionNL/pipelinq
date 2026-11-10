/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Every branch of nine small service helpers, pinned before they were
 * split into named checks: the A/B stats, the segment rule tree, the
 * submission link plan, the prospect lenses, the product tariffs and
 * weight, the monthly revenue, the ICP facts and the newsletter note.
 */
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match)),
}))

const { abTemplateIds, chiSquarePValue, variantStats } = await import('../../src/services/abTest.js')
const { cloneTree, tally } = await import('../../src/services/segmentRules.js')
const { linkPlan } = await import('../../src/utils/submissionLink.js')
const { matchesProspectLens, prospectMatches } = await import('../../src/services/prospectLenses.js')
const { currentTariffs, productWeight } = await import('../../src/services/productTariffs.js')
const { normalizeToMonthly } = await import('../../src/services/recurringRevenue.js')
const { icpFacts } = await import('../../src/services/icpFacts.js')
const { blastNote } = await import('../../src/services/blastNote.js')

afterEach(() => {
	delete globalThis.OC
})

describe('abTest', () => {
	it('reads a variant\'s stats', () => {
		expect(variantStats({ totals: { delivered: 200, clicked: 50 }, templateId: 't1' }, { t1: 'Hi' }))
			.toEqual({ delivered: 200, clicked: 50, clickRate: 0.25, subject: 'Hi' })
		expect(variantStats(null)).toEqual({ delivered: 0, clicked: 0, clickRate: 0, subject: '' })
		expect(variantStats({ totals: { clicked: 3 }, templateId: 'x' }, {})).toEqual({ delivered: 0, clicked: 3, clickRate: 0, subject: '' })
	})

	it('collects the templates of variants and their parents', () => {
		const blasts = [
			{ id: 'p', templateId: 'tp' },
			{ uuid: 'v', abVariantOf: 'p', templateId: 'tv' },
			{ slug: 's', templateId: 'ts' },
			{ id: 'q', abVariantOf: 'p' },
			{ id: 'w', abVariantOf: 'p', templateId: 'tv' },
		]
		expect(abTemplateIds(blasts)).toEqual(['tp', 'tv'])
		expect(abTemplateIds([{ slug: 'p', templateId: 'a' }, { abVariantOf: 'p' }])).toEqual(['a'])
		expect(abTemplateIds([])).toEqual([])
	})

	it('computes the chi-square p-value, or null for a degenerate table', () => {
		expect(chiSquarePValue(60, 600, 12, 600)).toBe(5.4091743306727835e-9)
		expect(chiSquarePValue(30, 500, 31, 500)).toBe(0.8948811968232554)
		expect(chiSquarePValue(10, 100, 20, 100)).toBe(0.04767024215002069)
		expect(chiSquarePValue(1, 0, 1, 10)).toBeNull()
		expect(chiSquarePValue(1, 10, 1, 0)).toBeNull()
		expect(chiSquarePValue(11, 10, 1, 10)).toBeNull()
		expect(chiSquarePValue(1, 10, 11, 10)).toBeNull()
		expect(chiSquarePValue(0, 500, 0, 500)).toBeNull()
		expect(chiSquarePValue(10, 10, 5, 5)).toBeNull()
		expect(chiSquarePValue(Number.NaN, 10, 1, 10)).toBeNaN()
	})
})

describe('segmentRules', () => {
	it('opens every stored shape as a group', () => {
		const empty = { type: 'AND', children: [] }
		expect(cloneTree(undefined)).toEqual(empty)
		expect(cloneTree('text')).toEqual(empty)
		expect(cloneTree(5)).toEqual(empty)
		const circular = {}
		circular.self = circular
		expect(cloneTree(circular)).toEqual(empty)
		expect(cloneTree([{ field: 'a' }])).toEqual({ type: 'AND', children: [{ field: 'a' }] })
		expect(cloneTree({ type: 'or', children: [], extra: 1 })).toEqual({ type: 'OR', children: [], extra: 1 })
		expect(cloneTree({ children: [] })).toEqual({ type: 'AND', children: [] })
		expect(cloneTree({ field: 'a', value: 1 })).toEqual({ type: 'AND', children: [{ field: 'a', value: 1 }] })
		expect(cloneTree({ operator: 'eq' })).toEqual({ type: 'AND', children: [{ operator: 'eq' }] })
		expect(cloneTree({ other: 1 })).toEqual(empty)
		const stored = { type: 'AND', children: [{ field: 'a' }] }
		expect(cloneTree(stored).children[0]).not.toBe(stored.children[0])
	})

	it('counts the conditions and the unfinished nodes', () => {
		expect(tally({ children: [] })).toEqual({ leaves: 0, unfinished: 1 })
		expect(tally({ field: 'a', operator: 'eq', value: 'x' })).toEqual({ leaves: 1, unfinished: 0 })
		expect(tally({ field: 'a', operator: 'eq', value: 0 })).toEqual({ leaves: 1, unfinished: 0 })
		for (const value of ['', null, undefined]) {
			expect(tally({ field: 'a', operator: 'eq', value })).toEqual({ leaves: 1, unfinished: 1 })
		}
		expect(tally({ operator: 'eq', value: 'x' })).toEqual({ leaves: 1, unfinished: 1 })
		expect(tally({ field: 'a', value: 'x' })).toEqual({ leaves: 1, unfinished: 1 })
		expect(tally(null)).toEqual({ leaves: 1, unfinished: 1 })
		expect(tally({
			children: [
				{ field: 'a', operator: 'eq', value: 'x' },
				{ children: [{ field: 'b', operator: 'eq', value: '' }, { children: [] }] },
			],
		})).toEqual({ leaves: 2, unfinished: 2 })
	})
})

describe('submissionLink.linkPlan', () => {
	it('plans an organisation client, a full contact and a lead', () => {
		const ticket = { title: ' Title ', description: ' Desc ', reporterName: 'Anna', reporterEmail: 'A@B.nl', reporterPhone: '06', reporterOrganisation: 'Acme', source: 'contact' }
		expect(linkPlan({ ticket, client: { mode: 'new' }, contact: { mode: 'new' }, lead: true })).toEqual({
			client: { create: { name: 'Acme', type: 'organization', email: 'a@b.nl' } },
			contact: { create: { name: 'Anna', email: 'a@b.nl', phone: '06' } },
			lead: { title: 'Title', description: 'Desc', source: 'contact' },
		})
	})

	it('plans existing records and no lead', () => {
		const plan = linkPlan({ ticket: {}, client: { mode: 'existing', id: 7 }, contact: { mode: 'existing', id: 'c' } })
		expect(plan).toEqual({ client: { id: '7' }, contact: { id: 'c' }, lead: null })
		expect(() => linkPlan({ ticket: {}, client: { mode: 'existing' }, contact: {} })).toThrow('No existing client chosen.')
		expect(() => linkPlan({ ticket: {}, client: {}, contact: { mode: 'existing' } })).toThrow('No existing contact chosen.')
	})

	it('names a person client after the person, or the e-mail address', () => {
		expect(linkPlan({ ticket: { reporterEmail: 'x@y.nl' }, client: {}, contact: {}, lead: true })).toEqual({
			client: { create: { name: 'x@y.nl', type: 'person', email: 'x@y.nl' } },
			contact: { create: { name: 'x@y.nl', email: 'x@y.nl' } },
			lead: { title: 'x@y.nl', description: '', source: 'website' },
		})
		expect(linkPlan({ ticket: null, client: {}, contact: {}, lead: true })).toEqual({
			client: { create: { name: '', type: 'person' } },
			contact: { create: { name: '' } },
			lead: { title: '', description: '', source: 'website' },
		})
		expect(linkPlan({ ticket: { reporterName: 'Bo', reporterPhone: '010' }, client: {}, contact: {} }).contact)
			.toEqual({ create: { name: 'Bo', phone: '010' } })
	})
})

describe('prospectLenses', () => {
	const now = new Date('2026-01-10T12:00:00Z')

	it('matches each lens', () => {
		expect(matchesProspectLens({ fitScore: 71 }, 1, now)).toBe(true)
		expect(matchesProspectLens({ fitScore: '70' }, 1, now)).toBe(false)
		expect(matchesProspectLens({ registrationDate: '2026-01-05' }, 2, now)).toBe(true)
		expect(matchesProspectLens({ registrationDate: '2026-01-03T12:00:00Z' }, 2, now)).toBe(true)
		expect(matchesProspectLens({ registrationDate: '2026-01-01' }, 2, now)).toBe(false)
		expect(matchesProspectLens({ registrationDate: '2026-02-01' }, 2, now)).toBe(false)
		expect(matchesProspectLens({ registrationDate: 'nope' }, 2, now)).toBe(false)
		expect(matchesProspectLens({}, 2, now)).toBe(false)
		expect(matchesProspectLens({}, 0, now)).toBe(true)
		expect(matchesProspectLens({}, 7, now)).toBe(true)
	})

	it('matches the filters and the search', () => {
		const prospect = { tradeName: 'Acme BV', sbiCode: '6201', sbiDescription: 'Software', address: { city: 'Utrecht' } }
		expect(prospectMatches(prospect, '', null)).toBe(true)
		expect(prospectMatches(prospect, '  ', { 'address.city': [], x: 'no-list' })).toBe(true)
		expect(prospectMatches(prospect, '', { 'address.city': ['Utrecht'] })).toBe(true)
		expect(prospectMatches(prospect, '', { 'address.city': ['Delft'] })).toBe(false)
		expect(prospectMatches(prospect, '', { 'missing.deep': [''] })).toBe(true)
		expect(prospectMatches(prospect, '', { 'missing.deep': ['x'] })).toBe(false)
		expect(prospectMatches(prospect, ' ACME ', {})).toBe(true)
		expect(prospectMatches(prospect, '6201', {})).toBe(true)
		expect(prospectMatches(prospect, 'soft', {})).toBe(true)
		expect(prospectMatches(prospect, 'utr', {})).toBe(true)
		expect(prospectMatches(prospect, 'nothing', {})).toBe(false)
		expect(prospectMatches({}, 'x', {})).toBe(false)
	})
})

describe('productTariffs', () => {
	it('keeps per label the latest tariff in force', () => {
		const tariffs = [
			{ label: 'A', price: 1 },
			{ label: 'B', price: 2, validFrom: '2026-01-01' },
			null,
			{ label: 'A', price: 3, validFrom: '2026-02-01' },
			{ label: 'A', price: 4, validFrom: '2026-09-01' },
			{ label: 'B', price: 5, validFrom: '2025-01-01' },
			{ label: 'B', price: 6, validFrom: '2026-01-01' },
		]
		expect(currentTariffs(tariffs, '2026-03-01').map((tariff) => tariff.price)).toEqual([3, 6])
		expect(currentTariffs(undefined, '2026-03-01')).toEqual([])
		expect(currentTariffs([{ label: 'C', validFrom: '2026-03-01' }], '2026-03-01')).toHaveLength(1)
	})

	it('formats the weight in grams or kilograms', () => {
		expect(productWeight({ weightGrams: 250.4 })).toBe('250 g')
		expect(productWeight({ weightGrams: 1500 })).toBe('1.5 kg')
		expect(productWeight({ weight: 0.2 })).toBe('200 g')
		expect(productWeight({ weightGrams: 'x', weight: 2 })).toBe('2 kg')
		expect(productWeight({})).toBe('')
		expect(productWeight(null)).toBe('')
		globalThis.OC = { getLocale: () => 'nl_NL' }
		expect(productWeight({ weightGrams: 1500 })).toBe('1,5 kg')
		globalThis.OC = {}
		expect(productWeight({ weightGrams: 1500 })).toBe('1.5 kg')
	})
})

describe('recurringRevenue.normalizeToMonthly', () => {
	it('spreads each billing interval over a month', () => {
		expect(normalizeToMonthly('monthly', 90)).toBe(90)
		expect(normalizeToMonthly('monthly', '90')).toBe('90')
		expect(normalizeToMonthly('quarterly', 90)).toBe(30)
		expect(normalizeToMonthly('annual', 120)).toBe(10)
		expect(normalizeToMonthly('one-off', 90)).toBe(0)
		expect(normalizeToMonthly('weekly', 90)).toBe(0)
		expect(normalizeToMonthly(undefined, 90)).toBe(0)
		expect(normalizeToMonthly('toString', 90)).toBe(0)
		expect(normalizeToMonthly({ toString: () => 'monthly' }, 90)).toBe(0)
	})
})

describe('icpFacts', () => {
	const translate = (text) => `T:${text}`

	it('lists the filled-in facts, translated', () => {
		expect(icpFacts({
			sbiCodes: ['62', '', '63'],
			employeeCountMin: 5,
			employeeCountMax: '0',
			provinces: [],
			legalForms: ['BV'],
			excludeInactive: false,
			keywords: ['cloud'],
		}, translate)).toEqual([
			{ key: 'sbiCodes', label: 'T:SBI codes', value: '62, 63' },
			{ key: 'employeeCountMin', label: 'T:Min. employees', value: '5' },
			{ key: 'legalForms', label: 'T:Legal forms', value: 'BV' },
			{ key: 'excludeInactive', label: 'T:Exclude inactive companies', value: 'T:No' },
			{ key: 'keywords', label: 'T:Keywords', value: 'cloud' },
		])
		expect(icpFacts({ employeeCountMax: 9, excludeInactive: true, keywords: [] }, translate)).toEqual([
			{ key: 'employeeCountMax', label: 'T:Max. employees', value: '9' },
			{ key: 'excludeInactive', label: 'T:Exclude inactive companies', value: 'T:Yes' },
		])
	})

	it('says nothing without a filled-in base fact', () => {
		expect(icpFacts(null, translate)).toEqual([])
		expect(icpFacts('x', translate)).toEqual([])
		expect(icpFacts({ excludeInactive: true, keywords: ['a'], sbiCodes: 'not-a-list' }, translate)).toEqual([])
		expect(icpFacts({ provinces: ['Utrecht'], excludeInactive: 'yes' }, translate)).toEqual([
			{ key: 'provinces', label: 'T:Provinces', value: 'Utrecht' },
		])
	})
})

describe('blastNote', () => {
	it('says how far a sending newsletter is', () => {
		expect(blastNote(null, { status: 'sending', totals: { queued: 3, delivered: 5, bounced: 'x', clicked: 2 } })).toBe('7 of 10 sent')
		expect(blastNote(null, { status: 'sending' })).toBe('0 of 0 sent')
	})

	it('says what else is worth knowing, in order', () => {
		expect(blastNote(null, { status: 'scheduled', skippedNoConsent: 4, abSplitPercent: 20 })).toBe('4 without consent, not sent')
		expect(blastNote(null, { status: 'scheduled', skippedNoConsent: 'x', abSplitPercent: '20' })).toBe('A/B test, variant A 20%')
		expect(blastNote(null, { status: 'scheduled', abSplitPercent: 0 })).toBe('Rules checked')
		expect(blastNote(null, { status: 'sent' })).toBe('')
		expect(blastNote(null, null)).toBe('')
	})
})
