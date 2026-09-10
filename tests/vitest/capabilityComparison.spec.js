/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Guards the help desk comparison shown on the Features & roadmap page.
 *
 * The data file is written ONCE, offline, from a reading of two products we
 * installed and drove, so CI cannot regenerate it and diff the result. These
 * assertions are the only thing standing between a hand-edit and a page that
 * quietly claims something the reading never said.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-data-must-match-the-reading-it-came-from
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-rival-rating-must-name-the-reading-it-came-from
 */

import { describe, expect, it } from 'vitest'
import data from '../../src/data/capabilityComparison.json'
import {
	behindEveryRival,
	formatComparedOn,
	groupByArea,
	labelFor,
	overallTallies,
	RATINGS,
	tally,
} from '../../src/utils/capabilityComparison.js'

// The totals the reading produced. Hard-coded on purpose: if a row is edited,
// one of these fails and names the system whose score moved.
const READING_TOTALS = {
	pipelinq: { yes: 35, partial: 54, no: 51 },
	glpi: { yes: 70, partial: 52, no: 18 },
	zammad: { yes: 62, partial: 53, no: 25 },
}

// How many rows each area holds. A row silently refiled under a neighbouring
// area moves no total and changes no score, so nothing above would catch it.
const AREA_SIZES = {
	intake: 13,
	'ticket-core': 21,
	'agent-workflow': 15,
	'service-levels': 13,
	communication: 11,
	'self-service': 4,
	attachments: 8,
	'search-and-views': 11,
	reporting: 9,
	configuration: 14,
	integrations: 9,
	access: 12,
}

// The rows where BOTH rivals have the capability and we do not. Pinned rather
// than asserted empty, because it is not empty and it is the most useful list
// on the page: it is the backlog this comparison was built to produce. The
// list shrinks as pipelinq ships, and this test is what makes each of those
// moves visible instead of a page quietly claiming a clean sheet.
const BEHIND_EVERY_RIVAL = [
	'1.4',
	'1.7',
	'2.2',
	'2.4',
	'2.10',
	'2.13',
	'2.20',
	'3.2',
	'4.2',
	'4.3',
	'4.8',
	'5.1',
	'5.2',
	'5.3',
	'5.9',
	'6.1',
	'7.1',
	'8.1',
	'8.5',
	'8.9',
	'9.6',
	'9.8',
	'10.3',
	'10.6',
	'10.9',
	'10.10',
	'12.1',
	'12.2',
	'12.11',
]

describe('capabilityComparison data', () => {
	it('carries the 140 rows, 12 areas and 3 systems the reading produced', () => {
		expect(data.capabilities).toHaveLength(140)
		expect(data.areas).toHaveLength(12)
		expect(data.systems).toHaveLength(3)
	})

	it('gives every row a unique id', () => {
		const ids = data.capabilities.map((c) => c.id)
		expect(new Set(ids).size).toBe(ids.length)
	})

	it('files every row under a declared area, and keeps the areas the size they were', () => {
		const areas = new Set(data.areas.map((a) => a.key))
		const orphans = data.capabilities.filter((c) => !areas.has(c.area))
		expect(orphans.map((c) => c.id)).toEqual([])

		const sizes = {}
		for (const row of data.capabilities) {
			sizes[row.area] = (sizes[row.area] ?? 0) + 1
		}
		expect(sizes).toEqual(AREA_SIZES)
	})

	it('names the question every rival rating came from', () => {
		// Without a source, a rival cell is a claim nobody can check, which is
		// the failure the whole page exists to avoid.
		const sourceless = data.capabilities.filter(
			(c) => typeof c.source !== 'string' || c.source.trim() === '',
		)
		expect(sourceless.map((c) => c.id)).toEqual([])

		const sources = data.capabilities.map((c) => c.source)
		expect(new Set(sources).size).toBe(sources.length)
	})

	it('rates every system on every row with a known rating', () => {
		const bad = []
		for (const row of data.capabilities) {
			for (const system of data.systems) {
				const rating = row[system.key]
				if (!RATINGS.includes(rating) && rating !== 'unknown') {
					bad.push(`${row.id}/${system.key}=${rating}`)
				}
			}
		}
		expect(bad).toEqual([])
	})

	it('never leaves our own column unknown', () => {
		// We can read our own code, so an empty cell here is an unfinished row
		// that understates our own score for free.
		const self = data.systems.find((system) => system.isSelf)
		const blank = data.capabilities.filter((c) => !RATINGS.includes(c[self.key]))
		expect(blank.map((c) => c.id)).toEqual([])
	})

	it('pins every rival unknown to a row a later round added', () => {
		// `unknown` has exactly one honest cause: the row was added after those
		// products were read. Anything else is either a guess or an omission.
		const rivals = data.systems.filter((system) => !system.isSelf)
		const wrong = data.capabilities.filter((row) => {
			const anyUnknown = rivals.some((r) => row[r.key] === 'unknown')
			const allUnknown = rivals.every((r) => row[r.key] === 'unknown')
			if (row.addedOn) {
				return !allUnknown
			}
			return anyUnknown
		})
		expect(wrong.map((row) => row.id)).toEqual([])
	})

	it('reproduces the reading tallies for all three systems', () => {
		const totals = overallTallies(data)
		for (const [system, expected] of Object.entries(READING_TOTALS)) {
			expect(
				{
					yes: totals[system].yes,
					partial: totals[system].partial,
					no: totals[system].no,
				},
				system,
			).toEqual(expected)
			expect(totals[system].total, system).toBe(140)
		}
	})

	it('drops no row that at least one system answers', () => {
		// A row every system answers `no` measures a specification rather than
		// a product, and eight such rows were parked before publication. This
		// asserts none survived, so the list a reader sees is one where every
		// row separates the three systems.
		const useless = data.capabilities.filter((row) =>
			data.systems.every((system) => row[system.key] === 'no'),
		)
		expect(useless.map((row) => row.id)).toEqual([])
	})

	it('translates every capability and every area into Dutch', () => {
		const missing = data.capabilities.filter(
			(c) => !c.name_nl || c.name_nl.trim() === '',
		)
		expect(missing.map((c) => c.id)).toEqual([])

		// Every row here differs between the two languages. A row added without
		// a translation is caught by this rather than shipping English into a
		// Dutch table.
		const untranslated = data.capabilities.filter((c) => c.name_nl === c.name)
		expect(untranslated.map((c) => c.id)).toEqual([])

		const areasMissing = data.areas.filter(
			(a) => !a.name_nl || a.name_nl.trim() === '',
		)
		expect(areasMissing.map((a) => a.key)).toEqual([])
	})

	it('records when the reading was made, and against which revision of us', () => {
		expect(data.comparedOn).toMatch(/^\d{4}-\d{2}-\d{2}$/)
		expect(data.pipelinqRevision).toMatch(/^[0-9a-f]{7,40}$/)
	})

	it('corrects our own column only', () => {
		const self = data.systems.find((system) => system.isSelf)
		const byId = new Map(data.capabilities.map((c) => [c.id, c]))
		for (const entry of data._rerated ?? []) {
			expect(entry.from, entry.id).not.toBe(entry.to)
			expect(RATINGS, entry.id).toContain(entry.to)
			expect(entry.on, entry.id).toMatch(/^\d{4}-\d{2}-\d{2}$/)
			expect(entry.reason.length, entry.id).toBeGreaterThan(20)
			// The note must still describe the row it sits beside, and it may
			// only ever describe OUR column.
			expect(byId.get(entry.id)?.[self.key], entry.id).toBe(entry.to)
		}
	})
})

