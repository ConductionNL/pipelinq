/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The newsletter results page as boards PqNieuwsbriefPrestaties (Overzicht),
 * PqNieuwsbriefABTest and PqNieuwsbriefToeschrijving draw it.
 *
 * @spec openspec/changes/newsletter-results-board/specs/marketing/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	chiSquarePValue,
	higherVariant,
	variantSplit,
	variantStats,
	wholePercent,
} from '../../src/services/abTest.js'

const view = readFileSync(
	resolve(__dirname, '../../src/views/blasts/PerformanceDashboard.vue'),
	'utf8',
)

describe('A/B arithmetic', () => {
	it('reads delivered, clicked, click rate and subject of a variant', () => {
		expect(variantStats({ subject: 'S', totals: { delivered: 200, clicked: 50 } }))
			.toEqual({ delivered: 200, clicked: 50, clickRate: 0.25, subject: 'S' })
		expect(variantStats({}).clickRate).toBe(0)
	})

	it('gives a small p-value for clearly different click rates and null for a degenerate table', () => {
		expect(chiSquarePValue(60, 600, 12, 600)).toBeLessThan(0.001)
		expect(chiSquarePValue(30, 500, 31, 500)).toBeGreaterThan(0.5)
		expect(chiSquarePValue(0, 500, 0, 500)).toBeNull()
		expect(chiSquarePValue(1, 0, 1, 10)).toBeNull()
	})

	it('names the variant with the higher click rate', () => {
		expect(higherVariant({ clickRate: 0.15 }, { clickRate: 0.26 })).toBe('b')
		expect(higherVariant({ clickRate: 0.3 }, { clickRate: 0.2 })).toBe('a')
		expect(higherVariant({ clickRate: 0.2 }, { clickRate: 0.2 })).toBeNull()
	})

	it('splits the delivered mail into whole shares that add up to 100', () => {
		expect(variantSplit({ delivered: 203 }, { delivered: 201 })).toEqual({ a: 50, b: 50 })
		expect(variantSplit({ delivered: 0 }, { delivered: 0 })).toEqual({ a: 50, b: 50 })
		expect(variantSplit({ delivered: 1 }, { delivered: 2 })).toEqual({ a: 33, b: 67 })
	})

	it('prints rates as whole percentages, as the board does', () => {
		expect(wholePercent(0.5812)).toBe('58%')
		expect(wholePercent(Number.NaN)).toBe('—')
	})
})

describe('the page header (board PqNieuwsbriefPrestaties)', () => {
	it('is a CnPageHeader titled as the board, with Download and buildiq beside it', () => {
		expect(view).toMatch(/<CnPageHeader[\s\S]*?'Results of your sends'/)
		expect(view).toMatch(/<template #extra>[\s\S]*?'Download'[\s\S]*?<CnBuildiqEditButton/)
		expect(view).not.toMatch(/<h2>\{\{ t\('pipelinq', 'Blast performance'\)/)
	})

	it('takes the page look', () => {
		expect(view).toMatch(/:class="lookClass"/)
		expect(view).toMatch(/useLook\(\)/)
	})

	it('puts an Actions menu beside the tab strip, outside the tablist', () => {
		expect(view).toMatch(/<template #nav-end>[\s\S]*?<CnActionsMenu/)
	})

	it('colours the status pill by status', () => {
		expect(view).toMatch(/:colorKey="row.status"/)
		expect(view).toMatch(/sent: 'success'/)
	})
})

describe('the A/B-test tab (board PqNieuwsbriefABTest)', () => {
	it('draws the variant cards with the subject and the higher-click-rate pill', () => {
		expect(view).toMatch(/performance-dashboard__ab-variant--winner/)
		expect(view).toMatch(/'Higher click rate'/)
		expect(view).toMatch(/'Subject: \{subject\}'/)
	})

	it('marks a winner only once each variant has at least 500 delivered and a day has passed', () => {
		expect(view).toMatch(/const AB_MIN_DELIVERED = 500/)
		expect(view).toMatch(/const winner = eligible \? higherVariant\(a, b\) : null/)
		expect(view).toMatch(/v-if="!pair.eligible"[\s\S]*?'Results not yet available/)
	})

	it('shows the first test in full and every later one as a summary line', () => {
		expect(view).toMatch(/performance-dashboard__ab-summary/)
	})
})

describe('the Toeschrijving tab (board PqNieuwsbriefToeschrijving)', () => {
	it('titles the attribution table and explains the traffic table', () => {
		expect(view).toMatch(/performance-dashboard__section-title[\s\S]*?'Attribution to deals'/)
		expect(view).toMatch(/'Portal sessions that came in through a link in the mail'/)
	})
})
