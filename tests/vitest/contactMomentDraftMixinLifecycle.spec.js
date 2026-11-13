// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft mixin's lifecycle and its plain (non-keepalive)
 * writes: the autosaver it wires up on create, the flush when the tab is
 * hidden or the form closes, the store writes that create, overwrite and
 * remove the draft, and the fallbacks of a failed load or finish.
 *
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 */
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

const store = {
	saveObject: vi.fn(),
	getError: vi.fn(),
	deleteObject: vi.fn(),
	fetchCollection: vi.fn(),
}
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

let mixin
let draftType

beforeAll(async () => {
	window.OC = { requestToken: 'tok' }
	mixin = (await import('../../src/mixins/contactMomentDraftMixin.js')).default
	draftType = (await import('../../src/services/contactMomentDraft.js')).DRAFT_TYPE
})

beforeEach(() => {
	for (const fn of Object.values(store)) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

/**
 * A host with the mixin's data and its methods bound to it.
 *
 * @param {object} extra Fields to set on the host.
 * @return {object} The host.
 */
function host(extra = {}) {
	const ctx = { ...mixin.data(), ...extra }
	for (const [name, fn] of Object.entries(mixin.methods)) {
		if (!(name in extra)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('the autosaver wired up on create', () => {
	it('marks the session ended on a 401 or 412 write failure, and only then', () => {
		const ctx = host()
		mixin.created.call(ctx)
		ctx.autosaver.onError({ status: 500 })
		expect(ctx.sessionEnded).toBe(false)
		ctx.autosaver.onError({ status: 412 })
		expect(ctx.sessionEnded).toBe(true)
		mixin.beforeUnmount.call(ctx)
	})

	it('hands writes and removals to the host', async () => {
		const writeDraft = vi.fn(async () => {})
		const removeDraft = vi.fn(async () => {})
		const ctx = host({ writeDraft, removeDraft })
		mixin.created.call(ctx)
		await ctx.autosaver.write({ a: 1 }, { keepalive: false })
		await ctx.autosaver.remove({ keepalive: true })
		expect(writeDraft).toHaveBeenCalledWith({ a: 1 }, { keepalive: false })
		expect(removeDraft).toHaveBeenCalledWith({ keepalive: true })
		mixin.beforeUnmount.call(ctx)
	})

	it('flushes with keepalive when the tab is hidden, not when it is shown', () => {
		const ctx = host()
		mixin.created.call(ctx)
		const flush = vi.spyOn(ctx.autosaver, 'flush').mockImplementation(() => {})
		const state = vi.spyOn(document, 'visibilityState', 'get')

		state.mockReturnValue('visible')
		document.dispatchEvent(new Event('visibilitychange'))
		expect(flush).not.toHaveBeenCalled()

		state.mockReturnValue('hidden')
		document.dispatchEvent(new Event('visibilitychange'))
		expect(flush).toHaveBeenCalledWith({ keepalive: true })

		mixin.beforeUnmount.call(ctx)
	})

	it('flushes on unmount and stops listening for the tab being hidden', () => {
		const ctx = host()
		mixin.created.call(ctx)
		const flush = vi.spyOn(ctx.autosaver, 'flush').mockImplementation(() => {})
		mixin.beforeUnmount.call(ctx)
		expect(flush).toHaveBeenCalledTimes(1)
		expect(flush).toHaveBeenCalledWith()

		vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden')
		document.dispatchEvent(new Event('visibilitychange'))
		expect(flush).toHaveBeenCalledTimes(1)
	})
})

describe('the plain draft write', () => {
	it('creates the draft through the store and remembers its id', async () => {
		store.saveObject.mockResolvedValue({ id: 'd-1' })
		const ctx = host()
		await ctx.writeDraft({ a: 1 }, { keepalive: false })
		expect(store.saveObject).toHaveBeenCalledWith(draftType, { a: 1 })
		expect(ctx.draftId).toBe('d-1')
	})

	it('overwrites the existing draft by id, and keeps the id when the answer has none', async () => {
		store.saveObject.mockResolvedValue({})
		const ctx = host({ draftId: 'd-7' })
		await ctx.writeDraft({ a: 2 }, { keepalive: false })
		expect(store.saveObject).toHaveBeenCalledWith(draftType, { a: 2, id: 'd-7' })
		expect(ctx.draftId).toBe('d-7')
	})

	it('throws the store error with its status when the write is refused', async () => {
		store.saveObject.mockResolvedValue(null)
		store.getError.mockReturnValue({ status: 412, message: 'token' })
		const ctx = host()
		await expect(ctx.writeDraft({ a: 1 }, { keepalive: false })).rejects.toMatchObject({ status: 412, message: 'token' })
		expect(store.getError).toHaveBeenCalledWith(draftType)
		expect(ctx.draftId).toBe(null)
	})
})

describe('removing the draft', () => {
	it('does nothing when there is no draft', async () => {
		const ctx = host()
		await ctx.removeDraft({ keepalive: false })
		expect(store.deleteObject).not.toHaveBeenCalled()
	})

	it('deletes it through the store and forgets the id', async () => {
		const ctx = host({ draftId: 'd-3' })
		await ctx.removeDraft({ keepalive: false })
		expect(store.deleteObject).toHaveBeenCalledWith(draftType, 'd-3')
		expect(ctx.draftId).toBe(null)
	})

	it('drops a known draft after stopping the autosaver', async () => {
		const stop = vi.fn()
		const ctx = host({ draftId: 'd-4', autosaver: { stop } })
		await ctx.dropDraft()
		expect(stop).toHaveBeenCalled()
		expect(store.deleteObject).toHaveBeenCalledWith(draftType, 'd-4')
		expect(ctx.draftId).toBe(null)
	})

	it('only stops the autosaver when there is no draft to drop', async () => {
		const stop = vi.fn()
		const ctx = host({ autosaver: { stop } })
		await ctx.dropDraft()
		expect(stop).toHaveBeenCalled()
		expect(store.deleteObject).not.toHaveBeenCalled()
	})
})

describe('loading and finishing the draft', () => {
	it('starts watching the form when the drafts cannot be fetched', async () => {
		window.OC.getCurrentUser = () => ({ uid: 'agent' })
		store.fetchCollection.mockRejectedValue(new Error('offline'))
		const ctx = host({ author: 'agent', draftPickContext: { author: 'agent', clientId: 'c-1', requestId: null } })
		await ctx.loadDraft()
		expect(store.fetchCollection).toHaveBeenCalledWith(draftType, expect.objectContaining({ _drafts: 'true', '@self[owner]': 'agent', _limit: 50 }))
		expect(ctx.offeredDraft).toBe(null)
		expect(ctx.draftId).toBe(null)
		expect(ctx.draftWatching).toBe(true)
		delete window.OC.getCurrentUser
	})

	it('does not look for drafts without an author', async () => {
		const ctx = host({ author: null })
		await ctx.loadDraft()
		expect(store.fetchCollection).not.toHaveBeenCalled()
		expect(ctx.draftWatching).toBe(false)
	})

	it('throws the status of a refused finish, also when its body cannot be read', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 422, json: async () => { throw new Error('not json') } })))
		const ctx = host({ draftId: 'd-5' })
		await expect(ctx.finishDraft('d-5', { a: 1 })).rejects.toMatchObject({ status: 422, message: '' })
		expect(fetch).toHaveBeenCalledWith(
			`/index.php/apps/openregister/api/objects/pipelinq/${draftType}/d-5/activate`,
			expect.objectContaining({ method: 'POST', body: '{"object":{"a":1}}' }),
		)
		expect(ctx.draftId).toBe('d-5')
	})

	it('joins the message and findings of a refused finish', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 400, json: async () => ({ message: 'Invalid.', findings: [{ message: 'Subject is required.' }] }) })))
		const ctx = host({ draftId: 'd-6' })
		await expect(ctx.finishDraft('d-6', {})).rejects.toMatchObject({ status: 400, message: 'Invalid. Subject is required.' })
	})
})

describe('the request token header', () => {
	it('sends an empty token when the page has none', async () => {
		const token = window.OC.requestToken
		delete window.OC.requestToken
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ id: 'x' }) })))
		const ctx = host({ draftId: 'd-8' })
		await ctx.finishDraft('d-8', {})
		expect(fetch.mock.calls[0][1].headers.requesttoken).toBe('')
		window.OC.requestToken = token
	})
})
