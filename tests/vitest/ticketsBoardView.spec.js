/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tickets list as the LijstBord board draws it: a board by status, a drop
 * that follows the ticket lifecycle, and the work lenses.
 *
 * @spec openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const patch = vi.fn(async (url, body) => ({ data: { url, ...body } }))
vi.mock('@nextcloud/axios', () => ({ default: { patch: (...args) => patch(...args) } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_m, key) => vars[key] ?? ''),
}))
globalThis.t = (_app, text) => text

const { moveTicketStatus, ticketStatusTargets } = await import('../../src/utils/ticketBoardMoves.js')
const registrySource = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const tickets = manifest.pages.find((page) => page.id === 'Tickets')

describe('the tickets board', () => {
	it('offers the board view with columns from the status', () => {
		expect(tickets.config.viewModes).toEqual(['table', 'cards', 'board'])
		expect(tickets.config.board.statusField).toBe('status')
		expect(tickets.config.board.moveHandler).toBe('moveTicketStatus')
		expect(tickets.config.board.moveTargetsHandler).toBe('ticketStatusTargets')
	})

	it('switches its columns between status, type, channel and handler', () => {
		const fields = tickets.config.board.columnFields
		expect(fields.map((entry) => entry.field)).toEqual(['status', 'ticketType', 'channel', 'assignee'])
		expect(fields.filter((entry) => entry.readOnly).map((entry) => entry.field)).toEqual(['ticketType', 'channel'])
	})

	it('registers both handlers', () => {
		expect(registrySource).toMatch(/moveTicketStatus: \{\s*kind: 'handler',\s*handler: moveTicketStatus,/)
		expect(registrySource).toMatch(/ticketStatusTargets: \{\s*kind: 'handler',\s*handler: ticketStatusTargets,/)
	})
})

describe('the work lenses', () => {
	const labels = () => tickets.config.quickFilters.map((lens) => lens.label)

	it('starts on All open and keeps the type lenses after the work lenses', () => {
		expect(labels()).toEqual(['All open', 'Mine', 'Unassigned', 'Waiting for customer', 'Past the deadline', 'All', 'Requests', 'Complaints', 'Contactmomenten'])
		expect(tickets.config.quickFilters[0].default).toBe(true)
		expect(tickets.config.quickFilters[0].filter.status).toEqual(['new', 'in_progress', 'awaiting_customer'])
	})

	it('reads unassigned as no assignee and past the deadline as a deadline before now', () => {
		const lens = (label) => tickets.config.quickFilters.find((entry) => entry.label === label).filter
		expect(lens('Unassigned').assignee).toBe('IS NULL')
		expect(lens('Mine').assignee).toBe('@me')
		expect(lens('Past the deadline').slaDeadline).toEqual({ lt: '@now' })
	})
})

describe('moveTicketStatus', () => {
	beforeEach(() => patch.mockClear())

	it('writes a move the lifecycle allows', async () => {
		await moveTicketStatus({ card: { id: 't1', status: 'new' }, value: 'in_progress' })
		expect(patch).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/ticket/t1', { status: 'in_progress' })
	})

	it('refuses a move the lifecycle does not allow and writes nothing', async () => {
		await expect(moveTicketStatus({ card: { id: 't1', status: 'completed' }, value: 'new' }))
			.rejects.toThrow('You cannot move this ticket from Completed to New.')
		expect(patch).not.toHaveBeenCalled()
	})
})

describe('ticketStatusTargets', () => {
	it('answers the statuses the lifecycle allows from the current one', async () => {
		expect(await ticketStatusTargets({ card: { status: 'awaiting_customer' } })).toEqual(['in_progress', 'completed', 'rejected'])
		expect(await ticketStatusTargets({ card: { status: 'completed' } })).toEqual([])
	})
})
