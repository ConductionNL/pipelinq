// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Characterisation tests for src/services/complaintStatus.js and
 * src/services/requestStatus.js — the two status lifecycles that share their
 * lookup functions. Every exported function is pinned for every status and
 * priority the module declares, plus an unknown value, so the shared helper
 * both modules use returns exactly what each module returned on its own.
 */

import { beforeAll, describe, expect, it } from 'vitest'

let complaint
let request

beforeAll(async () => {
	globalThis.t = (app, text) => `${app}:${text}`
	complaint = await import('../../src/services/complaintStatus.js')
	request = await import('../../src/services/requestStatus.js')
})

/**
 * Apply each lookup to every value plus an unknown one.
 *
 * @param {object} mod The lifecycle module.
 * @param {string[]} values The values to look up.
 * @return {object} name → value → result
 */
function lookups(mod, values) {
	const all = [...values, 'unknown', '']
	const names = [
		'getAllowedTransitions',
		'isTerminalStatus',
		'getStatusLabel',
		'getStatusColor',
		'getPriorityLabel',
		'getPriorityColor',
		'isValidPriority',
	]
	return Object.fromEntries(
		names.map((name) => [name, Object.fromEntries(all.map((v) => [v, mod[name](v)]))]),
	)
}

/**
 * isValidTransition over every (from, to) pair including an unknown value.
 *
 * @param {object} mod The lifecycle module.
 * @return {string[]} The pairs that are valid.
 */
function validPairs(mod) {
	const all = [...Object.keys(mod.STATUS_TRANSITIONS), 'unknown']
	return all.flatMap((from) => all.filter((to) => mod.isValidTransition(from, to)).map((to) => `${from}>${to}`))
}

describe('requestStatus', () => {
	it('keeps its tables', () => {
		expect(request.STATUS_TRANSITIONS).toEqual({
			new: ['in_progress', 'awaiting_customer', 'rejected', 'completed'],
			in_progress: ['awaiting_customer', 'completed', 'rejected', 'converted'],
			awaiting_customer: ['in_progress', 'completed', 'rejected'],
			completed: [],
			rejected: [],
			converted: [],
		})
		expect(request.VALID_PRIORITIES).toEqual(['low', 'normal', 'high', 'urgent'])
		expect(Object.keys(request.STATUS_LABELS)).toEqual(Object.keys(request.STATUS_TRANSITIONS))
		expect(request.PRIORITY_COLORS.high).toBe('var(--color-warning-text)')
	})

	it('answers every lookup as before', () => {
		const values = [...Object.keys(request.STATUS_TRANSITIONS), ...request.VALID_PRIORITIES]
		const result = lookups(request, values)
		expect(result.getAllowedTransitions.unknown).toEqual([])
		expect(result.isTerminalStatus.completed).toBe(true)
		expect(result.isTerminalStatus.new).toBe(false)
		expect(result.isTerminalStatus.unknown).toBe(true)
		expect(result.getStatusLabel.awaiting_customer).toBe('pipelinq:Waiting for customer')
		expect(result.getStatusLabel.unknown).toBe('unknown')
		expect(result.getStatusColor.converted).toBe('#745bca')
		expect(result.getStatusColor.unknown).toBe('#999')
		expect(result.getPriorityLabel.urgent).toBe('pipelinq:Urgent')
		expect(result.getPriorityLabel.unknown).toBe('unknown')
		expect(result.getPriorityColor.urgent).toBe('var(--color-text-error)')
		expect(result.getPriorityColor.unknown).toBe('var(--color-text-maxcontrast)')
		expect(result.isValidPriority.high).toBe(true)
		expect(result.isValidPriority.unknown).toBe(false)
		expect(result).toMatchSnapshot()
	})

	it('validates transitions as before', () => {
		expect(validPairs(request)).toEqual([
			'new>new', 'new>in_progress', 'new>awaiting_customer', 'new>completed', 'new>rejected',
			'in_progress>in_progress', 'in_progress>awaiting_customer', 'in_progress>completed',
			'in_progress>rejected', 'in_progress>converted',
			'awaiting_customer>in_progress', 'awaiting_customer>awaiting_customer',
			'awaiting_customer>completed', 'awaiting_customer>rejected',
			'completed>completed', 'rejected>rejected', 'converted>converted', 'unknown>unknown',
		])
	})
})

describe('complaintStatus', () => {
	it('keeps its tables', () => {
		expect(complaint.STATUS_TRANSITIONS).toEqual({
			new: ['in_progress'],
			in_progress: ['resolved', 'rejected'],
			resolved: [],
			rejected: [],
		})
		expect(complaint.VALID_PRIORITIES).toEqual(['low', 'normal', 'high', 'urgent'])
		expect(complaint.VALID_CATEGORIES).toEqual(['service', 'product', 'communication', 'billing', 'other'])
		expect(complaint.VALID_CHANNELS).toEqual(['phone', 'email', 'web', 'counter', 'letter', 'other'])
		expect(complaint.PRIORITY_COLORS.low).toBe('#999')
	})

	it('answers every lookup as before', () => {
		const values = [...Object.keys(complaint.STATUS_TRANSITIONS), ...complaint.VALID_PRIORITIES]
		const result = lookups(complaint, values)
		expect(result.getStatusColor.resolved).toBe('#46ba61')
		expect(result.getStatusColor.unknown).toBe('#999')
		expect(result.getPriorityColor.high).toBe('#e9a400')
		expect(result.getPriorityColor.unknown).toBe('var(--color-text-maxcontrast)')
		expect(result.isTerminalStatus.resolved).toBe(true)
		expect(result).toMatchSnapshot()
	})

	it('validates transitions as before', () => {
		expect(validPairs(complaint)).toEqual([
			'new>new', 'new>in_progress',
			'in_progress>in_progress', 'in_progress>resolved', 'in_progress>rejected',
			'resolved>resolved', 'rejected>rejected', 'unknown>unknown',
		])
	})

	it('keeps its complaint-only helpers', () => {
		expect(complaint.requiresResolution('resolved')).toBe(true)
		expect(complaint.requiresResolution('rejected')).toBe(true)
		expect(complaint.requiresResolution('new')).toBe(false)
		expect(complaint.getCategoryLabel('billing')).toBe('pipelinq:Billing')
		expect(complaint.getCategoryLabel('x')).toBe('x')
		expect(complaint.getChannelLabel('counter')).toBe('pipelinq:Counter')
		expect(complaint.getChannelLabel('x')).toBe('x')
		expect(complaint.getSlaIndicator(null, 'new')).toBeNull()
		expect(complaint.getSlaIndicator('2000-01-01T00:00:00Z', 'resolved')).toBe('met')
		expect(complaint.getSlaIndicator('2000-01-01T00:00:00Z', 'new')).toBe('overdue')
		expect(complaint.getSlaIndicator(new Date(Date.now() + 3600000).toISOString(), 'new')).toBe('approaching')
		expect(complaint.getSlaIndicator(new Date(Date.now() + 86400000).toISOString(), 'new')).toBe('on_track')
		expect(complaint.getSlaColor('met')).toBe('#46ba61')
		expect(complaint.getSlaColor('approaching')).toBe('#e9a400')
		expect(complaint.getSlaColor('overdue')).toBe('#e9322d')
		expect(complaint.getSlaColor('x')).toBe('var(--color-text-maxcontrast)')
	})
})
