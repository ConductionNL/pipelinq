// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/journeyLabels.js — the words a journey run
 * and a weekly review are read in (marketing-integrated-campaigns).
 *
 * These matter more than they look. `suppressed_dunning` rendered raw
 * answers nothing, and a wrong mapping is invisible in a screenshot: the
 * page still renders, it just says the wrong thing about who was skipped
 * and why.
 */

import { describe, expect, it } from 'vitest'
import {
	degradedSources,
	flowStatusMessage,
	isAgentAuthored,
} from '../../src/services/journeyLabels.js'

/**
 * An identity translator, so the assertions read the English source.
 *
 * @param {string} app The app id, ignored.
 * @param {string} text The source string.
 * @param {object} params Placeholder values.
 * @return {string} The interpolated source string.
 */
function t(app, text, params = {}) {
	return text.replace(/\{(\w+)\}/g, (match, key) =>
		params[key] === undefined ? match : String(params[key]),
	)
}

describe('flowStatusMessage', () => {
	it('says nothing when the journey compiled', () => {
		expect(flowStatusMessage({ flowStatus: 'compiled' }, t)).toBe('')
	})

	it('says a journey will not run when there is no flow engine', () => {
		expect(flowStatusMessage({ flowStatus: 'engine_missing' }, t)).toContain(
			'it will not run',
		)
	})

	it("keeps the engine's own words on a refusal", () => {
		expect(
			flowStatusMessage(
				{ flowStatus: 'refused', flowError: 'unknown node type' },
				t,
			),
		).toBe('The flow engine refused this journey: unknown node type')
	})
})

describe('degradedSources', () => {
	it('names an absent source', () => {
		expect(degradedSources({ degraded: ['watchEvent'] })).toEqual(['watchEvent'])
	})

	it('answers an empty list, never a zero, when nothing is absent', () => {
		expect(degradedSources({ degraded: [] })).toEqual([])
		expect(degradedSources({})).toEqual([])
		expect(degradedSources(null)).toEqual([])
	})
})

describe('isAgentAuthored', () => {
	it('needs both the flag and the author before it claims one', () => {
		expect(
			isAgentAuthored({ agentAuthored: true, agentAuthoredBy: 'hermiq:x' }),
		).toBe(true)
		expect(isAgentAuthored({ agentAuthored: true, agentAuthoredBy: '' })).toBe(
			false,
		)
		expect(isAgentAuthored({ agentAuthored: false })).toBe(false)
		expect(isAgentAuthored(null)).toBe(false)
	})
})
