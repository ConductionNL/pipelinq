/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketsSelectie (#826): a saved view of the tickets list is shared
 * from the toolbar through nextcloud-vue's saved-view share dialog.
 *
 * @spec openspec/changes/tickets-share-saved-view/specs/ticket-list/spec.md#requirement-a-saved-view-of-the-tickets-list-can-be-shared
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))

describe('tickets list saved views', () => {
	it('saves views and shares the open one from the toolbar', () => {
		const tickets = manifest.pages.find((p) => p.id === 'Tickets' && p.route === '/tickets')
		expect(tickets.config.allowSavedViews).toBe(true)
		expect(tickets.config.savedViewShare).toBe(true)
		expect(tickets.config.register).toBe('pipelinq')
		expect(tickets.config.schema).toBe('ticket')
	})
})
