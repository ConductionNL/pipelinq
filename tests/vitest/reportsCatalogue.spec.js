/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Reports page as board PqReports draws it: a catalogue (nextcloud-vue
 * `catalogue`) with "New analysis report" beside the buildiq square, the
 * board's labels, and the "Missing a report?" note in the aside. The SLA
 * attainment card stays (users keep features): the simple profile appends it.
 *
 * @spec openspec/specs/reports-page-as-board/spec.md#requirement-the-reports-page-is-the-boards-catalogue
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const page = readJson('src', 'manifest.json').pages.find((item) => item.id === 'Reports')
const simple = readJson('src', 'menu-layout.simple.json').pages.find((item) => item.id === 'Reports')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

describe('the Reports page as board PqReports', () => {
	it('is a catalogue with New analysis report beside the buildiq square', () => {
		expect(page.config.catalogue).toBe(true)
		expect(page.config.headerButtons).toEqual([{ label: 'New analysis report', action: 'new-analysis-report', variant: 'primary', icon: 'Plus' }])
		expect(page.config.headerActions.find((action) => action.id === 'new-analysis-report').href).toBe('/apps/analytics/')
	})

	it('names the staff report and closes the aside with the note', () => {
		expect(page.config.cards.find((card) => card.id === 'AgentPerformanceView').label).toBe('Staff performance')
		expect(page.config.asideNote).toEqual({
			title: 'Missing a report?',
			text: 'Build one yourself in Nextcloud Analytics with New analysis report, or ask an administrator to add a report to the app.',
		})
	})

	it('keeps the SLA attainment card in the simple profile', () => {
		expect(simple.configAppend.cards.map((card) => card.id)).toContain('SlaAttainment')
	})

	it('has its words in Dutch', () => {
		for (const text of ['New analysis report', 'Staff performance', 'Missing a report?', page.config.asideNote?.text, ...Object.values(page.config.categories)]) {
			expect(en[text], `en "${text}"`).toBeTruthy()
			expect(nl[text], `nl "${text}"`).toBeTruthy()
		}
		expect(nl['Staff performance']).toBe('Prestaties medewerkers')
	})
})
