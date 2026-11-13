// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead probability cell shows a rounded percentage, a dash for a missing
 * or non-numeric value, and a "Low" badge (icon plus text) under 30%.
 *
 * @spec openspec/specs/lead-scoring-win-probability/spec.md#requirement-win-probability-is-surfaced-on-the-pipeline-list-and-deal-detail
 */

import { mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it } from 'vitest'

let LeadProbabilityCell

beforeAll(async () => {
	globalThis.t = (app, text) => text
	LeadProbabilityCell = (await import('../../src/views/leads/cells/LeadProbabilityCell.vue')).default
})

/**
 * Mount the cell with a value.
 *
 * @param {*} value The raw cell value.
 * @return {object} The wrapper.
 */
function render(value) {
	return mount(LeadProbabilityCell, {
		props: { value },
		global: { mocks: { t: globalThis.t } },
	})
}

describe('LeadProbabilityCell', () => {
	it.each([null, undefined, '', 'abc'])('shows a dash for %j', (value) => {
		const wrapper = render(value)
		expect(wrapper.find('.lead-prob-cell__dash').text()).toBe('—')
		expect(wrapper.find('.lead-prob-cell__value').exists()).toBe(false)
		expect(wrapper.find('.lead-prob-cell__badge').exists()).toBe(false)
	})

	it('rounds the value and shows no badge from 30 up', () => {
		const wrapper = render(64.6)
		expect(wrapper.find('.lead-prob-cell__value').text()).toBe('65%')
		expect(wrapper.find('.lead-prob-cell__badge').exists()).toBe(false)
		expect(render('30').find('.lead-prob-cell__badge').exists()).toBe(false)
		expect(render(29.5).find('.lead-prob-cell__badge').exists()).toBe(false)
	})

	it('adds a labelled Low badge with an icon under 30', () => {
		const wrapper = render('12')
		expect(wrapper.find('.lead-prob-cell__value').text()).toBe('12%')
		const badge = wrapper.find('.lead-prob-cell__badge')
		expect(badge.attributes('aria-label')).toBe('Low win chance')
		expect(badge.find('.lead-prob-cell__badge-label').text()).toBe('Low')
		expect(badge.find('.lead-prob-cell__badge-icon').exists()).toBe(true)
	})

	it('treats zero as a low value, not an empty one', () => {
		const wrapper = render(0)
		expect(wrapper.find('.lead-prob-cell__dash').exists()).toBe(false)
		expect(wrapper.find('.lead-prob-cell__value').text()).toBe('0%')
		expect(wrapper.find('.lead-prob-cell__badge').exists()).toBe(true)
	})
})
