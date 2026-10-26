// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The walk-in queue actions (openspec/changes/screens-inloop-resource-board).
 */

import { describe, expect, it, vi } from 'vitest'
import { createWalkInHandlers, ticketBody, walkInPath } from '../../src/services/walkInQueue.js'

/**
 * Handlers over a fake client.
 *
 * @param {object} [http] Overrides for the client.
 * @return {object} The handlers and their spies.
 */
function setup(http = {}) {
	const deps = {
		generateUrl: (path) => path,
		http: { get: vi.fn(), put: vi.fn().mockResolvedValue({}), ...http },
		showSuccess: vi.fn(),
		showError: vi.fn(),
		translate: (_app, text) => text,
		now: () => new Date('2026-10-07T09:55:00Z'),
	}
	return { deps, handlers: createWalkInHandlers(deps) }
}

describe('walkInPath and ticketBody', () => {
	it('builds the object path', () => {
		expect(walkInPath('a b')).toBe('/apps/openregister/api/objects/pipelinq/walkInTicket/a%20b')
	})

	it('drops the metadata block and applies the values', () => {
		expect(ticketBody({ id: '1', status: 'waiting', '@self': {} }, { status: 'called' })).toEqual({ id: '1', status: 'called' })
	})
})

describe('the row actions', () => {
	it('Help serves the ticket and stamps the time', async () => {
		const { deps, handlers } = setup()
		expect(await handlers.walkInServe({ item: { id: 't1', status: 'called' } })).toBe(true)
		expect(deps.http.put).toHaveBeenCalledWith(walkInPath('t1'), {
			id: 't1',
			status: 'served',
			actualServedAt: '2026-10-07T09:55:00.000Z',
		})
	})

	it('reports a failed write and returns false', async () => {
		const { deps, handlers } = setup({ put: vi.fn().mockRejectedValue(new Error('x')) })
		expect(await handlers.walkInAbandon({ item: { id: 't1' } })).toBe(false)
		expect(deps.showError).toHaveBeenCalled()
	})
})

describe('Call next', () => {
	it('calls the longest waiting customer', async () => {
		const { deps, handlers } = setup({ get: vi.fn().mockResolvedValue({ data: { results: [{ id: 'first', status: 'waiting' }] } }) })
		expect(await handlers.walkInCallNext()).toBe(true)
		expect(deps.http.get.mock.calls[0][1].params).toMatchObject({ status: 'waiting', _limit: 1 })
		expect(deps.http.put).toHaveBeenCalledWith(walkInPath('first'), { id: 'first', status: 'called' })
	})

	it('says so when nobody waits', async () => {
		const { deps, handlers } = setup({ get: vi.fn().mockResolvedValue({ data: { results: [] } }) })
		expect(await handlers.walkInCallNext()).toBe(false)
		expect(deps.showError).toHaveBeenCalledWith('Nobody is waiting.')
	})
})
