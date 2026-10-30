/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicketBewerken: the edit dialog draws Titel across both columns,
 * then Omschrijving (three lines) and Bericht aan de klant (two lines) as
 * multi-line fields across both columns. A field override naming the
 * `textarea` widget makes CnFormDialog draw a textarea and span it over both
 * columns; `width: 'full'` and `rows` need nc-vue's
 * fix/form-field-full-width-and-rows and are ignored before it.
 *
 * @spec openspec/changes/ticket-edit-text-fields/specs/ticket-detail-page/spec.md#requirement-the-ticket-edit-dialog-draws-its-long-texts-as-multi-line-fields
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))
const ticketPage = layout.pages.find((page) => page.id === 'TicketDetail')
const overrides = ticketPage.config.fieldOverrides

describe('the ticket edit dialog', () => {
	it('is a two-column form', () => {
		expect(ticketPage.config.formColumns).toBe(2)
	})

	it.each(['description', 'customerMessage'])('draws %s as a textarea across both columns', (key) => {
		expect(ticketPage.config.includeFields).toContain(key)
		expect(overrides[key].widget).toBe('textarea')
	})

	it('draws Omschrijving three lines and Bericht aan de klant two lines high', () => {
		expect(overrides.description.rows).toBe(3)
		expect(overrides.customerMessage.rows).toBe(2)
	})

	it('keeps the title a single line across both columns, above the description', () => {
		expect(overrides.title.widget).toBeUndefined()
		expect(overrides.title.width).toBe('full')
		expect(overrides.title.order).toBeLessThan(overrides.description.order)
	})
})
