/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * 'Leads over time' as the PqOperationeel board draws it: the count on each
 * bar, short month names, and no value axis or grid. Read from the manifest.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
const chart = page.config.widgets.find((w) => w.id === 'leads-over-time').content

describe('leads over time', () => {
	it('labels each bar and reads short months without a value axis', () => {
		expect(chart.chartKind).toBe('bar')
		expect(chart.categoryFormat).toBe('month')
		expect(chart.options.dataLabels.enabled).toBe(true)
		expect(chart.options.plotOptions.bar.dataLabels.position).toBe('top')
		expect(chart.options.yaxis.show).toBe(false)
		expect(chart.options.grid.show).toBe(false)
	})
})
