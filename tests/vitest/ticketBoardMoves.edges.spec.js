/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Edge cases of the ticket board moves: cards without a status, ids under
 * `@self`, ids that need URL encoding and unknown statuses.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

const patch = vi.fn(async (url, body) => ({ data: { url, ...body } }))
vi.mock('@nextcloud/axios', () => ({ default: { patch: (...args) => patch(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? ''),
}))
globalThis.t = (_app, text) => text

const { moveTicketStatus, ticketStatusTargets } = await import('../../src/utils/ticketBoardMoves.js')

describe('moveTicketStatus edge cases', () => {
	beforeEach(() => patch.mockClear())

	it('refuses a card without a status and writes nothing', async () => {
		await expect(moveTicketStatus({ card: { id: 't1' }, value: 'in_progress' }))
			.rejects.toThrow('You cannot move this ticket from  to In progress.')
		await expect(moveTicketStatus({ card: null, value: 'new' })).rejects.toThrow()
		expect(patch).not.toHaveBeenCalled()
	})

	it('names an unknown target status as it is', async () => {
		await expect(moveTicketStatus({ card: { id: 't1', status: 'new' }, value: 'archived' }))
			.rejects.toThrow('You cannot move this ticket from New to archived.')
		await expect(moveTicketStatus({ card: { id: 't1', status: 'new' } }))
			.rejects.toThrow('You cannot move this ticket from New to .')
		expect(patch).not.toHaveBeenCalled()
	})

	it('takes the id from @self and encodes it in the URL', async () => {
		const response = await moveTicketStatus({
			card: { status: 'in_progress', '@self': { id: 'a/b c' } },
			value: 'converted',
		})
		expect(patch).toHaveBeenCalledTimes(1)
		expect(patch).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/ticket/a%2Fb%20c', { status: 'converted' })
		expect(response).toEqual({ data: { url: '/apps/openregister/api/objects/pipelinq/ticket/a%2Fb%20c', status: 'converted' } })
	})

	it('prefers the top-level id over @self', async () => {
		await moveTicketStatus({ card: { id: 'top', status: 'awaiting_customer', '@self': { id: 'self' } }, value: 'in_progress' })
		expect(patch).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/ticket/top', { status: 'in_progress' })
	})
})

describe('ticketStatusTargets edge cases', () => {
	it('answers every next status from new, as a fresh array', async () => {
		const first = await ticketStatusTargets({ card: { status: 'new' } })
		expect(first).toEqual(['in_progress', 'awaiting_customer', 'rejected', 'completed'])
		first.push('mutated')
		expect(await ticketStatusTargets({ card: { status: 'new' } })).toEqual(['in_progress', 'awaiting_customer', 'rejected', 'completed'])
	})

	it('answers nothing for a card without a status or with an unknown one', async () => {
		expect(await ticketStatusTargets({ card: undefined })).toEqual([])
		expect(await ticketStatusTargets({ card: { status: 'archived' } })).toEqual([])
	})
})
