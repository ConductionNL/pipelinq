/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Mijn werk as board PqMijnWerk draws its lists: a rule under each list's
 * header, the kind pills in the board's tones (Opvolging violet, Lead
 * teal) and "Show completed" as a checkbox in the lens bar.
 *
 * @spec openspec/specs/my-work/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'MyWork').config
const widget = (id) => config.widgets.find((entry) => entry.id === id)
const BUCKETS = ['my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer']

describe('Mijn werk lists as the board draws them', () => {
	it('rules each bucket list under its header', () => {
		BUCKETS.forEach((id) => expect(widget(id).content.ruleAbove).toBe(true))
	})

	it('draws a follow-up violet and a lead teal in every list', () => {
		;[...BUCKETS, 'my-items-phone'].forEach((id) => {
			expect(widget(id).content.pill.colorMap).toMatchObject({ lead: 'teal', task: 'purple' })
		})
	})

	it('draws Show completed as a checkbox', () => {
		const toggle = widget('my-lens').content.controls.find((control) => control.style === 'toggle')
		expect(toggle.control).toBe('checkbox')
	})
})
