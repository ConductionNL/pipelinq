/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketBewerken: the edit dialog's header names the ticket, with
 * "Bewerken · PQ-2026-0417" over the title and "PQ-2026-0417 · Verzoek" under
 * it. `editEyebrow` and the record tokens need nc-vue's
 * feat/bpq3-edit-form-eyebrow-record-tokens; before it the eyebrow is ignored
 * and the subtitle shows its tokens as written, so the subtitle ships with it.
 *
 * @spec openspec/changes/ticket-edit-header/specs/ticket-detail-page/spec.md#requirement-the-ticket-edit-dialog-names-the-ticket
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const ticketPage = layout.pages.find((page) => page.id === 'TicketDetail')
const nl = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

describe('the ticket edit dialog header', () => {
	it('names the ticket over and under the title', () => {
		expect(ticketPage.config.editTitle).toBe('Edit ticket')
		expect(ticketPage.config.editEyebrow).toBe('Edit · {reference}')
		expect(ticketPage.config.editSubtitle).toBe('{reference} · {ticketType}')
	})

	it('reads Dutch', () => {
		expect(nl['Edit · {reference}']).toBe('Bewerken · {reference}')
		expect(nl['{reference} · {ticketType}']).toBe('{reference} · {ticketType}')
	})
})
