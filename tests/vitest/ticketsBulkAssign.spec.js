/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketsSelectie: "Toewijzen aan" in the bulk band hands the
 * selected tickets to one handler through OpenRegister's assign job.
 *
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-assigns-a-selection-to-one-handler
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const tickets = manifest.pages.find((page) => page.id === 'Tickets')
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const icons = readFileSync(resolve(__dirname, '../../src/icons.js'), 'utf8')

describe('the tickets bulk assign', () => {
	const assign = (tickets.config.bulkActions || []).find((entry) => entry.id === 'assign')

	it('offers "Assign to" first in the bulk band, with the board icon', () => {
		expect(tickets.config.bulkActions[0]).toBe(assign)
		expect(assign.label).toBe('Assign to')
		expect(nl['Assign to']).toBe('Toewijzen aan')
		expect(assign.icon).toBe('AccountArrowRightOutline')
		expect(icons).toContain('AccountArrowRightOutline,')
	})

	it('opens the library assign dialog as an OpenRegister assign job on the ticket assignee', () => {
		expect(assign.handler).toBe('open-modal')
		expect(assign.target).toBe('CnAssignDialog')
		const config = assign.props.config
		expect(config.bulkJob).toEqual({ register: 'pipelinq', schema: 'ticket' })
		expect(config.assignee).toMatchObject({ kinds: ['user'], field: 'assignee' })
		expect(config.note).not.toBe(false)
		for (const text of [config.title, config.assignee.label, config.assignee.placeholder, config.note.label, config.confirm.label]) {
			expect(nl[text], text).toBeTruthy()
		}
	})
})
