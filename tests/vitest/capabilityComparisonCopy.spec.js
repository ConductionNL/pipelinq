/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words on the "How pipelinq compares" docs page.
 *
 * The comparison moved from the in-app Features & roadmap page to
 * pipelinq.conduction.nl/compare on 2026-10-07. These are the caveat
 * assertions the in-app section carried, pointed at
 * `docs/src/components/CapabilityComparison/comparisonCopy.js`, the module the
 * docs page renders from. It is pure, so this runs in node with no Docusaurus
 * and no React.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
 */

import { describe, expect, it } from 'vitest'
import {
	areaSummary,
	comparisonCopy,
	NL,
	ratingLabel,
	translate,
} from '../../docs/src/components/CapabilityComparison/comparisonCopy.js'
import data from '../../src/data/capabilityComparison.json'
import { groupByArea } from '../../src/utils/capabilityComparison.js'

const copy = comparisonCopy(data, 'en')
const caveats = copy.caveats.join('\n')

describe('comparison caveats on the docs page', () => {
	it('keeps all four mandatory caveats', () => {
		expect(caveats).toContain('open source software we could install and run')
		expect(caveats).toContain('already out of date')
		expect(caveats).toContain('is not proof that a product does')
		expect(caveats).toContain('run your own evaluation')
	})

	it('says that only our own column is ever corrected', () => {
		expect(caveats).toContain('we correct our own column only')
	})

	it('declares that the list is written in our own shape', () => {
		expect(caveats).toContain('that bias runs in our favour')
	})

	it('dates the reading in the reader-s own language', () => {
		const english = new Intl.DateTimeFormat('en', {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'UTC',
		}).format(new Date(`${data.comparedOn}T00:00:00Z`))
		expect(caveats).toContain(english)
		expect(caveats).not.toContain(data.comparedOn)
	})

	it('names both rivals and the real row count in the lead', () => {
		expect(copy.leadText).toContain(
			`on ${data.capabilities.length} capabilities`,
		)
		for (const rival of data.systems.filter((system) => !system.isSelf)) {
			expect(copy.leadText).toContain(rival.name)
		}
	})

	it('leaves no empty caveat and no unfilled placeholder behind', () => {
		for (const caveat of copy.caveats) {
			expect(caveat.trim()).not.toBe('')
			expect(caveat).not.toMatch(/\{\w+\}/)
		}
	})
})

describe('comparison copy in Dutch', () => {
	it('has a Dutch string for every caveat and every label', () => {
		const dutch = comparisonCopy(data, 'nl')
		expect(dutch.caveats).toHaveLength(copy.caveats.length)
		dutch.caveats.forEach((caveat, index) => {
			expect(caveat).not.toBe(copy.caveats[index])
			expect(caveat).not.toMatch(/\{\w+\}/)
		})
		expect(dutch.leadText).not.toBe(copy.leadText)
		for (const rating of ['yes', 'partial', 'no', 'unknown']) {
			expect(ratingLabel('nl', rating)).not.toBe(ratingLabel('en', rating))
		}
	})

	it('falls back to English for a string without a Dutch entry', () => {
		expect(translate('nl', 'No Dutch for {x}', { x: 1 })).toBe('No Dutch for 1')
		expect(Object.values(NL).every((value) => value.trim() !== '')).toBe(true)
	})

	it('summarises an area in the reader-s language', () => {
		const english = areaSummary('en', groupByArea(data, 'en')[0])
		const dutch = areaSummary('nl', groupByArea(data, 'nl')[0])
		expect(english).toContain('Pipelinq has')
		expect(dutch).not.toBe(english)
	})
})
