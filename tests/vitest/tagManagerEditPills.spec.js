/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqBeheer: a tag being renamed or added ends in two 26px round pills,
 * Save in the success tint with a check, Cancel in grey with a cross (pl3
 * PqBeheer 20/41: live drew a bare ✓ and ✕ in text). Read as text.
 *
 * @spec exclude board measure (pl3 PqBeheer 20/41), presentation only
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const src = readFileSync(resolve(__dirname, '../../src/views/settings/TagManager.vue'), 'utf8')
const rule = (sel) => src.slice(src.indexOf(`\n${sel} {`), src.indexOf('}', src.indexOf(`\n${sel} {`)))

describe('the tag manager edit pills', () => {
	it('draws save and cancel as icons, named, never a text glyph', () => {
		expect(src).not.toContain('&#10003;')
		expect(src).not.toContain('&#10005;')
		expect(src.match(/<Check :size="16" \/>/g)).toHaveLength(2)
		expect(src.match(/class="tag-chip__action tag-chip__action--save"\n\s*type="button"\n\s*:aria-label=/g)).toHaveLength(2)
	})

	it('makes each a 26px circle, save tinted success, cancel grey', () => {
		expect(rule('.tag-chip__action')).toContain('height: 26px;')
		expect(rule('.tag-chip__action')).toContain('border-radius: 13px;')
		expect(rule('.tag-chip__action--save')).toContain('background-color: var(--cn-board-success-tint')
		expect(rule('.tag-chip__action--cancel')).toContain('background-color: var(--color-background-dark')
	})
})
