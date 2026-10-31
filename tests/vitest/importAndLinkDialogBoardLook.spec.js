/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * pl6 residuals of two dialogs, read from their stylesheets.
 * PqContactenImporteren: result rows are 57px as drawn (name and meta at
 * normal line height), divided by a hairline above each row with none under
 * the last, "Al gekoppeld" is the board's 13px pill at the top of its row, and
 * the note has the board's padding, gap and 18px icon.
 * PqAanvraagOmzetten: the sent card sits on the board's sunken ground, its
 * eyebrow muted even under a theme that colours every h3, and the section
 * headings at normal line height.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (p) => readFileSync(resolve(__dirname, '../../src', p), 'utf8')

/**
 * The declarations of the rule whose selector is exactly `sel`.
 *
 * @param {string} source The stylesheet.
 * @param {string} sel The selector.
 * @return {string} The declarations, or ''.
 */
function rule(source, sel) {
	const m = source.match(new RegExp(`(^|\\n)${sel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\{([^}]*)\\}`))
	return m ? m[2] : ''
}

describe('contact import dialog', () => {
	const css = read('dialogs/ContactImportDialog.vue')

	it('divides the rows by a hairline above each, none under the last', () => {
		expect(rule(css, '.import-dialog__results')).not.toContain('border-top')
		expect(rule(css, '.import-result')).toContain('border-top: 1px solid var(--color-border)')
		expect(rule(css, '.import-result')).not.toContain('border-bottom')
	})

	it('sets name and meta at normal line height', () => {
		expect(rule(css, '.import-result__name')).toContain('line-height: normal')
		expect(rule(css, '.import-result__meta')).toContain('line-height: normal')
	})

	it('draws "Al gekoppeld" as the 13px board pill at the top of its row', () => {
		const pill = rule(css, '.import-result__linked')
		expect(pill).toContain('font-size: 13px')
		expect(pill).toContain('padding: 3px 10px')
		expect(pill).toContain('align-self: flex-start')
	})

	it('draws the note with the board padding, gap and icon', () => {
		const note = rule(css, '.import-dialog__note')
		expect(note).toContain('padding: 10px 14px')
		expect(note).toContain('gap: 10px')
		expect(note).toContain('line-height: 1.45')
		expect(css).toContain('<InformationOutline :size="18" class="import-dialog__note-icon" />')
	})
})

describe('link submission dialog', () => {
	const css = read('modals/LinkSubmissionToClientModal.vue')

	it('lays the sent card on the sunken ground', () => {
		expect(rule(css, '.link-submission__sent')).toContain('background: var(--cn-board-sunken-ground, var(--color-background-hover))')
	})

	it('keeps the eyebrow muted against a theme heading colour', () => {
		expect(rule(css, '.link-submission__sent h3')).toContain('color: var(--color-text-maxcontrast) !important')
	})

	it('sets the section headings at normal line height', () => {
		expect(rule(css, '.link-submission h3')).toContain('line-height: normal')
	})
})
