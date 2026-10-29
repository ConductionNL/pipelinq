/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sales overview as the PqVerkoopoverzicht board draws it.
 *
 * @spec openspec/specs/dashboard/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(
	readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)
const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')

describe('sales overview', () => {
	it('picks the period in one segmented control, 90 days by default', () => {
		expect(dashboard.config.dateRange).toMatchObject({
			enabled: true,
			control: 'segmented',
			showHeaderPicker: true,
			default: { preset: 'quarter' },
		})
	})

	it('shows the six KPI tiles in a row above the grid', () => {
		expect(dashboard.config.kpiRow).toEqual([
			'revenue',
			'won-value',
			'win-rate',
			'avg-deal-size',
			'weighted-forecast',
			'mrr',
		])
		const placed = dashboard.config.layout.map((item) => item.widgetId)
		for (const id of dashboard.config.kpiRow) {
			expect(placed).not.toContain(id)
		}
	})

	it('drops the per-widget date chips and Actions menus', () => {
		expect(dashboard.config.showWidgetActions).toBe(false)
		expect(dashboard.config.layout.some((item) => item.dateChip)).toBe(false)
	})

	it('lists closing and recently closed deals as rows with their value', () => {
		for (const id of ['closing-soon', 'recently-won-lost']) {
			const widget = dashboard.config.widgets.find((w) => w.id === id)
			expect(widget.type).toBe('record-list')
			expect(widget.content.trailing).toEqual(['{value|currency}'])
		}
	})

	it('says won or lost and who closed it in the meta line, not as a raw pill and a user id', () => {
		const widget = dashboard.config.widgets.find((w) => w.id === 'recently-won-lost')
		expect(widget.content.meta).toBe('{status} {expectedCloseDate|date} · {assignee|user}')
		expect(widget.content.valueLabels).toEqual({ status: { won: 'Won', lost: 'Lost' } })
		expect(widget.content.pill).toBeUndefined()
	})
})
