/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Tickets can be imported from the ticket list, as board PqImporteren draws
 * it: the header's Actions menu offers the library's Import, which opens the
 * mass import dialog. The menu kept only the page's header actions, which the
 * ticket list has none of, so it was hidden and there was no way in.
 *
 * @spec openspec/changes/ticket-import/specs/kcc-werkplek/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const simple = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/menu-layout.simple.json'), 'utf8'))

describe('ticket import', () => {
	it('offers Import in the ticket list Actions menu', () => {
		const tickets = simple.pages.find((p) => p.id === 'Tickets')
		const menu = tickets.config.headerButtons.find((b) => b.action === 'actions-menu')
		expect(menu.items).toContain('import')
		expect(tickets.config.showMassImport).not.toBe(false)
	})
})
