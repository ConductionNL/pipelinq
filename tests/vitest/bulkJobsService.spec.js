// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The bulk-jobs service: the pure helpers a bulk change is read with (writable
 * properties, change counts, the reversal window, a refusal reason) and the
 * thin OpenRegister calls (resolve scope, create a previewed job, commit,
 * reverse, list). HTTP is mocked; the calls are checked by their request.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = { get: vi.fn(), post: vi.fn() }

vi.mock('@nextcloud/axios', () => ({
	default: { get: (...args) => http.get(...args), post: (...args) => http.post(...args) },
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const svc = await import('../../src/services/bulkJobs.js')

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
})

describe('writablePropertiesOf', () => {
	const schema = {
		properties: {
			id: { type: 'string' },
			uuid: { type: 'string' },
			contactsUid: { type: 'string', title: 'Contacts link' },
			name: { type: 'string', title: 'Name' },
			industry: { type: 'string', title: 'Industry' },
			score: { type: 'number', title: 'Score', readOnly: true },
			slug: { type: 'string', calculation: 'name|lower' },
			secret: { type: 'string', title: 'Secret', writeOnly: true },
			active: { type: 'boolean', title: 'Active' },
		},
	}

	it('lists only the writable, settable properties, by label', () => {
		expect(svc.writablePropertiesOf(schema).map((p) => p.id)).toEqual(['active', 'industry', 'name'])
	})

	it('drops identifiers, read-only, computed and write-only properties', () => {
		const ids = svc.writablePropertiesOf(schema).map((p) => p.id)
		for (const gone of ['id', 'uuid', 'contactsUid', 'score', 'slug', 'secret']) {
			expect(ids).not.toContain(gone)
		}
	})

	it('falls back to the property name when it has no title, and carries the type', () => {
		const result = svc.writablePropertiesOf({ properties: { role: { type: 'string' } } })
		expect(result).toEqual([{ id: 'role', label: 'role', type: 'string' }])
	})

	it('returns nothing for a schema with no properties', () => {
		expect(svc.writablePropertiesOf(null)).toEqual([])
		expect(svc.writablePropertiesOf({})).toEqual([])
	})
})

describe('changeSummary', () => {
	it('reads the counts under processed.counts', () => {
		const job = { processed: { counts: { applied: 50, skipped: 2, refused: 0, failed: 1 } } }
		expect(svc.changeSummary(job)).toEqual({ changed: 50, skipped: 2, refused: 0, failed: 1 })
	})

	it('reads a top-level counts object too', () => {
		expect(svc.changeSummary({ counts: { applied: 3 } })).toEqual({ changed: 3, skipped: 0, refused: 0, failed: 0 })
	})

	it('is all zeroes for a job with no counts', () => {
		expect(svc.changeSummary(null)).toEqual({ changed: 0, skipped: 0, refused: 0, failed: 0 })
	})
})

describe('reversibleUntil and isReversible', () => {
	const now = new Date('2026-09-20T12:00:00Z')

	it('reads the deadline as a Date, or null when absent or unparseable', () => {
		expect(svc.reversibleUntil({ reversibleUntil: '2026-09-25T00:00:00Z' }).toISOString()).toBe('2026-09-25T00:00:00.000Z')
		expect(svc.reversibleUntil({})).toBeNull()
		expect(svc.reversibleUntil({ reversibleUntil: 'nonsense' })).toBeNull()
	})

	it('is reversible while the window is open', () => {
		expect(svc.isReversible({ reversibleUntil: '2026-09-25T00:00:00Z' }, now)).toBe(true)
	})

	it('is not reversible past the window', () => {
		expect(svc.isReversible({ reversibleUntil: '2026-09-19T00:00:00Z' }, now)).toBe(false)
	})

	it('is not reversible without a window or when the job says so', () => {
		expect(svc.isReversible({}, now)).toBe(false)
		expect(svc.isReversible({ reversibleUntil: '2026-09-25T00:00:00Z', reversible: false }, now)).toBe(false)
	})
})

describe('refusalReason', () => {
	it('prefers reason, then error, then message, then the fallback', () => {
		expect(svc.refusalReason({ response: { data: { reason: 'window-expired' } } }, 'fb')).toBe('window-expired')
		expect(svc.refusalReason({ response: { data: { error: 'too big' } } }, 'fb')).toBe('too big')
		expect(svc.refusalReason({ response: { data: { message: 'nope' } } }, 'fb')).toBe('nope')
		expect(svc.refusalReason(new Error('x'), 'fallback here')).toBe('fallback here')
		expect(svc.refusalReason(null, 'fallback here')).toBe('fallback here')
	})
})

describe('resolveScope', () => {
	it('reads the numeric register and schema from an object @self', async () => {
		http.get.mockResolvedValue({ data: { '@self': { register: 7, schema: 12 } } })
		const scope = await svc.resolveScope('pipelinq', 'client', 'uuid-1')
		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/client/uuid-1')
		expect(scope).toEqual({ register: 7, schema: 12 })
	})
})

describe('createSetPropertiesJob', () => {
	it('posts a set-properties job for the selection and never commits', async () => {
		http.post.mockResolvedValue({ data: { id: 5, processed: { counts: { applied: 3 } } } })
		const job = await svc.createSetPropertiesJob({ register: 7, schema: 12, ids: ['a', 'b'], properties: { industry: 'Healthcare' } })

		expect(http.post).toHaveBeenCalledTimes(1)
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs', {
			action: 'openregister:set-properties',
			parameters: { properties: { industry: 'Healthcare' } },
			selection: { ids: ['a', 'b'] },
			register: 7,
			schema: 12,
		})
		expect(http.post.mock.calls.every(([url]) => !url.endsWith('/commit'))).toBe(true)
		expect(job.id).toBe(5)
	})
})

describe('commitJob, reverseJob, listJobs', () => {
	it('commits a job by id', async () => {
		http.post.mockResolvedValue({ data: { id: 5, state: 'applied' } })
		await svc.commitJob(5)
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs/5/commit')
	})

	it('asks for a reversal by id', async () => {
		http.post.mockResolvedValue({ data: { id: 6 } })
		await svc.reverseJob(5)
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs/5/reverse')
	})

	it('lists jobs from any of the envelope shapes', async () => {
		http.get.mockResolvedValueOnce({ data: [{ id: 1 }] })
		expect(await svc.listJobs()).toEqual([{ id: 1 }])
		http.get.mockResolvedValueOnce({ data: { results: [{ id: 2 }] } })
		expect(await svc.listJobs()).toEqual([{ id: 2 }])
		http.get.mockResolvedValueOnce({ data: { jobs: [{ id: 3 }] } })
		expect(await svc.listJobs()).toEqual([{ id: 3 }])
		http.get.mockResolvedValueOnce({ data: { nope: true } })
		expect(await svc.listJobs()).toEqual([])
	})
})
