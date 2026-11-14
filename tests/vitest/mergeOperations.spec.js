/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The merge operations a record survived, read from OpenRegister's
 * merge-operation register, the reversal call, and the open / final /
 * reversed state a merge is in.
 *
 * @spec openspec/changes/clients-reversible-merge
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, vars = {}) => path.replace(/\{(\w+)\}/g, (_m, key) => vars[key]) }))

const { fetchMergesInto, mergedAwayName, mergeFinalAt, mergeState, reverseMergeOperation } = await import('../../src/services/mergeOperations.js')

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('fetchMergesInto', () => {
	it('asks the merge-operation register for this survivor and lists the newest first', async () => {
		http.get.mockResolvedValue({
			data: {
				results: [
					{ id: 'old', mergedIntoUuid: 'c-1', mergedAt: '2026-09-01T00:00:00Z' },
					{ id: 'other', mergedIntoUuid: 'c-2', mergedAt: '2026-11-01T00:00:00Z' },
					{ id: 'new', mergedIntoUuid: 'c-1', mergedAt: '2026-11-07T00:00:00Z' },
				],
			},
		})

		const rows = await fetchMergesInto('c-1')

		expect(http.get).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/merge-operation/mergeOperation',
			{ params: { mergedIntoUuid: 'c-1', _limit: 100 } },
		)
		expect(rows.map((row) => row.id)).toEqual(['new', 'old'])
	})

	it('asks nothing without a record id', async () => {
		expect(await fetchMergesInto('')).toEqual([])
		expect(http.get).not.toHaveBeenCalled()
	})

	it('reads an answer without a results list as no merges', async () => {
		http.get.mockResolvedValue({ data: { results: 'nope' } })
		expect(await fetchMergesInto('c-1')).toEqual([])
	})
})

describe('reverseMergeOperation', () => {
	it('posts to the reversal of that operation and returns the updated row', async () => {
		http.post.mockResolvedValue({ data: { id: 'op-1', reversible: false } })

		expect(await reverseMergeOperation('op-1')).toEqual({ id: 'op-1', reversible: false })
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/objects/merge/op-1/reverse')
	})
})

describe('the state of a merge', () => {
	const now = new Date('2026-11-14T12:00:00Z')

	it('is open inside the window and final after it', () => {
		expect(mergeState({ mergedAt: '2026-11-07T09:00:00Z', reversible: true }, 30, now)).toBe('open')
		expect(mergeState({ mergedAt: '2026-09-15T09:00:00Z', reversible: true }, 30, now)).toBe('final')
	})

	it('is reversed once undone, and final when the time cannot be read', () => {
		expect(mergeState({ mergedAt: '2026-11-07T09:00:00Z', reversedAt: '2026-11-08T00:00:00Z' }, 30, now)).toBe('reversed')
		expect(mergeState({ mergedAt: 'yesterday', reversible: true }, 30, now)).toBe('final')
	})

	it('becomes final the window\'s number of days after the merge', () => {
		expect(mergeFinalAt('2026-11-07T09:00:00Z', 30).toISOString()).toBe('2026-12-07T09:00:00.000Z')
		expect(mergeFinalAt('', 30)).toBeNull()
	})

	it('names the merged-away record from the snapshot, or nothing', () => {
		expect(mergedAwayName({ mergedFromUuids: ['c-2'], preMergeSnapshot: { objects: { 'c-2': { name: 'Bakker BV' } } } })).toBe('Bakker BV')
		expect(mergedAwayName({ mergedFromUuids: ['c-2'] })).toBe('')
	})
})
