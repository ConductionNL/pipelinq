/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqBiExport's run columns: when ("7 okt, 02:04", the year only outside this
 * year) and the line under the last run's status (rows, files, or why it
 * failed).
 *
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-the-bi-export-page-shows-a-switched-off-job-and-lets-an-administrator-switch-it-back-on-req-efs-003
 */

import { describe, expect, it } from 'vitest'
import { exportMoment, exportSchedule, lastRunLine, switchedOffReason } from '../../src/views/export/exportRunText.js'

const NOW = new Date(2026, 9, 7, 12, 0, 0)
const iso = (y, m, d, h, min) => new Date(y, m - 1, d, h, min).toISOString()

describe('the BI export run texts', () => {
	it('write a moment as day, month and time, the year only outside this year', () => {
		expect(exportMoment(iso(2026, 10, 7, 2, 4), NOW, 'nl')).toBe('7 okt, 02:04')
		expect(exportMoment(iso(2025, 12, 30, 23, 0), NOW, 'nl')).toBe('30 dec 2025, 23:00')
		expect(exportMoment('', NOW, 'nl')).toBe('')
		expect(exportMoment('Not scheduled', NOW, 'nl')).toBe('Not scheduled')
	})

	it('say what the last run did: rows, files, or why it failed', () => {
		const at = iso(2026, 10, 7, 2, 4)
		expect(lastRunLine({ lastRunStatus: 'succeeded', lastRunAt: at, lastRunRows: 12480 }, NOW, 'nl')).toBe('7 okt, 02:04 · 12.480 rows')
		expect(lastRunLine({ lastRunStatus: 'partial', lastRunAt: at, lastRunFiles: 2 }, NOW, 'nl')).toBe('7 okt, 02:04 · 2 files')
		expect(lastRunLine({ lastRunStatus: 'partial', lastRunAt: at, lastRunFiles: 2, lastRunFilesExpected: 3 }, NOW, 'nl')).toBe('7 okt, 02:04 · 2 of 3 files')
		expect(lastRunLine({ lastRunStatus: 'failed', lastRunAt: at, lastRunError: 'verbinding geweigerd' }, NOW, 'nl')).toBe('7 okt, 02:04 · verbinding geweigerd')
		expect(lastRunLine({}, NOW, 'nl')).toBe('')
	})

	it('say why the system switched a job off, under its failed last run', () => {
		const at = iso(2026, 10, 6, 1, 0)
		const off = { enabled: false, lastRunStatus: 'failed', lastRunAt: at, lastRunError: 'connection refused', consecutiveFailures: 3, pausedAt: at }
		expect(switchedOffReason(off)).toBe('Switched off after 3 failed runs in a row')
		expect(lastRunLine(off, NOW, 'nl')).toBe('6 okt, 01:00 · connection refused · Switched off after 3 failed runs in a row')
		expect(switchedOffReason({ ...off, consecutiveFailures: 1 })).toBe('Switched off after 1 failed run')
		expect(switchedOffReason({ ...off, enabled: true })).toBe('')
		expect(switchedOffReason({ ...off, pausedAt: null })).toBe('')
	})

	it('say a schedule in words, and keep anything else as written', () => {
		expect(exportSchedule('@hourly', 'nl')).toBe('Every hour')
		expect(exportSchedule('0 * * * *', 'nl')).toBe('Every hour')
		expect(exportSchedule('0 2 * * *', 'nl')).toBe('Every night at 02:00')
		expect(exportSchedule('@daily', 'nl')).toBe('Every night at 00:00')
		expect(exportSchedule('30 9 * * *', 'nl')).toBe('Every day at 09:30')
		expect(exportSchedule('0 3 * * 1', 'nl')).toBe('Maandag at 03:00')
		expect(exportSchedule('0 4 1 * *', 'nl')).toBe('On day 1 of every month at 04:00')
		expect(exportSchedule('*/15 9-17 * * 1-5', 'nl')).toBe('*/15 9-17 * * 1-5')
		expect(exportSchedule('', 'nl')).toBe('')
	})
})
