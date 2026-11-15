/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The field layout the lead and request forms draw with: the spacing and
 * label of a field group, the side-by-side row, and the action bar, in the
 * order the forms' scoped styles apply them.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-49
 */
import { parse } from '@vue/compiler-sfc'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * @param {string} file The component, from src/.
 * @return {Array<{selector: string, body: string}>} Its scoped rules in the order they apply.
 */
function scopedRules(file) {
	const path = resolve(__dirname, '../../src', file)
	const { descriptor } = parse(readFileSync(path, 'utf8'))
	const css = descriptor.styles
		.map((style) => {
			expect(style.scoped).toBe(true)
			return style.src ? readFileSync(resolve(dirname(path), style.src), 'utf8') : style.content
		})
		.join('\n')
		.replace(/\/\*[\s\S]*?\*\//g, '')
	return [...css.matchAll(/([^{}]+)\{([^}]*)\}/g)].map(([, selector, body]) => ({
		selector: selector.trim().replace(/\s+/g, ' '),
		body: body.trim().split(/\s*;\s*/).filter(Boolean).join('; '),
	}))
}

const layout = {
	'.form-group': 'margin-bottom: 16px',
	'.form-group label': 'display: block; font-weight: bold; margin-bottom: 4px; color: var(--color-text-maxcontrast); font-size: 13px',
	'.form-row': 'display: flex; gap: 16px',
	'.form-row .form-group': 'flex: 1',
	'.form-actions': 'display: flex; gap: 8px; margin-top: 20px',
}

describe.each(['views/leads/LeadForm.vue', 'views/requests/RequestForm.vue'])('%s', (file) => {
	const rules = scopedRules(file)

	it('lays out its fields, rows and actions the same way', () => {
		for (const [selector, body] of Object.entries(layout)) {
			expect(rules.filter((rule) => rule.selector === selector).map((rule) => rule.body)).toEqual([body])
		}
	})

	it('applies the shared row rule before its own refinements of it', () => {
		const at = (selector) => rules.findIndex((rule) => rule.selector === selector)
		for (const own of rules.filter((rule) => rule.selector.startsWith('.form-row .form-group-'))) {
			expect(at(own.selector)).toBeGreaterThan(at('.form-row .form-group'))
		}
	})
})

it('keeps the lead form\'s narrow currency field', () => {
	const rules = scopedRules('views/leads/LeadForm.vue')
	expect(rules.find((rule) => rule.selector === '.form-row .form-group--currency').body).toBe('flex: 0 0 96px')
})
