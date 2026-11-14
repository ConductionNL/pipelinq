// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The two chart widgets on the lead analytics page: the aging donut and the
 * pipeline value bar chart. What they hand the chart is the whole of their
 * logic, so that is what this pins: bucket labels with the value suffix only
 * when there is value, zero counts that keep the empty state, and the two
 * rounded series per stage plus the pipeline filter event.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name', 'description'],
		render() {
			return h('div', { class: 'empty' }, this.name)
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render: () => h('div', { class: 'select' }),
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnChartWidget: {
		name: 'CnChartWidget',
		props: ['type', 'title', 'labels', 'series', 'categories'],
		render: () => h('div', { class: 'chart' }),
	},
}))

globalThis.t = (app, text) => text
const global = { mocks: { t: globalThis.t } }

const { default: LeadAgingWidget } =
	await import('../../src/views/rapportage/LeadAgingWidget.vue')
const { default: PipelineFunnelWidget } =
	await import('../../src/views/rapportage/PipelineFunnelWidget.vue')

describe('the lead aging donut', () => {
	it('labels each bucket with its count and only shows value when there is some', () => {
		const wrapper = mount(LeadAgingWidget, {
			global,
			props: {
				data: [
					{ bucket: '0-7 days', count: 3, totalValue: 12500 },
					{ bucket: '8-30 days', count: 2, totalValue: 0 },
					{ bucket: '31-90 days', count: 0 },
				],
			},
		})
		const chart = wrapper.findComponent({ name: 'CnChartWidget' })
		expect(chart.exists()).toBe(true)
		expect(chart.props('type')).toBe('donut')
		expect(chart.props('labels')).toEqual([
			`0-7 days: 3 (EUR ${(12500).toLocaleString('nl-NL')})`,
			'8-30 days: 2',
			'31-90 days: 0',
		])
		expect(chart.props('series')).toEqual([3, 2, 0])
	})

	it('shows the empty state when every bucket is zero', () => {
		const wrapper = mount(LeadAgingWidget, {
			global,
			props: { data: [{ bucket: 'old', count: 0 }, { bucket: 'new' }] },
		})
		expect(wrapper.findComponent({ name: 'CnChartWidget' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe(
			'No aging data',
		)
	})

	it('treats a non-array payload as no data', () => {
		const wrapper = mount(LeadAgingWidget, { global, props: { data: null } })
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
	})
})

describe('the pipeline value bar chart', () => {
	it('hands one category per stage and rounded total and weighted series', () => {
		const wrapper = mount(PipelineFunnelWidget, {
			global,
			props: {
				data: [
					{ stage: 'New', totalValue: 1000.4, weightedValue: 100.6 },
					{ stage: 'Won', totalValue: 2500 },
				],
			},
		})
		const chart = wrapper.findComponent({ name: 'CnChartWidget' })
		expect(chart.props('type')).toBe('bar')
		expect(chart.props('categories')).toEqual(['New', 'Won'])
		expect(chart.props('series')).toEqual([
			{ name: 'Total value', data: [1000, 2500] },
			{ name: 'Weighted value', data: [101, 0] },
		])
	})

	it('shows the empty state without stages', () => {
		const wrapper = mount(PipelineFunnelWidget, {
			global,
			props: { data: 'nope' },
		})
		expect(wrapper.findComponent({ name: 'CnChartWidget' }).exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).props('name')).toBe(
			'No pipeline data',
		)
	})

	it('emits the chosen pipeline id, and null when cleared', async () => {
		const options = [{ value: 'p-1', label: 'Sales' }]
		const wrapper = mount(PipelineFunnelWidget, {
			global,
			props: { data: [], pipelineOptions: options },
		})
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('options')).toEqual(options)

		await select.vm.$emit('update:modelValue', options[0])
		await select.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('pipelineChange')).toEqual([['p-1'], [null]])
	})
})
