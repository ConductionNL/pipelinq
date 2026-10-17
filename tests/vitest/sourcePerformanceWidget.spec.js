import { mount } from '@vue/test-utils'
// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead source table on the analytics page (LeadAnalyticsSection).
 *
 * The defects this pins: its columns carried `format` FUNCTIONS, which
 * CnDataTable ignores (it takes a format spec object), so the conversion read
 * "33.3" and the deal value "7000"; and the table sat in half a row, 518px,
 * while its five sortable columns need about 825px, so it ran out of its card.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { name: 'NcEmptyContent', render: () => h('div') },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDataTable: {
		name: 'CnDataTable',
		props: ['rows', 'columns'],
		render: () => h('table'),
	},
}))

globalThis.t = (app, text) => text

const { default: SourcePerformanceWidget } =
	await import('../../src/views/rapportage/SourcePerformanceWidget.vue')

describe('the lead source table', () => {
	it('hands the table format specs, not functions', () => {
		const wrapper = mount(SourcePerformanceWidget, {
			props: {
				data: [
					{
						source: 'website',
						total: 6,
						won: 2,
						conversionRate: 33.3,
						avgWonValue: 7000,
					},
				],
			},
		})
		const columns = wrapper
			.findComponent({ name: 'CnDataTable' })
			.props('columns')
		for (const column of columns) {
			expect(typeof column.format, column.key).not.toBe('function')
		}
		expect(columns.find((c) => c.key === 'conversionRate').format).toEqual({
			style: 'percent',
			decimals: 1,
		})
		expect(columns.find((c) => c.key === 'avgWonValue').format).toEqual({
			style: 'currency',
			decimals: 0,
		})
	})

	it('shows a label for the source, not the stored value', () => {
		const wrapper = mount(SourcePerformanceWidget, {
			props: {
				data: [
					{ source: 'cold-call', total: 2 },
					{ source: 'unknown', total: 3 },
					{ source: 'beurs', total: 1 },
				],
			},
		})
		const rows = wrapper.findComponent({ name: 'CnDataTable' }).props('rows')
		expect(rows.map((r) => r.source)).toEqual([
			'Cold call',
			'No source',
			'beurs',
		])
		// The stored value stays on the row.
		expect(rows.map((r) => r.sourceValue)).toEqual([
			'cold-call',
			'unknown',
			'beurs',
		])
	})

	it('takes the whole row on the analytics page', () => {
		const section = readFileSync(
			resolve(
				__dirname,
				'../../src/components/rapportage/LeadAnalyticsSection.vue',
			),
			'utf8',
		)
		expect(section).toMatch(
			/\.lead-analytics__cell--wide\s*\{[^}]*grid-column:\s*1\s*\/\s*-1/,
		)
	})
})
