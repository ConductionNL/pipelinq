/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The resource page's Overview as the PqResource board draws it: the tab
 * strip is the board's folder strip with the resource details card joined to
 * it (not a bordered, padded panel around every card), the working hours and
 * vacations cards stand 20px under it at their own height, and their tables
 * draw a white head in 14px weight 600 with 18px outer insets, inside a 1px
 * frame without a shadow. Read as text.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/bookings/ResourceDetail.vue'), 'utf8')
/**
 * @param {string} selector A rule's selector, as written in the style block.
 * @return {string} The rule's body, '' when there is none.
 */
function rule(selector) {
	const at = src.indexOf(`\n${selector} {`)
	return at === -1 ? '' : src.slice(at, src.indexOf('}', at))
}

describe('the resource overview', () => {
	it('uses the folder strip, not a card panel', () => {
		expect(src).toContain('<CnTabs :ariaLabel="t(\'pipelinq\', \'Resource\')">')
		expect(src).not.toMatch(/<CnTabs card/)
	})

	it('stands the schedule cards 20px under the details card at their own height', () => {
		expect(rule('.resource-schedule')).toContain('margin-top: 20px')
		expect(rule('.resource-schedule')).toContain('align-items: start')
	})

	it('draws the schedule tables as the board does', () => {
		// The library's table.css raises the frame; the board draws it flat.
		expect(rule('.viewTableContainer')).toContain('box-shadow: none')
		expect(rule('.viewTable')).toContain('font-size: 14px')
		expect(rule('.viewTable th')).toContain('background-color: var(--color-main-background)')
		expect(rule('.viewTable th')).toContain('font-weight: 600')
		expect(rule('.viewTable :is(th, td):first-child')).toContain('padding-inline-start: 18px')
	})
})
