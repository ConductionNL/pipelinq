/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lead score in the list and on the board (pipeline-lead-score-call-first).
 *
 * The drift test reads the REAL `x-openregister-calculations.qualificationScore`
 * expression from lib/Settings/pipelinq_register.json, evaluates it for fixture
 * leads with a small evaluator of the operators it uses, and asserts the
 * browser explanation lists the same total. When somebody changes a weight in
 * the register and not in src/services/leadScore.js, this fails.
 *
 * @spec openspec/changes/pipeline-lead-score-call-first/specs/lead-management/spec.md#requirement-a-person-can-see-why-a-lead-has-its-score-req-lscore-003
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	CALL_FIRST_SORT,
	SCORE_CRITERIA,
	compareCallFirst,
	explainScore,
	scoreBand,
} from '../../src/services/leadScore.js'

const register = JSON.parse(
	readFileSync(resolve(__dirname, '../../lib/Settings/pipelinq_register.json'), 'utf8'),
)
const calculation =
	register.components.schemas.lead.configuration['x-openregister-calculations']
		.qualificationScore

/**
 * Evaluate the JSON-logic subset the calculation uses. Unknown operators
 * throw, so a new operator in the register fails this test loudly.
 *
 * @param {*} node Expression node.
 * @param {object} lead The lead.
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
		case '+':
			return args.reduce((sum, a) => sum + Number(evaluate(a, lead)), 0)
		case 'if':
			return val(0) ? val(1) : val(2)
		case 'gt':
			return val(0) !== null && Number(val(0)) > Number(val(1))
		case 'ne':
			return val(0) !== val(1)
		case 'eq':
			return val(0) === val(1)
		case 'or':
			return args.some((a) => evaluate(a, lead))
		default:
			throw new Error('operator not covered by the drift test: ' + op)
	}
}

const FIXTURES = [
	{ title: 'empty' },
	{ value: 5000, client: 'c-1', expectedCloseDate: '2026-10-01' },
	{ value: 25000, client: 'c-1', contact: 'p-1', source: 'referral', expectedCloseDate: '2026-10-01', priority: 'urgent', description: 'Wants a demo' },
	{ value: 12000, source: 'partner', priority: 'high', description: 'x' },
	{ value: 0, contact: 'p-2', source: 'website', priority: 'normal' },
]

describe('the explanation matches the register calculation', () => {
	it('uses only the operators the evaluator knows, and has eight terms', () => {
		expect(calculation.expression['+']).toHaveLength(SCORE_CRITERIA.length)
	})

	it.each(FIXTURES.map((f, i) => [i, f]))('fixture %i totals the same', (_i, lead) => {
		expect(explainScore(lead).total).toBe(evaluate(calculation.expression, lead))
	})

	it('explains a lead with a value, a client and a close date as 35', () => {
		const { matched, total } = explainScore(FIXTURES[1])
		expect(matched.map((c) => `${c.label} +${c.points}`)).toEqual([
			'Value present +10',
			'Client linked +15',
			'Expected close date set +10',
		])
		expect(total).toBe(35)
	})
})

describe('score bands', () => {
	it.each([
		[85, 'high'],
		[70, 'high'],
		[69, 'medium'],
		[40, 'medium'],
		[39, 'low'],
		[10, 'low'],
		[0, 'low'],
		['55', 'medium'],
	])('%s is %s', (score, band) => {
		expect(scoreBand(score)).toBe(band)
	})

	it.each([null, undefined, '', 'abc'])('%s has no band', (score) => {
		expect(scoreBand(score)).toBeNull()
	})
})

describe('call first', () => {
	it('orders 85, 40, 10 with leads without a score last', () => {
		const leads = [
			{ id: 'b', qualificationScore: 40 },
			{ id: 'none', qualificationScore: null },
			{ id: 'c', qualificationScore: 10 },
			{ id: 'a', qualificationScore: 85 },
		]
		expect([...leads].sort(compareCallFirst).map((l) => l.id)).toEqual([
			'a',
			'b',
			'c',
			'none',
		])
	})

	it('breaks a tie with the lead updated longest ago', () => {
		const newer = { id: 'newer', qualificationScore: 50, '@self': { updated: '2026-09-20T10:00:00Z' } }
		const older = { id: 'older', qualificationScore: 50, '@self': { updated: '2026-09-01T10:00:00Z' } }
		expect([newer, older].sort(compareCallFirst).map((l) => l.id)).toEqual(['older', 'newer'])
	})

	it('asks OpenRegister for score descending, then oldest update', () => {
		expect(CALL_FIRST_SORT).toEqual([
			{ key: 'qualificationScore', order: 'desc' },
			{ key: '@self.updated', order: 'asc' },
		])
	})
})
