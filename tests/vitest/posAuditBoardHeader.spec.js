/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassaAudit: the audit log's only header button is the tax
 * authority export (no Download, Actions or Add from the index defaults),
 * and the Time column writes "7 okt, 12:05".
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { momentInYear } from '../../src/services/cellFormatters.js'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const base = read('src/manifest.json').pages.find((p) => p.id === 'KassakoppelingAuditList').config
const overlay = read('src/menu-layout.simple.json').pages.find((p) => p.id === 'KassakoppelingAuditList')

describe('cash register audit log header', () => {
	it('has the tax authority export as its only header button', () => {
		expect(overlay.config.headerButtons).toEqual([
			{ action: 'audit-export', icon: 'Download', label: 'Export for the tax authority' },
		])
		expect(base.headerActions.map((a) => a.id)).toContain('audit-export')
	})

	it('writes the time as day, short month and time', () => {
		const time = base.columns.find((c) => c.key === 'timestamp')
		expect(time.formatter).toBe('momentInYear')
		expect(time.link).toBe(true)
		const now = new Date('2026-10-28T12:00:00')
		expect(momentInYear('2026-10-07T12:05:00', {}, {}, { now, locale: 'nl-NL' })).toBe('7 okt, 12:05')
		expect(momentInYear('2025-10-07T12:05:00', {}, {}, { now, locale: 'nl-NL' })).toBe('7 okt 2025, 12:05')
		expect(momentInYear('', {}, {}, { now })).toBe('')
	})
})