describe('behindEveryRival', () => {
	it('names the rows both rivals have and we do not', () => {
		expect(behindEveryRival(data).map((row) => row.id)).toEqual(
			BEHIND_EVERY_RIVAL,
		)
	})

	it('counts a partial on our side as behind', () => {
		const shaped = {
			systems: data.systems,
			capabilities: [
				{ id: 'x', pipelinq: 'partial', glpi: 'yes', zammad: 'yes' },
			],
		}
		expect(behindEveryRival(shaped).map((row) => row.id)).toEqual(['x'])
	})

	it('does not count a row one rival merely half has', () => {
		const shaped = {
			systems: data.systems,
			capabilities: [
				{ id: 'x', pipelinq: 'no', glpi: 'yes', zammad: 'partial' },
			],
		}
		expect(behindEveryRival(shaped)).toEqual([])
	})
})

describe('labelFor', () => {
	const entry = {
		name: 'Merge two tickets into one',
		name_nl: 'Twee tickets samenvoegen tot een',
	}

	it('returns Dutch for a Dutch locale', () => {
		expect(labelFor(entry, 'nl')).toBe('Twee tickets samenvoegen tot een')
		expect(labelFor(entry, 'nl_NL')).toBe('Twee tickets samenvoegen tot een')
	})

	it('returns English for anything else', () => {
		expect(labelFor(entry, 'en')).toBe('Merge two tickets into one')
		expect(labelFor(entry, 'de')).toBe('Merge two tickets into one')
	})

	it('falls back to English when the Dutch is missing or blank', () => {
		expect(labelFor({ name: 'A', name_nl: '' }, 'nl')).toBe('A')
		expect(labelFor({ name: 'A' }, 'nl')).toBe('A')
	})
})

describe('tally', () => {
	it('counts an unknown rating instead of dropping it', () => {
		const counts = tally([{ x: 'yes' }, { x: 'wat' }, { x: 'no' }], 'x')
		expect(counts).toEqual({ yes: 1, partial: 0, no: 1, unknown: 1, total: 3 })
	})
})

describe('groupByArea', () => {
	it('keeps the declared ordering, intake first and access last', () => {
		const groups = groupByArea(data, 'en')
		expect(groups[0].label).toBe('Intake and channels')
		expect(groups[groups.length - 1].label).toBe('Access and privacy')
	})

	it('places every row in exactly one area group', () => {
		const groups = groupByArea(data, 'en')
		const total = groups.reduce((n, g) => n + g.capabilities.length, 0)
		expect(total).toBe(140)
	})

	it('labels the groups and their rows in Dutch for a Dutch locale', () => {
		const groups = groupByArea(data, 'nl')
		const levels = groups.find((g) => g.key === 'service-levels')
		expect(levels.label).toBe('Serviceniveaus en termijnen')
		expect(levels.capabilities.every((c) => c.label === c.name_nl)).toBe(true)
	})

	it('tallies each area per system', () => {
		const groups = groupByArea(data, 'en')
		const intake = groups.find((g) => g.key === 'intake')
		expect(intake.capabilities).toHaveLength(13)
		expect(intake.tallies.pipelinq.total).toBe(13)
	})
})

describe('formatComparedOn', () => {
	it('renders the date in the reader-s language', () => {
		expect(formatComparedOn('2026-09-09', 'en')).toContain('2026')
		expect(formatComparedOn('2026-09-09', 'nl')).toContain('september')
	})

	it('returns the raw value when it is not a date', () => {
		expect(formatComparedOn('not-a-date', 'en')).toBe('not-a-date')
		expect(formatComparedOn('', 'en')).toBe('')
	})
})
