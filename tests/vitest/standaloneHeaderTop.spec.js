/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqWeekoverzicht, PqZoekinzicht and PqForecast draw the page title at 96,
 * 28px under the content top, as the Dq page headers stand (Dq wins); the
 * three views started their header 24px down, so the title read 92.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-a-custom-views-page-title-starts-28px-under-the-content-top
 */
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it } from 'vitest'

const views = {
	'.weekly-review': 'src/views/marketing/WeeklyReview.vue',
	'.search-queries': 'src/views/marketing/SearchQueries.vue',
	'.forecast-dashboard': 'src/views/forecast/ForecastDashboard.vue',
}

describe('a custom view\'s page header top', () => {
	it.each(Object.entries(views))('%s starts its header 28px down, 28px in', (selector, file) => {
		const source = readFileSync(resolve(__dirname, '../..', file), 'utf8')
		const style = source.slice(source.indexOf('<style'))
		const at = style.indexOf(`${selector} {`)
		const rule = style.slice(at, style.indexOf('}', at))
		expect(rule).toContain('padding: 28px 28px 24px')
	})
})
