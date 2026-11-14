// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Every text field and select in src/ has an accessible name (WCAG 2.2 AA,
 * 1.3.1 Info and Relationships and 4.1.2 Name, Role, Value).
 *
 * `labelOutside` tells NcTextField, NcTextArea and friends not to render their
 * `label`, and it adds no aria-label either: the input is nameless unless the
 * template ties a `<label for>` to the field's `id` or gives it an
 * `aria-label`. NcSelect behaves the same way: with `labelOutside` its
 * `inputLabel` is dropped, so it needs `ariaLabelCombobox` or a `<label for>`
 * pointing at its `inputId`. Without `labelOutside` it needs `inputLabel` or
 * `ariaLabelCombobox` (hydra gate nc-input-labels).
 *
 * The scan reads the templates, so a new field without a name fails here
 * before it reaches a screen reader.
 */

import { readdirSync, readFileSync } from 'fs'
import { join, relative, resolve } from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../..')
const TEXT_FIELDS = new Set(['NcTextField', 'NcTextArea', 'NcInputField', 'NcPasswordField', 'NcDateTimePickerNative', 'NcActionInput'])
const SELECTS = new Set(['NcSelect', 'NcSelectUsers', 'NcSelectTags'])

const pascal = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase()).replace(/^[a-z]/, (c) => c.toUpperCase())
const camel = (name) => name.replace(/-([a-z])/g, (_, c) => c.toUpperCase())
const blank = (block) => block.replace(/[^\n]/g, ' ')

/**
 * The start tags of a single-file component's template, with their attributes
 * normalised to camelCase keys (`:aria-label` and `ariaLabel` both become
 * `ariaLabel`).
 *
 * @param {string} source the .vue file
 * @return {Array<{name: string, line: number, attrs: Record<string, string|true>}>}
 */
function startTags(source) {
	const template = source
		.replace(/<script[\s\S]*?<\/script>/g, blank)
		.replace(/<style[\s\S]*?<\/style>/g, blank)
		.replace(/<!--[\s\S]*?-->/g, blank)
	const tags = []
	const open = /<([A-Za-z][\w-]*)/g
	let match
	while ((match = open.exec(template))) {
		let end = open.lastIndex
		let quote = null
		for (; end < template.length; end++) {
			const c = template[end]
			if (quote) {
				if (c === quote) quote = null
			} else if (c === '"' || c === '\'') {
				quote = c
			} else if (c === '>') {
				break
			}
		}
		const attrs = {}
		const attr = /([:@#]?[\w.:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'))?/g
		const raw = template.slice(open.lastIndex, end)
		let a
		while ((a = attr.exec(raw))) {
			attrs[camel(a[1].replace(/^(:|v-bind:)/, ''))] = a[2] ?? a[3] ?? true
		}
		tags.push({ name: match[1].includes('-') ? pascal(match[1]) : match[1], line: template.slice(0, match.index).split('\n').length, attrs })
	}
	return tags
}

const isOn = (value) => value !== undefined && value !== 'false'
const text = (value) => String(value).trim()

/**
 * The fields of one template that have no accessible name.
 *
 * @param {string} source the .vue file
 * @return {Array<{line: number, name: string}>}
 */
export function namelessFields(source) {
	const tags = startTags(source)
	const labelled = new Set(tags.filter((t) => t.name === 'label' && t.attrs.for).map((t) => text(t.attrs.for)))
	const hasLabelFor = (id) => id !== undefined && labelled.has(text(id))
	return tags.filter(({ name, attrs }) => {
		const outside = isOn(attrs.labelOutside)
		if (SELECTS.has(name)) {
			if (attrs.ariaLabelCombobox || (attrs.inputLabel && !outside)) return false
			return !(outside && hasLabelFor(attrs.inputId))
		}
		if (!TEXT_FIELDS.has(name) || !outside) return false
		if (attrs.ariaLabel || attrs.ariaLabelledby) return false
		return !hasLabelFor(attrs.id ?? attrs.inputId)
	}).map(({ line, name }) => ({ line, name }))
}

/**
 * Every .vue file under a directory.
 *
 * @param {string} dir directory to walk
 * @return {string[]}
 */
function vueFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name)
		if (entry.isDirectory()) return vueFiles(path)
		return path.endsWith('.vue') ? [path] : []
	})
}

describe('the field scan', () => {
	it('flags a labelOutside field with only its own label', () => {
		expect(namelessFields('<template><NcTextField :label="t(\'pipelinq\', \'Name\')" labelOutside /></template>')).toHaveLength(1)
	})

	it('accepts a labelOutside field with an aria-label', () => {
		expect(namelessFields('<template><nc-text-area label-outside :aria-label="t(\'pipelinq\', \'Notes\')" /></template>')).toEqual([])
	})

	it('accepts a labelOutside field tied to a label', () => {
		expect(namelessFields('<template><label for="x-name">Name</label><NcTextField id="x-name" labelOutside /></template>')).toEqual([])
	})

	it('flags a label that points at another field', () => {
		expect(namelessFields('<template><label for="x-other">Name</label><NcTextField id="x-name" labelOutside /></template>')).toHaveLength(1)
	})

	it('flags a select whose inputLabel is dropped by labelOutside', () => {
		expect(namelessFields('<template><NcSelect inputLabel="Queue" :labelOutside="true" /></template>')).toHaveLength(1)
	})

	it('accepts a select with ariaLabelCombobox, a visible inputLabel or a label for its inputId', () => {
		expect(namelessFields('<template><NcSelect aria-label-combobox="Queue" labelOutside /></template>')).toEqual([])
		expect(namelessFields('<template><NcSelect :input-label="t(\'pipelinq\', \'Queue\')" /></template>')).toEqual([])
		expect(namelessFields('<template><label for="q">Queue</label><NcSelect input-label="Queue" input-id="q" labelOutside /></template>')).toEqual([])
	})

	it('flags a select with no name at all', () => {
		expect(namelessFields('<template><NcSelect :options="options" /></template>')).toHaveLength(1)
	})
})

describe('every field in src has an accessible name', () => {
	it('finds no nameless field', () => {
		const nameless = vueFiles(join(ROOT, 'src')).flatMap((file) =>
			namelessFields(readFileSync(file, 'utf8')).map(({ line, name }) => `${relative(ROOT, file)}:${line} ${name}`))
		expect(nameless).toEqual([])
	})
})
