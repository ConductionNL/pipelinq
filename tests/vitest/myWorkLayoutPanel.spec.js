// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

/**
 * Arrange My work (REQ-MW-160, board PqMijnWerkIndelen): the page offers the
 * library's layout panel, where the user hides and reorders the sections, and
 * the work list itself is locked, so it can be moved but never hidden. Back to
 * default returns to the manifest layout (the board PqMijnWerk order).
 *
 * @spec openspec/changes/my-work-roadmap/specs/my-work/spec.md#requirement-customisable-layout-req-mw-160
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'MyWork')
const config = page.config
const layoutIds = config.layout.map((item) => item.widgetId)

const WORK_LIST = ['my-answer-today', 'my-waiting-on-me', 'my-waiting-on-customer', 'my-leads', 'my-follow-ups', 'my-items-phone']
const OPTIONAL = ['my-week', 'my-saved-views', 'my-meetings', 'my-activity']

describe('My work: arrange the page', () => {
	it('offers the layout panel on the page header', () => {
		expect(page.type).toBe('dashboard')
		expect(config.layoutPanel).toBe(true)
	})

	it('locks every list of the work list, each a section of the layout', () => {
		expect([...config.layoutPanelLocked].sort()).toEqual([...WORK_LIST].sort())
		for (const id of config.layoutPanelLocked) {
			expect(layoutIds).toContain(id)
			expect(config.widgets.find((w) => w.id === id)?.type).toBe('record-list')
		}
	})

	it('lets the user hide the sections around the work list', () => {
		for (const id of OPTIONAL) {
			expect(layoutIds).toContain(id)
			expect(config.layoutPanelLocked).not.toContain(id)
		}
	})

	it('does not list the lens bar of the KPI row as a section to lock', () => {
		for (const id of config.kpiRow) {
			expect(config.layoutPanelLocked).not.toContain(id)
		}
	})
})
