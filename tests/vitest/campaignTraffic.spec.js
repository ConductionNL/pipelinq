// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/campaignTraffic.js — the decision the site-traffic
 * block on the blast performance dashboard makes about what to say.
 *
 * The load-bearing case is the first one. That block used to hold this decision
 * in a nullable boolean, and an instance with no blasts left it null forever, so
 * the template showed "Loading site traffic" and never stopped. On the
 * development run of 2026-09-06 the page fetched the blast list, got 200 with an
 * empty list, made zero performance calls, and the e2e assertion timed out
 * against a spinner sixty seconds later.
 *
 * So "asked nothing" and "asked and heard nothing" are separated here on
 * purpose: they are different sentences to a user, and collapsing them is what
 * produced a state with no exit.
 */
import { describe, expect, it } from 'vitest'
import { trafficOutcome } from '../../src/services/campaignTraffic.js'

describe('trafficOutcome', () => {
	it('says there are no blasts when nothing was asked', () => {
		expect(trafficOutcome({ asked: 0, answered: 0 })).toBe('no-blasts')
	})

	it('does not call an empty list unreadable', () => {
		// The regression guard. `answered === 0` is true here too, and reading
		// that first would blame the server for requests that were never sent.
		expect(trafficOutcome({ asked: 0, answered: 0 })).not.toBe('unreadable')
	})

	it('says unreadable when every request failed', () => {
		expect(trafficOutcome({ asked: 3, answered: 0 })).toBe('unreadable')
	})

	it('is answered as soon as one request came back', () => {
		expect(trafficOutcome({ asked: 3, answered: 1 })).toBe('answered')
	})

	it('is answered when every request came back', () => {
		expect(trafficOutcome({ asked: 2, answered: 2 })).toBe('answered')
	})

	it('never returns loading, because it only runs once the fan-out is over', () => {
		const outcomes = [
			trafficOutcome({ asked: 0, answered: 0 }),
			trafficOutcome({ asked: 1, answered: 0 }),
			trafficOutcome({ asked: 1, answered: 1 }),
		]

		expect(outcomes).not.toContain('loading')
	})
})
