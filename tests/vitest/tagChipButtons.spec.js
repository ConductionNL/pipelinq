/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The round buttons on a tag chip in the tag manager: the remove target and
 * the save / cancel pills share the board's 26px circle; remove is
 * transparent until hovered, save and cancel carry their own tint.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
import { parse } from '@vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const { descriptor } = parse(readFileSync(resolve(__dirname, '../../src/views/settings/TagManager.vue'), 'utf8'))
const css = descriptor.styles.map((style) => style.content).join('\n').replace(/\/\*[\s\S]*?\*\//g, '')
const rules = [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(([, selector, body]) => ({
	selectors: selector.split(',').map((part) => part.trim()),
	declarations: body.split(';').map((d) => d.trim()).filter(Boolean).map((d) => d.split(/:\s*/).map((p) => p.trim())),
}))

/**
 * @param {string} selector One selector exactly as written.
 * @return {object} The declarations every rule naming it sets, later rules winning.
 */
function effective(selector) {
	const out = {}
	for (const rule of rules.filter((r) => r.selectors.includes(selector))) {
		for (const [property, value] of rule.declarations) {
			out[property] = value
		}
	}
	return out
}

const circle = {
	display: 'inline-flex',
	'align-items': 'center',
	'justify-content': 'center',
	width: '26px',
	'min-width': '26px',
	height: '26px',
	'min-height': '26px',
	margin: '0',
	padding: '0',
	border: '0',
	'border-radius': '13px',
	cursor: 'pointer',
}

describe('the tag chip buttons', () => {
	it('draw the remove target as a transparent 26px circle', () => {
		expect(effective('.tag-chip__remove')).toEqual({ ...circle, background: 'transparent', color: 'inherit' })
	})

	it('draw save and cancel as 26px circles', () => {
		expect(effective('.tag-chip__action')).toEqual(circle)
	})

	it('give the remove target its hover before save and cancel get their tint', () => {
		const at = (selector) => rules.findIndex((r) => r.selectors.includes(selector))
		expect(at('.tag-chip__remove:hover')).toBeGreaterThan(at('.tag-chip__remove'))
		expect(at('.tag-chip__action--save')).toBeGreaterThan(at('.tag-chip__action'))
		expect(at('.tag-chip__action--cancel')).toBeGreaterThan(at('.tag-chip__action'))
	})
})
