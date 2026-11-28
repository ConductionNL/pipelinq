// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call scorecard editor on the admin page (contact-moments-call-review task
 * 2.2): the criteria rules, and the section loading, editing and saving
 * scorecards through OpenRegister's object API, run against a plain component
 * context with axios doubled.
 *
 * @spec openspec/changes/contact-moments-call-review/specs/call-review/spec.md#requirement-a-reviewer-scores-a-call-and-writes-coaching-points-req-crv-002
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => String(text)
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), put: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => params[k] ?? m) }))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('@nextcloud/vue', () => ({ NcButton: stub('NcButton'), NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'), NcTextField: stub('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: stub('CnSettingsSection') }))

const { criterionKey, normaliseCriteria, scorecardProblems } = await import('../../src/utils/scorecardCriteria.js')
const { default: ScorecardManager } = await import('../../src/views/settings/ScorecardManager.vue')

const URL = '/index.php/apps/openregister/api/objects/pipelinq/callScorecard'

/**
 * A component context: data and bound methods.
 *
 * @return {object} The context.
 */
function context() {
	const ctx = {}
	for (const [name, fn] of Object.entries(ScorecardManager.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, ScorecardManager.data.call(ctx))
	return ctx
}

beforeEach(() => {
	toasts.length = 0
	for (const fn of Object.values(http)) {
		fn.mockReset()
	}
})

describe('scorecard criteria rules', () => {
	it('makes a unique key from a label', () => {
		expect(criterionKey('Begroeting & toon')).toBe('begroeting-toon')
		expect(criterionKey('Één antwoord')).toBe('een-antwoord')
		expect(criterionKey('Answer', ['answer', 'answer-2'])).toBe('answer-3')
		expect(criterionKey('  !! ')).toBe('criterion')
	})

	it('keeps an existing key, fills defaults and clamps weight and scale', () => {
		expect(normaliseCriteria([
			{ key: 'greeting', label: 'Welcome', weight: '2', scale: '4' },
			{ label: 'Answer', weight: '', scale: '' },
			{ label: 'Answer', weight: '-3', scale: '9' },
			{ label: '   ', weight: '1' },
		])).toEqual([
			{ key: 'greeting', label: 'Welcome', weight: 2, scale: 4 },
			{ key: 'answer', label: 'Answer', weight: 1, scale: 5 },
			{ key: 'answer-2', label: 'Answer', weight: 0, scale: 5 },
		])
		expect(normaliseCriteria(null)).toEqual([])
	})

	it('names what stops a save', () => {
		expect(scorecardProblems({ title: ' ', criteria: [] })).toEqual(['Give the scorecard a title.', 'Add at least one criterion.'])
		expect(scorecardProblems({ title: 'Calls', criteria: [{ key: 'a', weight: 0 }, { key: 'a', weight: 0 }] }))
			.toEqual(['Two criteria have the same key.', 'At least one criterion needs a weight above 0.'])
		expect(scorecardProblems({ title: 'Calls', criteria: [{ key: 'a', weight: 1 }] })).toEqual([])
	})
})

describe('ScorecardManager', () => {
	it('loads the scorecards with their ids', async () => {
		http.get.mockResolvedValue({ data: { results: [{ '@self': { id: 's1' }, title: 'Calls', criteria: [] }] } })
		const ctx = context()

		await ctx.load()

		expect(http.get).toHaveBeenCalledWith(URL, { params: { _limit: 50 } })
		expect(ctx.scorecards).toEqual([{ '@self': { id: 's1' }, id: 's1', title: 'Calls', criteria: [] }])
		expect(ctx.loadFailed).toBe(false)
	})

	it('says when the scorecards cannot be loaded', async () => {
		http.get.mockRejectedValue(new Error('403'))
		const ctx = context()

		await ctx.load()

		expect(ctx.scorecards).toEqual([])
		expect(ctx.loadFailed).toBe(true)
		expect(ctx.loading).toBe(false)
	})

	it('adds and removes criteria and posts a new scorecard', async () => {
		http.post.mockResolvedValue({ data: {} })
		http.get.mockResolvedValue({ data: { results: [] } })
		const ctx = context()
		ctx.startNew()
		ctx.draft.title = ' Inbound calls '
		Object.assign(ctx.draft.criteria[0], { label: 'Greeting', weight: '1', scale: '5' })
		ctx.addCriterion()
		Object.assign(ctx.draft.criteria[1], { label: 'Answer', weight: '2', scale: '5' })
		ctx.addCriterion()
		ctx.removeCriterion(2)

		await ctx.save()

		expect(http.post).toHaveBeenCalledWith(URL, {
			title: 'Inbound calls',
			isActive: true,
			criteria: [
				{ key: 'greeting', label: 'Greeting', weight: 1, scale: 5 },
				{ key: 'answer', label: 'Answer', weight: 2, scale: 5 },
			],
		})
		expect(toasts).toEqual([['success', 'Scorecard saved']])
		expect(ctx.draft).toBeNull()
		expect(http.get).toHaveBeenCalledTimes(1)
	})

	it('puts an edited scorecard and keeps the keys its reviews point at', async () => {
		http.put.mockResolvedValue({ data: {} })
		http.get.mockResolvedValue({ data: { results: [] } })
		const ctx = context()
		ctx.edit({ id: 's1', title: 'Calls', isActive: false, criteria: [{ key: 'greeting', label: 'Greeting', weight: 1, scale: 5 }] })
		ctx.draft.criteria[0].label = 'Welcome'

		await ctx.save()

		expect(http.put).toHaveBeenCalledWith(`${URL}/s1`, {
			title: 'Calls',
			isActive: false,
			criteria: [{ key: 'greeting', label: 'Welcome', weight: 1, scale: 5 }],
		})
		expect(http.post).not.toHaveBeenCalled()
	})

	it('does not save a scorecard with problems, and shows the server refusal', async () => {
		const ctx = context()
		ctx.startNew()

		await ctx.save()

		expect(ctx.problems).toEqual(['Give the scorecard a title.', 'Add at least one criterion.'])
		expect(http.post).not.toHaveBeenCalled()

		http.post.mockRejectedValue({ response: { data: { message: 'Only administrators can change a scorecard.' } } })
		ctx.draft.title = 'Calls'
		ctx.draft.criteria[0].label = 'Greeting'

		await ctx.save()

		expect(toasts).toEqual([['error', 'Only administrators can change a scorecard.']])
		expect(ctx.draft).not.toBeNull()
		expect(ctx.saving).toBe(false)
	})
})
