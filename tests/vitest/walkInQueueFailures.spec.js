// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The walk-in queue actions when something is missing or fails: a ticket
 * without an id, a write that fails, a queue that cannot load or is empty.
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { createWalkInHandlers, walkInPath } from '../../src/services/walkInQueue.js'

function setup(http = {}) {
	const deps = {
		generateUrl: (path) => '/index.php' + path,
		http: { get: vi.fn(), put: vi.fn().mockResolvedValue({}), ...http },
		showSuccess: vi.fn(),
		showError: vi.fn(),
		translate: (_app, text) => text,
	}
	return { deps, handlers: createWalkInHandlers(deps) }
}

describe('walk-in failures', () => {
	it('builds the collection path without an id', () => {
		expect(walkInPath()).toBe('/apps/openregister/api/objects/pipelinq/walkInTicket')
	})

	it('writes nothing for a ticket without an id', async () => {
		const { deps, handlers } = setup()
		expect(await handlers.walkInCall({ item: { status: 'waiting' } })).toBe(false)
		expect(await handlers.walkInCall()).toBe(false)
		expect(deps.http.put).not.toHaveBeenCalled()
		expect(deps.showSuccess).not.toHaveBeenCalled()
		expect(deps.showError).not.toHaveBeenCalled()
	})

	it('uses the metadata id when the row has none', async () => {
		const { deps, handlers } = setup()
		expect(await handlers.walkInCall({ item: { '@self': { id: 'm1' } } })).toBe(true)
		expect(deps.http.put).toHaveBeenCalledWith('/index.php' + walkInPath('m1'), { status: 'called' })
		expect(deps.showSuccess).toHaveBeenCalledWith('Customer called.')
	})

	it('reports a failed write and does not confirm it', async () => {
		const { deps, handlers } = setup({ put: vi.fn().mockRejectedValue(new Error('500')) })
		expect(await handlers.walkInCall({ item: { id: 't1' } })).toBe(false)
		expect(deps.showError).toHaveBeenCalledWith('Could not update the ticket. Please try again.')
		expect(deps.showSuccess).not.toHaveBeenCalled()
	})

	it('reports a queue that cannot load', async () => {
		const { deps, handlers } = setup({ get: vi.fn().mockRejectedValue(new Error('down')) })
		expect(await handlers.walkInCallNext()).toBe(false)
		expect(deps.showError).toHaveBeenCalledWith('Could not load the queue.')
		expect(deps.http.put).not.toHaveBeenCalled()
	})

	it('says nobody is waiting on an empty queue', async () => {
		const { deps, handlers } = setup({ get: vi.fn().mockResolvedValue({ data: { results: [] } }) })
		expect(await handlers.walkInCallNext()).toBe(false)
		expect(deps.showError).toHaveBeenCalledWith('Nobody is waiting.')
	})

	it('asks for the longest waiting customer and calls them', async () => {
		const get = vi.fn().mockResolvedValue({ data: { results: [{ id: 'w1', status: 'waiting' }] } })
		const { deps, handlers } = setup({ get })
		expect(await handlers.walkInCallNext()).toBe(true)
		expect(get).toHaveBeenCalledWith('/index.php' + walkInPath(), {
			params: { status: 'waiting', _order: { arrivedAt: 'asc' }, _limit: 1 },
		})
		expect(deps.http.put).toHaveBeenCalledWith('/index.php' + walkInPath('w1'), { id: 'w1', status: 'called' })
	})
})
