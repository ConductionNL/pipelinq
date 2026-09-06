// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/subscriptionState.js — the chip vocabulary,
 * the per-state counts and the embed snippet the mailing-list surfaces share.
 *
 * The unknown-state case is the one that matters most: a state this build has
 * never seen must never come back reachable, because "reachable" is what the
 * interface uses to tell a marketer they may mail someone.
 */

import { describe, expect, it } from 'vitest'
import {
	chipForState,
	countByState,
	embedSnippet,
	isReachable,
	SUBSCRIPTION_STATES,
} from '../../src/services/subscriptionState.js'

describe('chipForState', () => {
	it('gives every declared state a label and a themed colour', () => {
		for (const state of SUBSCRIPTION_STATES) {
			const chip = chipForState(state)
			expect(chip.label).toBeTruthy()
			expect(chip.color).toMatch(/^var\(--color-/)
			expect(typeof chip.reachable).toBe('boolean')
		}
	})

	it('marks only a confirmed membership reachable', () => {
		expect(chipForState('confirmed').reachable).toBe(true)
		expect(chipForState('pending').reachable).toBe(false)
		expect(chipForState('unsubscribed').reachable).toBe(false)
		expect(chipForState('bounced').reachable).toBe(false)
	})

	it('falls back to an unreachable chip for a state it has never seen', () => {
		const chip = chipForState('suppressed-by-provider')
		expect(chip.label).toBe('Unknown')
		expect(chip.reachable).toBe(false)
	})

	it('falls back for a missing state rather than throwing', () => {
		expect(chipForState(undefined).reachable).toBe(false)
		expect(chipForState(null).reachable).toBe(false)
		expect(chipForState('').reachable).toBe(false)
	})

	it('returns a copy, so a caller cannot edit the shared vocabulary', () => {
		const first = chipForState('confirmed')
		first.label = 'Tampered'
		expect(chipForState('confirmed').label).toBe('Subscribed')
	})
})

describe('isReachable', () => {
	it('agrees with the chip it reads', () => {
		expect(isReachable('confirmed')).toBe(true)
		expect(isReachable('pending')).toBe(false)
		expect(isReachable('nonsense')).toBe(false)
	})
})

describe('countByState', () => {
	it('reports every declared state even when the list is empty', () => {
		const counts = countByState([])
		expect(counts).toEqual({
			pending: 0,
			confirmed: 0,
			unsubscribed: 0,
			bounced: 0,
			total: 0,
		})
	})

	it('counts a realistic mix', () => {
		const counts = countByState([
			{ state: 'confirmed' },
			{ state: 'confirmed' },
			{ state: 'pending' },
			{ state: 'unsubscribed' },
			{ state: 'bounced' },
		])
		expect(counts.confirmed).toBe(2)
		expect(counts.pending).toBe(1)
		expect(counts.unsubscribed).toBe(1)
		expect(counts.bounced).toBe(1)
		expect(counts.total).toBe(5)
	})

	it('counts an unknown state in the total but in no bucket', () => {
		const counts = countByState([
			{ state: 'suppressed' },
			{ state: 'confirmed' },
		])
		expect(counts.total).toBe(2)
		expect(counts.confirmed).toBe(1)
		expect(counts.pending).toBe(0)
	})

	it('survives a non-array and rows with no state', () => {
		expect(countByState(null).total).toBe(0)
		expect(countByState(undefined).total).toBe(0)
		expect(countByState([{}, null]).total).toBe(2)
	})
})

describe('embedSnippet', () => {
	it('posts at the public subscribe endpoint for the list', () => {
		const snippet = embedSnippet('https://crm.example.org', 'list-abc')
		expect(snippet).toContain(
			'action="https://crm.example.org/index.php/apps/pipelinq/api/lists/list-abc/subscribe"',
		)
		expect(snippet).toContain('method="post"')
	})

	it('carries the honeypot, hidden from sight and from assistive tech', () => {
		const snippet = embedSnippet('https://crm.example.org', 'list-abc')
		expect(snippet).toContain('name="website"')
		expect(snippet).toContain('aria-hidden="true"')
		expect(snippet).toContain('tabindex="-1"')
	})

	it('labels the email field, so the form is usable with a screen reader', () => {
		const snippet = embedSnippet('https://crm.example.org', 'list-abc')
		expect(snippet).toContain('<label for="pipelinq-email">')
		expect(snippet).toContain('id="pipelinq-email"')
	})

	it('trims a trailing slash off the base URL rather than doubling it', () => {
		const snippet = embedSnippet('https://crm.example.org/', 'list-abc')
		expect(snippet).not.toContain('org//index.php')
	})

	it('escapes a list id that would otherwise break out of the attribute', () => {
		const snippet = embedSnippet('https://crm.example.org', 'a b"onload=x')
		expect(snippet).not.toContain('"onload=x')
	})

	it('returns nothing when either input is missing', () => {
		expect(embedSnippet('', 'list-abc')).toBe('')
		expect(embedSnippet('https://crm.example.org', '')).toBe('')
	})
})
