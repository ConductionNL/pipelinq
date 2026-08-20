/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioral e2e coverage for the commercial-dashboard split
 * (openspec/changes/commercial-dashboard). The landing dashboard `/` is
 * the Commercial overview (revenue / pipeline / win KPIs + sales charts +
 * deal tables); the previous operational widgets live on the Operational
 * overview reachable from the nav.
 */

import { test, expect } from '@playwright/test'
import { openApp, trackPipelinqErrors, assertNoHardError } from '../helpers/pipelinq'

// @e2e commercial-dashboard::commercial-dashboard-renders-kpis-and-charts
test('Commercial dashboard: KPI strip + sales charts render on the landing page', async ({
	page,
}) => {
	const errs = trackPipelinqErrors(page)
	await openApp(page)

	const content = page.locator('#content-vue')

	// The six commercial KPI cards.
	await expect(content.getByText('Revenue', { exact: true }).first()).toBeVisible({
		timeout: 15000,
	})
	await expect(content.getByText('Won Value').first()).toBeVisible()
	await expect(content.getByText('Win Rate').first()).toBeVisible()
	await expect(content.getByText('Avg Deal Size').first()).toBeVisible()
	await expect(content.getByText('Weighted Forecast').first()).toBeVisible()
	await expect(content.getByText('Open Pipeline').first()).toBeVisible()

	// The commercial chart + table widget chrome titles.
	await expect(content.getByText('Revenue over time').first()).toBeVisible()
	await expect(content.getByText('Pipeline by stage').first()).toBeVisible()
	await expect(content.getByText('Top customers by revenue').first()).toBeVisible()
	await expect(content.getByText('Deals closing soon').first()).toBeVisible()

	// At least one ApexCharts svg has mounted from a commercial chart.
	await expect(content.locator('svg.apexcharts-svg').first()).toBeVisible({
		timeout: 15000,
	})

	await assertNoHardError(page)
	expect(errs(), `pipelinq console errors: ${errs().join(' || ')}`).toEqual([])
})

// @e2e commercial-dashboard::operational-widgets-reachable-after-the-split
test('Operational dashboard: previous widgets remain reachable from the nav', async ({
	page,
}) => {
	await openApp(page)

	// Deep-link the OperationalDashboard via the SPA hash (`/operational`); a
	// path-form goto boots the shell at the default Commercial dashboard.
	await page.goto('/apps/pipelinq/#/operational')
	await page.reload()

	const content = page.locator('#content-vue')
	// Operational KPIs/panels that used to live on the old Dashboard.
	await expect(content.getByText('Lead Conversion Rate').first()).toBeVisible({
		timeout: 15000,
	})
	await expect(content.getByText('Avg Request Resolution').first()).toBeVisible()
	await expect(content.getByText('Open Requests').first()).toBeVisible()
	await expect(content.getByText('Requests by Status').first()).toBeVisible()

	// NOTE 2026-08-06 — 'Contact Moment Volume' was tried here and is NOT
	// assertable by that string. Every stat tile on this dashboard carries
	// `showTitle: false` in its layout slot, so the manifest `title` is never
	// painted; what renders is `content.label`. For six of the seven tiles the
	// two strings are identical, which is why asserting titles worked at all —
	// but `contact-volume` is `title: "Contact Moment Volume"` with
	// `label: "Contacts"`, and "Contacts" is far too generic to assert on a page
	// that also carries a Client Overview table. 'Open Requests' is one of the
	// operational tiles this test exists to protect and its label is unambiguous.

	// CORRECTED 2026-08-06. This test asserted that a "Customer Satisfaction"
	// widget was VISIBLE here. The canonical spec says the opposite:
	// openspec/specs/dashboard/spec.md — "THEN no Customer Satisfaction widget
	// MUST be present". The SatisfactionKpiWidget (widget id `satisfaction`,
	// layout slot 17) was removed in 2026-07 because AnalyticsService hardcodes
	// an empty survey-response set after the forms-leaf migration, so the tile
	// was permanently null for every install; it returns with real CSAT data via
	// the `customer-satisfaction-closed-loop` change. Asserting its presence
	// could only ever fail — and would have kept failing after a correct fix.
	// Assert the shipped decision instead, so re-adding a permanently-empty tile
	// is caught here.
	await expect(content.getByText('Customer Satisfaction')).toHaveCount(0)

	await assertNoHardError(page)
})

