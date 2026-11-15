/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqAfspraakVerplaatsen draws "Nu" and "Nieuw" 8px apart at 14px. Nextcloud's
 * global stylesheet pads every dt and dd by 12px and gives a dt a fixed,
 * right-aligned width, which put the two rows about 55px apart. The dialog
 * undoes those rules for its own list, and its body adds no padding above the
 * first field.
 *
 * @spec openspec/changes/screens-appointments-board/specs/appointment-booking/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(resolve(__dirname, '../../src/dialogs/RescheduleBookingDialog.vue'), 'utf8').split('<style')[1]

/**
 * @param {string} selector The rule's selector.
 * @return {string} The rule's body.
 */
function block(selector) {
	const start = css.indexOf(selector + ' {')
	expect(start, selector).toBeGreaterThan(-1)
	return css.slice(start, css.indexOf('}', start))
}

describe('the reschedule dialog slots', () => {
	it('sets the list in 14px rows 8px apart, without server padding', () => {
		const list = block('.reschedule-form__slots')
		expect(list).toMatch(/gap: 8px 16px;/)
		expect(list).toMatch(/font-size: 14px;/)
		expect(list).toMatch(/padding: 0;/)
	})

	it('puts the label at the start of its column, without server padding', () => {
		const dt = block('.reschedule-form__slots dt')
		expect(dt).toMatch(/width: auto;/)
		expect(dt).toMatch(/text-align: start;/)
		expect(dt).toMatch(/white-space: normal;/)
		expect(dt).toMatch(/padding: 0;/)
	})

	it('starts the value without server padding', () => {
		expect(block('.reschedule-form__slots dd')).toMatch(/padding: 0;/)
	})

	it('adds no padding of its own above the first field', () => {
		expect(block('.reschedule-form')).not.toMatch(/padding/)
	})
})
