/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The field layout the client form, the contact form and the prospect
 * settings draw with: the spacing and label of a field group and the
 * side-by-side row, in the order their scoped styles apply them.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
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
	'.form-group label': 'display: block; margin-bottom: 4px; font-weight: bold',
	'.form-row': 'display: flex; gap: 16px',
	'.form-row .form-group': 'flex: 1',
}

describe.each(['views/clients/ClientForm.vue', 'views/contacts/ContactForm.vue', 'views/settings/ProspectSettings.vue'])('%s', (file) => {
	const rules = scopedRules(file)
	const at = (selector) => rules.findIndex((rule) => rule.selector === selector)

	it('lays out its field groups and rows the same way', () => {
		for (const [selector, body] of Object.entries(layout)) {
			expect(rules.filter((rule) => rule.selector === selector).map((rule) => rule.body)).toEqual([body])
		}
	})

	it('applies the shared label rule before any refinement of a group\'s label', () => {
		for (const own of rules.filter((rule) => /^\.form-group-[\w-]+ label$/.test(rule.selector))) {
			expect(at(own.selector)).toBeGreaterThan(at('.form-group label'))
		}
	})
})

it('keeps the prospect settings\' plain checkbox label', () => {
	const rules = scopedRules('views/settings/ProspectSettings.vue')
	expect(rules.find((rule) => rule.selector === '.form-group--checkbox label').body).toBe('margin-bottom: 0; font-weight: normal')
})
