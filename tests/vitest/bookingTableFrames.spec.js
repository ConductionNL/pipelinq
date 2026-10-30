/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqResource (Werktijden, Vakanties) and PqService (Opbouw in
 * stappen) draw a table inside a card as a flat frame: a hairline, 8px
 * corners, no shadow. Live drew a raised 12px card in the card (the library
 * table.css shadow, the board look's 12px large radius). Read as text.
 *
 * @spec openspec/changes/booking-table-frames/specs/appointment-booking/spec.md#requirement-a-table-inside-a-booking-card-is-a-flat-frame
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * The scoped `.viewTableContainer` rule of a view.
 *
 * @param {string} file The view under src/views/bookings.
 * @return {string} The rule body.
 */
function frameRule(file) {
	const src = readFileSync(resolve(__dirname, '../../src/views/bookings', file), 'utf8')
	const style = src.slice(src.indexOf('<style scoped>'))
	const start = style.indexOf('.viewTableContainer {')
	return style.slice(start, style.indexOf('}', start))
}

describe('the booking table frames', () => {
	for (const file of ['ResourceDetail.vue', 'ServiceDetail.vue']) {
		it(`draws ${file}'s table frame flat with 8px corners`, () => {
			const rule = frameRule(file)
			expect(rule).toContain('border-radius: 8px;')
			expect(rule).toContain('box-shadow: none;')
			expect(rule).toContain('border: 1px solid var(--color-border);')
		})
	}
})
