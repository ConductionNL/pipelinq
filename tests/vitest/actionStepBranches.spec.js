// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Branches of three actions pinned before they were split into named
 * steps: counting the items on a pipeline about to be deleted, the
 * click-to-dial button, and the segment preview.
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const cti = vi.hoisted(() => ({ clickToDial: vi.fn() }))
const http = vi.hoisted(() => ({ post: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnConditionGroupEditor: {}, CnSettingsSection: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcEmptyContent: {}, NcLoadingIcon: {}, NcNoteCard: {} }))
vi.mock('../../src/dialogs/DeletePipelineDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/services/ctiApi.js', () => cti)

let manager
let dialButton
let builder

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => String(vars[key] ?? match))
	globalThis.OC = { requestToken: 'tok' }
	manager = (await import('../../src/views/settings/PipelineManager.vue')).default
	dialButton = (await import('../../src/components/CtiClickToDialButton.vue')).default
	builder = (await import('../../src/components/SegmentBuilder.vue')).default
}, 60000)

beforeEach(() => {
	Object.values(dialogs).forEach((fn) => fn.mockReset())
	cti.clickToDial.mockReset()
	http.post.mockReset()
})

describe('PipelineManager.countAffectedItems', () => {
	const registry = {
		lead: { register: 'r', schema: 'lead' },
		ticket: { register: 'r', schema: 'ticket' },
	}

	/**
	 * @param {Array<object>} pipelines The pipelines.
	 * @return {object} A manager context.
	 */
	function ctx(pipelines) {
		return { ...manager.methods, pipelines, objectStore: { objectTypeRegistry: registry } }
	}

	it('counts each mapped schema in order, a ticket kind by its filter', async () => {
		const fetchMock = vi.fn(async (url) => ({ ok: true, json: async () => ({ total: url.includes('lead') ? 3 : 2 }) }))
		vi.stubGlobal('fetch', fetchMock)
		const c = ctx([{ id: 'p1', propertyMappings: [{ schemaSlug: 'lead' }, { schemaSlug: 'complaint' }, { schemaSlug: 'unknownThing' }] }])
		expect(await manager.methods.countAffectedItems.call(c, 'p1')).toBe(5)
		const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }
		expect(fetchMock.mock.calls).toEqual([
			['/apps/openregister/api/objects/r/lead?pipeline=p1&_limit=1', { headers }],
			['/apps/openregister/api/objects/r/ticket?ticketType=complaint&pipeline=p1&_limit=1', { headers }],
		])
		vi.unstubAllGlobals()
	})

	it('falls back to leads and requests, and skips refused and failed counts', async () => {
		const fetchMock = vi.fn()
			.mockResolvedValueOnce({ ok: false })
			.mockRejectedValueOnce(new Error('down'))
		vi.stubGlobal('fetch', fetchMock)
		const c = ctx([{ id: 'p2', propertyMappings: [] }])
		expect(await manager.methods.countAffectedItems.call(c, 'p2')).toBe(0)
		expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
			'/apps/openregister/api/objects/r/lead?pipeline=p2&_limit=1',
			'/apps/openregister/api/objects/r/ticket?ticketType=request&pipeline=p2&_limit=1',
		])
		fetchMock.mockReset()
		fetchMock.mockResolvedValue({ ok: true, json: async () => ({}) })
		expect(await manager.methods.countAffectedItems.call(ctx([]), 'gone')).toBe(0)
		expect(fetchMock).toHaveBeenCalledTimes(2)
		vi.unstubAllGlobals()
	})
})

