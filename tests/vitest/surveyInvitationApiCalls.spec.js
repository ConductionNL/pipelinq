// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The public survey client: the token URL, how an answer is posted, and how a
 * refused token comes back as a state instead of a throw.
 *
 * @spec openspec/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => '/index.php' + path.replace(/{(\w+)}/g, (_m, key) => vars[key]),
}))

const { invitationUrl, fetchInvitation, submitInvitation } = await import('../../src/services/surveyInvitationApi.js')

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('surveyInvitationApi', () => {
	it('puts the token in the survey path', () => {
		expect(invitationUrl('abc')).toBe('/index.php/apps/pipelinq/survey/i/abc')
	})

	it('returns the open survey, defaulting the state and the survey', async () => {
		axios.get.mockResolvedValueOnce({ data: { survey: { title: 'Q' } } })
		expect(await fetchInvitation('t')).toEqual({ state: 'open', survey: { title: 'Q' } })
		axios.get.mockResolvedValueOnce({ data: { state: 'open' } })
		expect(await fetchInvitation('t')).toEqual({ state: 'open', survey: {} })
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/survey/i/t')
	})

	it('treats a body that is not an object as an unknown token', async () => {
		axios.get.mockResolvedValueOnce({ data: '<html>' })
		expect(await fetchInvitation('t')).toEqual({ state: 'unknown' })
	})

	it('turns a refusal with a state into that state', async () => {
		axios.get.mockRejectedValueOnce({ response: { status: 410, data: { state: 'expired', other: 1 } } })
		expect(await fetchInvitation('t')).toEqual({ state: 'expired' })
	})

	it('rethrows a failure that carries no state', async () => {
		const failure = new Error('network')
		axios.get.mockRejectedValueOnce(failure)
		await expect(fetchInvitation('t')).rejects.toBe(failure)
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'x' } } })
		await expect(submitInvitation('t', {})).rejects.toEqual({ response: { data: { error: 'x' } } })
	})

	it('posts the answers, with optOut only when it is exactly true', async () => {
		axios.post.mockResolvedValue({})
		expect(await submitInvitation('t', { q1: 5 }, true)).toEqual({ state: 'answered' })
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/survey/i/t', { answers: { q1: 5 }, optOut: true })
		await submitInvitation('t', { q1: 4 }, 'yes')
		expect(axios.post).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/survey/i/t', { answers: { q1: 4 } })
	})

	it('reports an already answered survey as its state', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { state: 'responded' } } })
		expect(await submitInvitation('t', {})).toEqual({ state: 'responded' })
	})
})
