// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/ticketAssign.js: "Assign to me" on a Queue row
 * (openspec/changes/detail-pages-read-at-a-glance, pipelinq review G1).
 */

import { describe, expect, it, vi } from 'vitest'
import manifest from '../../src/manifest.json'
import { assignToMePath, createTicketHandlers } from '../../src/services/ticketAssign.js'

function deps(post) {
	return {
		generateUrl: (path) => `/index.php${path}`,
		http: { post },
		router: { push: vi.fn() },
		showSuccess: vi.fn(),
		showError: vi.fn(),
		translate: (app, text) => text,
	}
}

describe('assignTicketToMe', () => {
	it('posts to the assign endpoint, then opens the ticket', async () => {
		const post = vi.fn().mockResolvedValue({ data: {} })
		const d = deps(post)
		const ok = await createTicketHandlers(d).assignTicketToMe({ item: { id: 't-1' } })

		expect(ok).toBe(true)
		expect(post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/tickets/t-1/assign-to-me')
		expect(d.router.push).toHaveBeenCalledWith({ name: 'TicketDetail', params: { id: 't-1' } })
		expect(d.showSuccess).toHaveBeenCalled()
	})

	it('reports a refusal and stays on the Queue', async () => {
		const post = vi.fn().mockRejectedValue({ response: { data: { error: 'You cannot change this ticket' } } })
		const d = deps(post)
		const ok = await createTicketHandlers(d).assignTicketToMe({ item: { id: 't-2' } })

		expect(ok).toBe(false)
		expect(d.showError).toHaveBeenCalledWith('You cannot change this ticket')
		expect(d.router.push).not.toHaveBeenCalled()
	})

	it('does nothing without a row id', async () => {
		const post = vi.fn()
		expect(await createTicketHandlers(deps(post)).assignTicketToMe({})).toBe(false)
		expect(post).not.toHaveBeenCalled()
	})

	it('escapes the id in the path', () => {
		expect(assignToMePath('a/b')).toBe('/apps/pipelinq/api/tickets/a%2Fb/assign-to-me')
	})
})

describe('the bundled manifest', () => {
	const page = (id) => manifest.pages.find((p) => p.id === id)

	it('offers Assign to me on every Queue row', () => {
		const queue = manifest.pages.filter((p) => p.id === 'Queue').at(-1)
		expect(queue.config.actions).toContainEqual(
			expect.objectContaining({ id: 'assign-to-me', handler: 'assignTicketToMe' }),
		)
	})

	it('offers Assign to me on the ticket page', () => {
		expect(page('TicketDetail').config.headerActions).toContainEqual(
			expect.objectContaining({
				type: 'api-call',
				url: '/apps/pipelinq/api/tickets/@objectId/assign-to-me',
			}),
		)
	})
})
