// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline funnel and lead aging widgets on the reporting page: what each
 * hands its chart, when each shows its empty state instead, and which
 * pipeline the funnel reports when one is picked or cleared.
 *
 * @spec openspec/specs/rapportage/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnChartWidget: { name: 'CnChartWidget', props: ['type', 'title', 'categories', 'labels', 'series'], render: () => h('div', { class: 'chart' }) },
}))
vi.mock('@nextcloud/vue', () => ({
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name', 'description'], render() { return h('div', { class: 'empty' }, this.name) } },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], render: () => h('div', { class: 'select' }) },
}))

globalThis.t = (_app, text) => text

const { default: PipelineFunnelWidget } = await import('../../src/views/rapportage/PipelineFunnelWidget.vue')
const { default: LeadAgingWidget } = await import('../../src/views/rapportage/LeadAgingWidget.vue')

const options = { global: { mocks: { t: globalThis.t } } }

describe('the pipeline funnel widget', () => {
	it('charts the total and weighted value per stage, rounded, with a missing value as zero', () => {
		const wrapper = mount(PipelineFunnelWidget, {
			...options,
			props: {
				data: [
					{ stage: 'New', totalValue: 1000.4, weightedValue: 100.5 },
					{ stage: 'Won', totalValue: 2500.6 },
				],
			},
		})
		const chart = wrapper.findComponent({ name: 'CnChartWidget' })

		expect(wrapper.find('.empty').exists()).toBe(false)
		expect(chart.props('type')).toBe('bar')
		expect(chart.props('title')).toBe('Pipeline value per stage')
		expect(chart.props('categories')).toEqual(['New', 'Won'])
		expect(chart.props('series')).toEqual([
			{ name: 'Total value', data: [1000, 2501] },
			{ name: 'Weighted value', data: [101, 0] },
		])
	})

	it.each([[[]], [null], [{ stage: 'not a list' }]])('shows the empty state for %j', (data) => {
		const wrapper = mount(PipelineFunnelWidget, { ...options, props: { data } })

		expect(wrapper.find('.empty').text()).toBe('No pipeline data')
		expect(wrapper.findComponent({ name: 'CnChartWidget' }).exists()).toBe(false)
	})

	it('reports the picked pipeline\'s value, and null once the pick is cleared', async () => {
		const pipelines = [{ label: 'Sales', value: 'p-sales' }]
		const wrapper = mount(PipelineFunnelWidget, { ...options, props: { pipelineOptions: pipelines } })
		const select = wrapper.findComponent({ name: 'NcSelect' })

		expect(select.props('options')).toEqual(pipelines)
		await select.vm.$emit('update:modelValue', pipelines[0])
		await select.vm.$emit('update:modelValue', null)

		expect(wrapper.emitted('pipelineChange')).toEqual([['p-sales'], [null]])
	})
})

describe('the lead aging widget', () => {
	it('charts the count per bucket, labelled with the bucket and its euro total when there is one', () => {
		const wrapper = mount(LeadAgingWidget, {
			...options,
			props: {
				data: [
					{ bucket: '0-30 days', count: 4, totalValue: 12500 },
					{ bucket: '31-60 days', count: 0, totalValue: 0 },
					{ bucket: '60+ days', count: 2 },
				],
			},
		})
		const chart = wrapper.findComponent({ name: 'CnChartWidget' })

		expect(chart.props('type')).toBe('donut')
		expect(chart.props('title')).toBe('Lead aging distribution')
		expect(chart.props('labels')).toEqual([
			`0-30 days: 4 (EUR ${(12500).toLocaleString('nl-NL')})`,
			'31-60 days: 0',
			'60+ days: 2',
		])
		expect(chart.props('series')).toEqual([4, 0, 2])
	})

	it('shows the empty state when no bucket holds a lead', () => {
		const wrapper = mount(LeadAgingWidget, {
			...options,
			props: { data: [{ bucket: '0-30 days', count: 0 }, { bucket: '60+ days' }] },
		})

		expect(wrapper.find('.empty').text()).toBe('No aging data')
		expect(wrapper.findComponent({ name: 'CnChartWidget' }).exists()).toBe(false)
	})

	it('shows the empty state for data that is not a list', () => {
		const wrapper = mount(LeadAgingWidget, { ...options, props: { data: null } })

		expect(wrapper.find('.empty').text()).toBe('No aging data')
	})
})
