/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqBiExport's run columns: when ("7 okt, 02:04", the year only outside this
 * year) and the line under the last run's status (rows, files, or why it
 * failed).
 *
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */

import { describe, expect, it } from 'vitest'
import { exportMoment, exportSchedule, lastRunCell, lastRunLine, switchedOffLine } from '../../src/views/export/exportRunText.js'

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

	it('say why a job was switched off, only while it is off', () => {
		const off = { enabled: false, consecutiveFailures: 3, pausedReason: 'Switched off after 3 failed runs in a row' }
		expect(switchedOffLine(off)).toBe('Switched off after 3 failed runs in a row')
		expect(switchedOffLine({ ...off, consecutiveFailures: 1 })).toBe('Switched off after 1 failed run in a row')
		expect(switchedOffLine({ ...off, consecutiveFailures: 'x', pausedReason: ' Stored reason ' })).toBe('Stored reason')
		expect(switchedOffLine({ ...off, consecutiveFailures: 0 })).toBe('Switched off after 3 failed runs in a row')
		expect(switchedOffLine({ ...off, enabled: true })).toBe('')
		expect(switchedOffLine({ ...off, pausedReason: null })).toBe('')
		expect(switchedOffLine({ ...off, pausedReason: '  ' })).toBe('')
		expect(switchedOffLine(null)).toBe('')
	})

	it('put the switch-off reason after the failed run in the last run cell', () => {
		const at = iso(2026, 10, 6, 1, 0)
		const failed = { lastRunStatus: 'failed', lastRunAt: at, lastRunError: 'connection refused' }
		const off = { ...failed, enabled: false, consecutiveFailures: 3, pausedReason: 'Switched off after 3 failed runs in a row' }
		expect(lastRunCell(off, NOW, 'nl')).toBe('6 okt, 01:00 · connection refused · Switched off after 3 failed runs in a row')
		expect(lastRunCell(failed, NOW, 'nl')).toBe('6 okt, 01:00 · connection refused')
		expect(lastRunCell({ ...off, enabled: true, pausedReason: null }, NOW, 'nl')).toBe('6 okt, 01:00 · connection refused')
		expect(lastRunCell({ enabled: false, consecutiveFailures: 2, pausedReason: 'x' }, NOW, 'nl')).toBe('Switched off after 2 failed runs in a row')
		expect(lastRunCell({}, NOW, 'nl')).toBe('')
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

	it('reads every other schedule shape the same way', () => {
		const cases = [null, 5, '   ', ' @HOURLY ', '@weekly', '@monthly', '@yearly', '@nope', '0 0 * *', '0 0 * * * *', '5 * * * *', '0  *  *  *  *', 'x 2 * * *', '0 y * * *', '0 2 * 1 *', '0 6 * * *', '59 5 * * *', '0 3 * * 0', '0 3 * * 7', '0 3 * * 8', '0 3 2 * 1', '0 3 * * x', '0 3 15 * *', '0 3 x * *']
		expect(cases.map((value) => exportSchedule(value, 'en'))).toEqual(['', '', '', 'Every hour', 'Sunday at 00:00', 'On day 1 of every month at 00:00', '@yearly', '@nope', '0 0 * *', '0 0 * * * *', '5 * * * *', 'Every hour', 'x 2 * * *', '0 y * * *', '0 2 * 1 *', 'Every day at 06:00', 'Every night at 05:59', 'Sunday at 03:00', 'Sunday at 03:00', '0 3 * * 8', '0 3 2 * 1', '0 3 * * x', 'On day 15 of every month at 03:00', '0 3 x * *'])
	})
})
