// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqContactenImporteren: a chosen card keeps its outlined Import button
 * and its row takes the selection tint; a card shows grey initials, not a
 * coloured avatar; "Already linked" is the soft success pill; the note is a
 * neutral grey line, not a blue info card.
 */
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(async () => ({ data: [] })), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const { default: ContactImportDialog } = await import('../../src/dialogs/ContactImportDialog.vue')
const source = readFileSync(resolve(__dirname, '../../src/dialogs/ContactImportDialog.vue'), 'utf8')
const style = source.split('<style')[1]

async function dialogWithResults() {
	const w = mount(ContactImportDialog, { attachTo: document.body })
	await w.setData({
		query: 'an',
		results: [
			{ uid: 'a', name: 'Anouk Verhoef', email: 'a@example.nl', alreadyLinked: false },
			{ uid: 'b', name: 'Anna de Vries', alreadyLinked: true },
		],
	})
	return w
}

describe('the contact import dialog, as the board draws it', () => {
	it('keeps the chosen card\'s Import button outlined and tints its row', async () => {
		const w = await dialogWithResults()
		const button = w.find('[data-testid="import-result-toggle"]')
		await button.trigger('click')
		expect(w.vm.selected).toEqual(['a'])
		expect(button.attributes('aria-pressed')).toBe('true')
		expect(button.classes()).toContain('button-vue--secondary')
		expect(w.findAll('.import-result')[0].classes()).toContain('import-result--selected')
		await button.trigger('click')
		expect(w.vm.selected).toEqual([])
		expect(button.attributes('aria-pressed')).toBe('false')
		w.unmount()
	})

	it('shows grey initials instead of a coloured avatar', async () => {
		const w = await dialogWithResults()
		expect(w.findAll('.import-result__initials').map((el) => el.text())).toEqual(['AV', 'AV'])
		expect(source).not.toMatch(/<NcAvatar/)
		const initials = style.match(/\.import-result__initials \{([^}]*)\}/)[1]
		expect(initials).toMatch(/font-size: 12px;/)
		expect(initials).toMatch(/background: var\(--color-background-hover\);/)
		w.unmount()
	})

	it('draws "Already linked" in the soft success tone', () => {
		const pill = style.match(/\.import-result__linked \{([^}]*)\}/)[1]
		expect(pill).toMatch(/background: var\(--cn-board-success-tint, #e7f3ea\);/)
		expect(pill).toMatch(/color: var\(--cn-board-success-ink, #1e6b2a\);/)
		expect(pill).toMatch(/font-size: 13px;/)
	})

	it('writes the note as a neutral grey line', async () => {
		const w = await dialogWithResults()
		expect(w.find('[data-testid="import-dialog-note"]').exists()).toBe(true)
		expect(source).not.toMatch(/<NcNoteCard/)
		w.unmount()
	})
})
