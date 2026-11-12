// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The campaign endpoints: report, landing page, list, vocabularies and save,
 * including how a refusal with an error body is returned rather than thrown.
 *
 * @spec openspec/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = { get: vi.fn(), post: vi.fn(), patch: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const api = await import('../../src/services/campaignsApi.js')

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	axios.patch.mockReset()
})

describe('campaignsApi', () => {
	it('asks the report for the window', async () => {
		axios.get.mockResolvedValueOnce({ data: { leads: 3 } })
		expect(await api.fetchCampaignReport('c1', { from: 'a', to: 'b' })).toEqual({ leads: 3 })
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/campaigns/c1/report', { params: { from: 'a', to: 'b' } })
	})

	it('posts the landing page options, defaulting them to empty', async () => {
		axios.post.mockResolvedValueOnce({ data: { url: '/p' } })
		expect(await api.createCampaignLandingPage('c1')).toEqual({ url: '/p' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/campaigns/c1/landing-page', { portal: '', route: '' })
	})

	it('returns a refusal with an error body, and rethrows any other failure', async () => {
		axios.post.mockRejectedValueOnce({ response: { data: { error: 'No portal' } } })
		expect(await api.createCampaignLandingPage('c1', { portal: 'p' })).toEqual({ error: 'No portal' })
		const failure = new Error('network')
		axios.post.mockRejectedValueOnce(failure)
		await expect(api.createCampaignLandingPage('c1')).rejects.toBe(failure)
	})

	it('lists the campaigns from results, then data, else nothing', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [{ id: 1 }] } })
		expect(await api.fetchCampaigns(5)).toEqual([{ id: 1 }])
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/pipelinq/campaign', { params: { _limit: 5 } })
		axios.get.mockResolvedValueOnce({ data: { data: [{ id: 2 }] } })
		expect(await api.fetchCampaigns()).toEqual([{ id: 2 }])
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/objects/pipelinq/campaign', { params: { _limit: 100 } })
		axios.get.mockResolvedValueOnce({ data: null })
		expect(await api.fetchCampaigns()).toEqual([])
	})

	it('reads both vocabularies, empty when absent', async () => {
		axios.get.mockResolvedValueOnce({ data: { sources: ['google'], mediums: ['cpc'] } })
		expect(await api.fetchCampaignVocabularies()).toEqual({ sources: ['google'], mediums: ['cpc'] })
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchCampaignVocabularies()).toEqual({ sources: [], mediums: [] })
	})

	it('creates with POST and updates with PATCH', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'new' } })
		expect(await api.saveCampaign({ name: 'A' })).toEqual({ campaign: { id: 'new' } })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/campaigns', { name: 'A' })
		axios.patch.mockResolvedValueOnce({ data: { id: 'c1' } })
		expect(await api.saveCampaign({ name: 'B' }, 'c1')).toEqual({ campaign: { id: 'c1' } })
		expect(axios.patch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/campaigns/c1', { name: 'B' })
	})

	it('returns a save refusal with an error body', async () => {
		axios.patch.mockRejectedValueOnce({ response: { data: { error: 'Invalid' } } })
		expect(await api.saveCampaign({}, 'c1')).toEqual({ error: 'Invalid' })
	})
})