/*
 * "Charts sit directly below the KPI rows" is a LAYOUT claim, and the only
 * honest way to test a layout claim is geometry. The manifest places the KPI
 * tiles at gridY 0 and 2 and the two commercial charts at gridY 4
 * (src/manifest.json), i.e. the charts start on the row immediately after the
 * second KPI row with no empty row between them.
 *
 * Asserting the manifest JSON would test the JSON, not the render — the same
 * mistake as asserting a data widget's title, which is host chrome. So this
 * measures the rendered boxes: the vertical gap between the bottom of the
 * lowest KPI tile and the top of the first chart must be smaller than one grid
 * row. A regression that pushes the charts down by a row (the shape the spec
 * was written against, and the shape a layout edit reintroduces) makes that gap
 * exceed a row height and fails here.
 */
// @e2e commercial-dashboard::charts-sit-directly-below-the-kpi-rows
test('Commercial dashboard: the charts start on the row below the KPI tiles', async ({
	page,
}) => {
	await openApp(page)

	const content = page.locator('#content-vue')

	// Measure the WIDGET boxes, not text. The renderer wraps each manifest
	// widget in a role="group" whose accessible name is the widget id, so these
	// are the same handles the manifest declares. An earlier version of this
	// test located `getByText('Open Pipeline')` and measured a label span
	// instead of its tile — it failed at 286px against a 64px "row" that was
	// really the text line box, i.e. it measured the wrong two things and would
	// have been "fixed" by loosening the threshold.
	const lastKpi = content.getByRole('group', { name: 'avg-deal-size' })
	const firstChart = content.getByRole('group', { name: 'revenue-over-time' })
	await expect(lastKpi).toBeVisible({ timeout: 15000 })
	await expect(firstChart).toBeVisible({ timeout: 15000 })
	// The chart's own canvas has to have painted, or the group box is a
	// skeleton and the geometry below describes the loading state.
	await expect(firstChart.locator('svg.apexcharts-svg').first()).toBeVisible({
		timeout: 15000,
	})

	const kpiBox = await lastKpi.boundingBox()
	const chartBox = await firstChart.boundingBox()
	expect(kpiBox, 'the avg-deal-size KPI tile has no bounding box').not.toBeNull()
	expect(
		chartBox,
		'the revenue-over-time chart has no bounding box',
	).not.toBeNull()

	// Below, not beside — otherwise the gap arithmetic would be measuring two
	// columns and would pass for the wrong reason.
	expect(chartBox.y, 'the chart is not below the KPI band').toBeGreaterThan(
		kpiBox.y,
	)

	// `avg-deal-size` is declared `gridY: 2, gridHeight: 2` and
	// `revenue-over-time` is `gridY: 4`, so the chart begins on the row
	// immediately after the KPI band and the gap should be one gutter. Half the
	// KPI tile's rendered height is exactly one grid row, which makes the
	// threshold self-calibrating: an inserted empty row adds a full row and
	// fails, a theme with different row heights does not.
	const oneGridRow = kpiBox.height / 2
	const gap = chartBox.y - (kpiBox.y + kpiBox.height)
	expect(
		gap,
		`gap between the last KPI row and the first chart is ${Math.round(gap)}px; one grid row is ${Math.round(oneGridRow)}px — an empty row has appeared between them`,
	).toBeLessThan(oneGridRow)

	await assertNoHardError(page)
})
