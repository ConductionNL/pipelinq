// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The party indicator calls: the leaf read (sorted loudest first), the
 * acknowledgement, the vocabulary filter and the new value payload.
 *
 * @spec openspec/specs/party-fields-and-indicators/spec.md
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = { get: vi.fn(), post: vi.fn() }
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => '/index.php' + path.replace(/{(\w+)}/g, (_m, key) => vars[key]),
}))

const api = await import('../../src/services/partyIndicators.js')

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

describe('party indicator calls', () => {
	it('reads the leaf of the party and sorts its indicators', async () => {
		axios.get.mockResolvedValueOnce({ data: { indicators: [
			{ label: 'B', severity: 'info' },
			{ label: 'A', severity: 'critical' },
			{ label: 'C' },
		] } })
		expect((await api.fetchPartyIndicators('p1')).map((i) => i.label)).toEqual(['A', 'C', 'B'])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/leaves/party/p1')
	})

	it('reads no indicators from an empty leaf', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchPartyIndicators('p1')).toEqual([])
	})

	it('acknowledges a value and returns the updated one, or an empty object', async () => {
		axios.post.mockResolvedValueOnce({ data: { indicatorValue: { id: 'v1', acknowledgedAt: 'now' } } })
		expect(await api.acknowledgeIndicator('v1')).toEqual({ id: 'v1', acknowledgedAt: 'now' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/party-indicator-values/v1/acknowledge')
		axios.post.mockResolvedValueOnce({ data: {} })
		expect(await api.acknowledgeIndicator('v1')).toEqual({})
	})

	it('keeps only active vocabulary entries that have a code', async () => {
		axios.get.mockResolvedValueOnce({ data: { results: [
			{ code: 'a' },
			{ code: 'b', active: false },
			{ label: 'no code' },
			{ code: 'c', active: true },
		] } })
		expect(await api.fetchIndicatorVocabulary()).toEqual([{ code: 'a' }, { code: 'c', active: true }])
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/partyIndicator?_limit=200')
	})

	it('reads an empty vocabulary as no entries', async () => {
		axios.get.mockResolvedValueOnce({ data: {} })
		expect(await api.fetchIndicatorVocabulary()).toEqual([])
	})

	it('posts the built value and returns the saved object', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 'v9' } })
		const form = { indicator: 'aggr', validFrom: '2026-01-01', validUntil: '2026-12-31', source: 'desk' }
		expect(await api.addIndicatorValue('p1', form)).toEqual({ id: 'v9' })
		expect(axios.post).toHaveBeenCalledWith('/index.php/apps/openregister/api/objects/pipelinq/partyIndicatorValue', {
			party: 'p1',
			indicator: 'aggr',
			validFrom: '2026-01-01',
			validUntil: '2026-12-31',
			source: 'desk',
		})
		axios.post.mockResolvedValueOnce({})
		expect(await api.addIndicatorValue('p1', form)).toEqual({})
	})
})