describe('CtiClickToDialButton.dial', () => {
	/**
	 * @param {object} state The button's state.
	 * @return {object} A button context.
	 */
	function ctx(state) {
		return { ...dialButton.methods, targetNumber: '+3110', extension: '201', dialing: false, $emit: vi.fn(), ...state }
	}

	it('dials and says the extension will ring', async () => {
		cti.clickToDial.mockResolvedValue({ success: true, callId: 'c1' })
		const c = ctx({})
		const pending = dialButton.methods.dial.call(c)
		expect(c.dialing).toBe(true)
		await pending
		expect(cti.clickToDial).toHaveBeenCalledWith('+3110', '201')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Call initiated — your extension will ring.')
		expect(c.$emit).toHaveBeenCalledWith('initiated', { success: true, callId: 'c1' })
		expect(c.dialing).toBe(false)
	})

	it('refuses without a target or extension, and says why a dial failed', async () => {
		for (const state of [{ targetNumber: '' }, { extension: '' }]) {
			await dialButton.methods.dial.call(ctx(state))
		}
		expect(dialogs.showError.mock.calls).toEqual([
			['Click-to-dial: extension or target missing.'],
			['Click-to-dial: extension or target missing.'],
		])
		expect(cti.clickToDial).not.toHaveBeenCalled()
		dialogs.showError.mockReset()
		for (const [answer, message] of [
			[{ success: false, error: 'busy' }, 'Click-to-dial failed: busy'],
			[{ success: false }, 'Click-to-dial failed: unknown error'],
			[null, 'Click-to-dial failed: unknown error'],
		]) {
			cti.clickToDial.mockResolvedValue(answer)
			const c = ctx({})
			await dialButton.methods.dial.call(c)
			expect(dialogs.showError).toHaveBeenLastCalledWith(message)
			expect(c.$emit).not.toHaveBeenCalled()
		}
		cti.clickToDial.mockRejectedValue(new Error('offline'))
		await dialButton.methods.dial.call(ctx({}))
		expect(dialogs.showError).toHaveBeenLastCalledWith('Click-to-dial failed: offline')
		cti.clickToDial.mockRejectedValue({})
		const c = ctx({})
		await dialButton.methods.dial.call(c)
		expect(dialogs.showError).toHaveBeenLastCalledWith('Click-to-dial failed: network error')
		expect(c.dialing).toBe(false)
	})
})

describe('SegmentBuilder.runPreview', () => {
	/**
	 * @param {object} state The builder's state.
	 * @return {object} A builder context.
	 */
	function ctx(state) {
		return {
			...builder.methods,
			requestSeq: 1,
			entityType: 'client',
			tree: { type: 'AND', children: [] },
			status: 'checking',
			generalError: '',
			estimatedSize: null,
			placeError: vi.fn(),
			t: (app, text) => text,
			...state,
		}
	}

	it('shows the estimate of valid rules', async () => {
		http.post.mockResolvedValue({ data: { valid: true, estimatedSize: 12 } })
		const c = ctx({})
		await builder.methods.runPreview.call(c)
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/segments/preview', { entityType: 'client', rules: { type: 'AND', children: [] } })
		expect(c).toMatchObject({ status: 'valid', estimatedSize: 12 })
		http.post.mockResolvedValue({ data: {} })
		await builder.methods.runPreview.call(c)
		expect(c).toMatchObject({ status: 'valid', estimatedSize: 0 })
	})

	it('places the validator\'s message, or says it could not validate', async () => {
		http.post.mockResolvedValue({ data: { valid: false, error: { path: [0], message: 'Bad' } } })
		const c = ctx({})
		await builder.methods.runPreview.call(c)
		expect(c.status).toBe('invalid')
		expect(c.placeError).toHaveBeenCalledWith({ path: [0], message: 'Bad' })
		http.post.mockResolvedValue({ data: { valid: false } })
		await builder.methods.runPreview.call(c)
		expect(c.placeError).toHaveBeenLastCalledWith('Invalid rules.')
		for (const answer of [Promise.reject(new Error('x')), Promise.resolve({ data: null })]) {
			http.post.mockReturnValue(answer)
			const failed = ctx({})
			await builder.methods.runPreview.call(failed)
			expect(failed).toMatchObject({ status: 'failed', generalError: 'Could not validate rules.' })
		}
	})

	it('drops the answer of a preview that a newer one replaced', async () => {
		let resolve
		http.post.mockReturnValue(new Promise((done) => { resolve = done }))
		const c = ctx({})
		const pending = builder.methods.runPreview.call(c)
		c.requestSeq = 2
		resolve({ data: { valid: true, estimatedSize: 9 } })
		await pending
		expect(c).toMatchObject({ status: 'checking', estimatedSize: null })
	})
})
