/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqContactpersoon: the Channels and Relationships sections list
 * label and value side by side, rows 8px apart. Nextcloud's core stylesheet
 * gives every `dt` a 130px width, end-aligned text and 12px padding, and
 * every `dl` 12px padding: the labels ran over their values ("E-jsmit…")
 * and each row stood 52px tall. The compact lists reset those.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const style = (name) => readFileSync(resolve(__dirname, '../../src/components', name), 'utf8').split('<style')[1]
function rule (css, selector) {
	const start = css.indexOf(selector + ' {')
	expect(start, selector).toBeGreaterThan(-1)
	return css.slice(start, css.indexOf('}', start))
}

describe.each([
	['ContactChannelsSection.vue', '.contact-channels-section__compact-list', '.contact-channels-section__compact-row'],
	['ContactRelationships.vue', '.contact-relationships__compact', '.contact-relationships__compact-row'],
])('%s compact list', (file, list, row) => {
	const css = style(file)

	it('takes no core dl padding', () => {
		expect(rule(css, list)).toMatch(/padding: 0;/)
	})

	it('keeps each label in its column, start-aligned, without core padding', () => {
		const dt = rule(css, `${row} dt`)
		expect(dt).toMatch(/width: auto;/)
		expect(dt).toMatch(/padding: 0;/)
		expect(dt).toMatch(/text-align: start;/)
		expect(rule(css, `${row} dd`)).toMatch(/padding: 0;/)
	})
})
