/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqBiExport's run columns: when ("7 okt, 02:04", the year only outside this
 * year) and the line under the last run's status (rows, files, or why it
 * failed).
 *
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-a-job-shows-its-last-run-and-its-next-run
 */

import { describe, expect, it } from 'vitest'
import { exportMoment, lastRunLine } from '../../src/views/export/exportRunText.js'

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
		expect(lastRunLine({ lastRunStatus: 'failed', lastRunAt: at, lastRunError: 'verbinding geweigerd' }, NOW, 'nl')).toBe('7 okt, 02:04 · verbinding geweigerd')
		expect(lastRunLine({}, NOW, 'nl')).toBe('')
	})
})
