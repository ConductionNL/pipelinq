// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Start request widget's lifecycle: loading the type registry and the
 * three recent requests on mount, the submit guards, a refused create, and
 * resetting the form. Only fetch and the store initialiser are doubled.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('../../src/components/widgets/ClientAutocomplete.vue', () => ({ default: {} }))

const TICKET = { register: 'pipelinq', schema: 'ticket' }

let widget
let initializeStores

/**
 * A component context with the widget's data and methods bound to it.
 *
 * @param {object} overrides State to set on top of the data.
 * @return {object}
 */
function context(overrides = {}) {
	const mixinData = (widget.mixins || []).reduce((all, mixin) => ({ ...all, ...(mixin.data ? mixin.data.call({}) : {}) }), {})
	const ctx = { ...mixinData, ...widget.data.call({}), ...overrides }
	for (const [name, fn] of Object.entries(widget.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'token' }
	widget = (await import('../../src/views/widgets/StartRequestWidget.vue')).default
	initializeStores = (await import('../../src/store/store.js')).initializeStores
})

beforeEach(() => {
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

describe('StartRequestWidget lifecycle', () => {
	it('loads the registry and the three newest request tickets on mount', async () => {
		initializeStores.mockResolvedValue({ objectStore: { objectTypeRegistry: { ticket: TICKET } } })
		const fetchMock = vi.fn().mockResolvedValue({
			ok: true,
			json: async () => ({ results: [{ id: 1 }, { id: 2 }, { id: 3 }, { id: 4 }] }),
		})
		globalThis.fetch = fetchMock
		const ctx = context()

		await widget.mounted.call(ctx)

		expect(ctx.config).toEqual({ ticket: TICKET })
		expect(ctx.recentRequests.map((r) => r.id)).toEqual([1, 2, 3])
		const url = fetchMock.mock.calls[0][0]
		expect(url).toContain('ticketType=request')
		expect(url).toContain('_limit=3')
	})

	it('logs a failed initialisation and stays usable', async () => {
		initializeStores.mockRejectedValue(new Error('no stores'))
		const ctx = context()

		await widget.mounted.call(ctx)

		expect(console.error).toHaveBeenCalledWith('StartRequestWidget init error:', expect.any(Error))
		expect(ctx.recentRequests).toEqual([])
	})

	it('does not post without a title', async () => {
		globalThis.fetch = vi.fn()
		const ctx = context({ config: { ticket: TICKET } })

		await ctx.onSubmit()

		expect(ctx.submitted).toBe(true)
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('does not post when the ticket schema is not configured', async () => {
		globalThis.fetch = vi.fn()
		const ctx = context({ config: {}, form: { title: 'Help', category: null, priority: 'normal', channel: null } })

		await ctx.onSubmit()

		expect(console.error).toHaveBeenCalledWith('Ticket schema not configured')
		expect(globalThis.fetch).not.toHaveBeenCalled()
		expect(ctx.submitting).toBe(false)
	})

	it('a refused create is logged, shows no success and clears the busy flag', async () => {
		globalThis.fetch = vi.fn().mockResolvedValue({ ok: false, json: async () => ({}) })
		const ctx = context({ config: { ticket: TICKET }, form: { title: 'Help', category: null, priority: 'normal', channel: null } })

		await ctx.onSubmit()

		expect(console.error).toHaveBeenCalledWith('StartRequestWidget create error:', expect.any(Error))
		expect(ctx.success).toBe(false)
		expect(ctx.submitting).toBe(false)
	})

	it('resetting clears the form and the success state and reloads the recent list', async () => {
		globalThis.fetch = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ results: [] }) })
		const ctx = context({
			config: { ticket: TICKET },
			form: { title: 'Help', category: { id: 'general' }, priority: 'high', channel: 'phone' },
			selectedClient: { id: 'c-1' },
			submitted: true,
			success: true,
			successLink: '/apps/pipelinq/tickets/9',
		})

		ctx.resetForm()

		expect(ctx.form).toEqual({ title: '', category: null, priority: 'normal', channel: null })
		expect(ctx.selectedClient).toBeNull()
		expect(ctx.submitted).toBe(false)
		expect(ctx.success).toBe(false)
		expect(ctx.successLink).toBe('')
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)
	})
})
