/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

// Board PqBerichtSturen: the chosen channel stays white on the grey track and
// the options stand 2px apart. Nextcloud's server.css paints every plain
// button on :hover and :focus with the primary light tone and gives it a 3px
// end margin, at specificity (0,2,1); the switch rules have to outrank it.
const vue = fs.readFileSync(path.resolve(__dirname, '../../src/modals/SendMessageModal.vue'), 'utf8')
const style = vue.split('<style')[1]

function rule(selector) {
	const start = style.indexOf(selector + ' {')
	expect(start, selector).toBeGreaterThan(-1)
	return style.slice(start, style.indexOf('}', start))
}

describe('the send message channel switch', () => {
	it('outranks server.css for the margin, so the options stand 2px apart', () => {
		expect(rule('.send-message__switch .send-message__switch-option')).toMatch(/margin: 0;/)
	})

	it('keeps the chosen option white when it is hovered or focused', () => {
		const on = rule('.send-message__switch .send-message__switch-option--on,\n.send-message__switch .send-message__switch-option--on:is(:hover, :focus)')
		expect(on).toMatch(/background: var\(--color-main-background\);/)
	})

	it('draws an option that is not chosen in the soft ink, with a quiet hover', () => {
		expect(rule('.send-message__switch .send-message__switch-option')).toMatch(/color: var\(--cn-board-text-soft, #3d4047\);/)
		expect(rule('.send-message__switch .send-message__switch-option:not(.send-message__switch-option--on, :disabled):is(:hover, :focus)')).toMatch(/background: var\(--color-background-hover\);/)
	})
})
