// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moment draft write a closing tab still delivers: a keepalive
 * request that creates, overwrites or removes the draft, and remembers the
 * id of a draft it created.
 *
 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let mixin
let draftType

beforeAll(async () => {
	window.OC = { requestToken: 'tok' }
	mixin = (await import('../../src/mixins/contactMomentDraftMixin.js')).default
	draftType = (await import('../../src/services/contactMomentDraft.js')).DRAFT_TYPE
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function answer(ok, body) {
	vi.stubGlobal('fetch', vi.fn(async () => ({ ok, status: ok ? 200 : 412, json: body instanceof Error ? async () => { throw body } : async () => body })))
}

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }

describe('the keepalive draft write', () => {
	it('creates a draft and remembers its id', async () => {
		answer(true, { id: 'd-1' })
		const ctx = { draftId: null }
		await mixin.methods.writeDraft.call({ ...ctx, sendDraftKeepalive: (...args) => mixin.methods.sendDraftKeepalive.call(ctx, ...args) }, { a: 1 }, { keepalive: true })
		expect(fetch).toHaveBeenCalledWith(`/index.php/apps/openregister/api/objects/pipelinq/${draftType}`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers, body: '{"a":1}' })
		expect(ctx.draftId).toBe('d-1')
	})

	it('overwrites an existing draft, and keeps the id when the answer cannot be read', async () => {
		answer(true, new Error('closed'))
		const ctx = { draftId: 'd-1' }
		await mixin.methods.writeDraft.call({ ...ctx, sendDraftKeepalive: (...args) => mixin.methods.sendDraftKeepalive.call(ctx, ...args) }, { a: 2 }, { keepalive: true })
		expect(fetch).toHaveBeenCalledWith(`/index.php/apps/openregister/api/objects/pipelinq/${draftType}/d-1`, { method: 'PUT', keepalive: true, credentials: 'same-origin', headers, body: '{"a":2}' })
		expect(ctx.draftId).toBe('d-1')
		answer(true, new Error('closed'))
		const created = { draftId: null }
		await mixin.methods.writeDraft.call({ ...created, sendDraftKeepalive: (...args) => mixin.methods.sendDraftKeepalive.call(created, ...args) }, { a: 3 }, { keepalive: true })
		expect(created.draftId).toBe(null)
	})

	it('removes the draft without a body, and throws the status of a refusal', async () => {
		answer(true, {})
		const ctx = { draftId: 'd-9' }
		await mixin.methods.removeDraft.call(Object.assign(ctx, { sendDraftKeepalive: (...args) => mixin.methods.sendDraftKeepalive.call(ctx, ...args) }), { keepalive: true })
		expect(fetch).toHaveBeenCalledWith(`/index.php/apps/openregister/api/objects/pipelinq/${draftType}/d-9`, { method: 'DELETE', keepalive: true, credentials: 'same-origin', headers, body: undefined })
		expect(ctx.draftId).toBe(null)
		answer(false, {})
		const refused = { draftId: 'd-2' }
		await expect(mixin.methods.writeDraft.call({ ...refused, sendDraftKeepalive: (...args) => mixin.methods.sendDraftKeepalive.call(refused, ...args) }, { a: 1 }, { keepalive: true })).rejects.toMatchObject({ status: 412 })
	})
})
