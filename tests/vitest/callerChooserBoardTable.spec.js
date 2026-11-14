/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqInkomendGesprek: the caller chooser's matches fill the dialog's
 * content width (598, not 695 past the edge), start flush with the content
 * edge, and head the columns in soft ink on the dialog ground.
 *
 * @spec openspec/specs/caller-chooser-board-table/spec.md#requirement-the-caller-chooser-table-is-drawn-as-the-board
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(resolve(__dirname, '../../src/modals/ScreenPopModal.vue'), 'utf8')
const css = source.slice(source.indexOf('<style')).replace(/\/\*[\s\S]*?\*\//g, '')
/**
 * The declarations of the first rule that starts with a selector.
 *
 * @param {string} selector The selector the rule starts with.
 * @return {string} The rule, or '' when there is none.
 */
function rule(selector) {
	const at = css.indexOf(selector + ' {')
	return at === -1 ? '' : css.slice(at, css.indexOf('}', at))
}

describe('caller chooser table as the board draws it', () => {
	it('stays inside the dialog: a fixed layout that wraps long addresses', () => {
		expect(rule('.screen-pop__table')).toContain('table-layout: fixed;')
		expect(css).toMatch(/\.screen-pop__table td \{[^}]*white-space: normal;/)
	})

	it('starts the name and ends the button flush with the content edge', () => {
		expect(css).toMatch(/\.screen-pop__table td:first-child \{[^}]*padding-inline-start: 0;/)
		expect(css).toMatch(/\.screen-pop__table td:last-child \{[^}]*padding-inline-end: 0;/)
	})

	it('heads the columns in soft ink on the dialog ground', () => {
		const head = rule('.screen-pop__table th')
		expect(head).toContain('color: var(--color-text-maxcontrast);')
		expect(head).toContain('background: transparent;')
	})

	it('sets the name in semibold', () => {
		expect(css).toMatch(/\n\.screen-pop__table td:first-child \{\s*font-weight: 600;/)
	})
})
