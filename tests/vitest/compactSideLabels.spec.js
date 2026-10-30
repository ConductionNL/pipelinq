/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compact Contact channels and Relationships side cards (PqKlant,
 * PqOrganisatie, PqContactpersoon) draw a label column beside the values.
 * Nextcloud's global stylesheet gives every dt a 130px width, right-aligned
 * text and 12px padding, which pushed the labels under the values; the
 * cards undo those rules for their own lists.
 *
 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-is-the-board
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const style = (file) => readFileSync(resolve(__dirname, '../../src/components', file), 'utf8').split('<style')[1]

function block(css, selector) {
	const start = css.indexOf(selector + ' {')
	expect(start, selector).toBeGreaterThan(-1)
	return css.slice(start, css.indexOf('}', start))
}

describe.each([
	['ContactChannelsSection.vue', '.contact-channels-section__compact-list', '.contact-channels-section__compact-row'],
	['ContactRelationships.vue', '.contact-relationships__compact', '.contact-relationships__compact-row'],
])('%s', (file, list, row) => {
	const css = style(file)

	it('drops the server padding on the list', () => {
		expect(block(css, list)).toMatch(/padding: 0;/)
	})

	it('puts the label at the start of its own column, as wide as the column', () => {
		const dt = block(css, `${row} dt`)
		expect(dt).toMatch(/width: auto;/)
		expect(dt).toMatch(/text-align: start;/)
		expect(dt).toMatch(/white-space: normal;/)
		expect(dt).toMatch(/padding: 0;/)
	})

	it('starts the value at the column without server padding', () => {
		expect(block(css, `${row} dd`)).toMatch(/padding: 0;/)
	})

	it('gives the label column the board width', () => {
		expect(block(css, row)).toMatch(/grid-template-columns: 110px minmax\(0, 1fr\);/)
	})
})
