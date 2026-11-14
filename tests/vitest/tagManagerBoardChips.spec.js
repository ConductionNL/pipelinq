/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A tag on the admin tag managers is the board's 34px pill with a 26px round
 * remove target (pl2 PqBeheer "Verwijderen" pills 26 vs 40: Nextcloud's
 * button minimum made the bare × 40px). Read as text.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/settings/TagManager.vue'), 'utf8')
const rule = (sel) => src.slice(src.indexOf(`${sel} {`), src.indexOf('}', src.indexOf(`${sel} {`)))

describe('the tag manager chips', () => {
	it('draws a tag as a 34px pill, 12px in, 14/600', () => {
		expect(rule('.tag-chip')).toContain('height: 34px;')
		expect(rule('.tag-chip')).toContain('padding: 0 4px 0 12px;')
		expect(rule('.tag-chip')).toContain('font-weight: 600;')
	})

	it('draws the remove target as a named 26px circle with the close icon', () => {
		expect(rule('.tag-chip__remove')).toContain('min-height: 26px;')
		expect(rule('.tag-chip__remove')).toContain('border-radius: 13px;')
		expect(src).toContain(`:aria-label="t('pipelinq', 'Remove')"`)
		expect(src).toContain('<Close :size="18" />')
	})
})
