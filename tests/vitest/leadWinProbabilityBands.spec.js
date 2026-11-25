/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The win probability bands, read off the real `winProbability` expression in
 * lib/Settings/pipelinq_register.json: full up to 14 days untouched, 80% up to
 * 30, 50% up to 60, 25% after that, and zero stays zero. The decay is keyed on
 * `@self.updated`, which no client may set, so these bands are proven here
 * rather than on a fresh install (lead-win-probability-recency task 3.1).
 *
 * The evaluator knows only the operators the expression uses; a new operator
 * fails this test loudly instead of being guessed.
 *
 * @spec openspec/specs/lead-scoring-win-probability/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const register = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/pipelinq_register.json'), 'utf8'))
const calculation = register.components.schemas.lead.configuration['x-openregister-calculations'].winProbability

const NOW = new Date('2026-11-17T12:00:00Z')
const DAY = 24 * 60 * 60 * 1000

/**
 * Evaluate one expression node for a lead at NOW.
 *
 * @param {*} node Expression node.
 * @param {object} lead The lead, with `@self.updated`.
 * @return {*}
 */
function evaluate(node, lead) {
	if (node === null || typeof node !== 'object') return node
	const [op] = Object.keys(node)
	const args = node[op]
	const val = (i) => evaluate(args[i], lead)
	switch (op) {
		case 'prop':
			return lead[args] ?? null
		case 'if':
			return val(0) ? val(1) : val(2)
		case 'gt':
			return Number(val(0)) > Number(val(1))
		case '*':
			return Number(val(0)) * Number(val(1))
		case '/':
			return Number(val(0)) / Number(val(1))
		case 'dateDiff': {
			expect(args.unit).toBe('days')
			expect(args.to).toBe('now')
			const from = new Date(evaluate(args.from, lead))
			return Math.floor((NOW - from) / DAY)
		}
		default:
			throw new Error('operator not covered by the band test: ' + op)
	}
}

/**
 * A lead with the given probability, last touched `days` days before NOW.
 *
 * @param {number} probability The stage probability.
 * @param {number} days Days untouched.
 * @return {object}
 */
function lead(probability, days) {
	return { probability, '@self.updated': new Date(NOW - days * DAY).toISOString() }
}

describe('the win probability bands', () => {
	it('is computed on read, not stored', () => {
		expect(calculation.materialise).toBe(false)
	})

	it.each([
		[0, 60],
		[14, 60],
		[15, 48],
		[30, 48],
		[31, 30],
		[45, 30],
		[60, 30],
		[61, 15],
		[120, 15],
	])('a lead of 60%% untouched %i days reads %i', (days, expected) => {
		expect(evaluate(calculation.expression, lead(60, days))).toBe(expected)
	})

	it('keeps a zero probability at zero however old the lead is', () => {
		expect(evaluate(calculation.expression, lead(0, 0))).toBe(0)
		expect(evaluate(calculation.expression, lead(0, 90))).toBe(0)
	})
})
