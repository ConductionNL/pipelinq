/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket list's "New request" opens the new request dialog that board
 * PqNieuwVerzoek draws (RequestCreateDialog), as the navigation's
 * New request does, in both menu profiles. Editing a ticket keeps the form
 * dialog.
 *
 * @spec openspec/specs/request-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

describe('ticket list New request', () => {
	it.each(['src/menu-layout.simple.json', 'src/manifest.json'])('opens RequestCreateDialog in %s', (file) => {
		const tickets = read(file).pages.find((p) => p.id === 'Tickets')
		expect(tickets.config.createModal).toBe('RequestCreateDialog')
	})

	it('keeps the header button on the Add flow', () => {
		const tickets = read('src/menu-layout.simple.json').pages.find((p) => p.id === 'Tickets')
		const add = tickets.config.headerButtons.find((b) => b.action === 'add')
		expect(add.label).toBe('New request')
	})

	it('registers RequestCreateDialog as a modal', () => {
		expect(registry).toMatch(/RequestCreateDialog: \{\s*kind: 'modal'/)
	})
})
