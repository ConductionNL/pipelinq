/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A facts card that reads "the first match" of a query sorts it by a field.
 * The library reads every string in the source that starts with "@" as a
 * token still to fill, so a sort on "@self.created" left the card unread and
 * the cash shift page said "Nog niet geteld" beside a counted shift. The
 * creation moment sorts as "_created".
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const files = [
	'src/manifest.json',
	'src/menu-layout.simple.json',
	...fs.readdirSync(path.join(ROOT, 'src/manifest.d')).filter((f) => f.endsWith('.json')).map((f) => `src/manifest.d/${f}`),
]

/**
 * Every facts widget declared in a parsed manifest file.
 *
 * @param {unknown} node A JSON node.
 * @param {Array<object>} out The widgets found.
 * @return {Array<object>} The widgets found.
 */
function factsWidgets(node, out = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => factsWidgets(item, out))
	} else if (node && typeof node === 'object') {
		if (node.type === 'facts') {
			out.push(node)
		}
		Object.values(node).forEach((value) => factsWidgets(value, out))
	}
	return out
}

describe('facts sources', () => {
	it('never sort on a field the library reads as a token', () => {
		const offenders = files.flatMap((file) => factsWidgets(JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8')))
			.filter((w) => String(w.content?.source?.sort?.field ?? '').startsWith('@'))
			.map((w) => `${file}#${w.id}`))
		expect(offenders).toEqual([])
	})

	it('reads the newest cash difference of the shift', () => {
		const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
		const variance = manifest.pages.find((p) => p.id === 'CashShiftDetail').config.widgets.find((w) => w.id === 'shift-variance')
		expect(variance.content.source).toEqual({
			register: 'pipelinq',
			schema: 'cashDiff',
			filter: { shift: '@objectId' },
			sort: { field: '_created', dir: 'desc' },
		})
	})
})
