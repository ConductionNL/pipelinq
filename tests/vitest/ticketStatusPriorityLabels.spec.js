/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A ticket's status and priority read as words: every enum value carries an
 * x-enum-labels entry with a Dutch translation, so a badge cell shows "In
 * behandeling" and "Normaal" instead of "in_progress" and "normal" (pl2
 * PqOperationeel Klachten). Schema 1.7.0.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (p) => JSON.parse(readFileSync(resolve(__dirname, '../..', p), 'utf8'))
const ticket = read('lib/Settings/register.d/99-unify-ticket-supertype.json').components.schemas.ticket
const nl = read('l10n/nl.json').translations

describe('ticket status and priority labels', () => {
	it.each(['status', 'priority'])('labels every %s value, in Dutch too', (field) => {
		const property = ticket.properties[field]
		expect(Object.keys(property['x-enum-labels']).sort()).toEqual([...property.enum].sort())
		for (const label of Object.values(property['x-enum-labels'])) {
			expect(nl[label]).toBeTruthy()
		}
	})

	it('bumps the ticket schema for the change', () => {
		expect(ticket.version).toBe('1.7.0')
	})
})
