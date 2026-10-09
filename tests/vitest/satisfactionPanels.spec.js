// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The response-rate widget and the client satisfaction panel
 * (customer-satisfaction-closed-loop, tasks 5.2 and 5.3), and their places in
 * the manifest.
 *
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
vi.mock('@nextcloud/axios', () => ({ default: { get: (...args) => get(...args) } }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (p, params = {}) =>
		'/index.php' + p.replace(/\{(\w+)\}/g, (_m, k) => params[k]),
}))

const { default: SurveyResponseRateWidget } =
	await import('../../src/components/satisfaction/SurveyResponseRateWidget.vue')
const { default: ClientSatisfactionSection } =
	await import('../../src/components/satisfaction/ClientSatisfactionSection.vue')

function t(_app, text, vars = {}) {
	return text.replace(/\{(\w+)\}/g, (_m, k) => String(vars[k]))
}
const global = {
	mocks: { t },
	stubs: {
		NcLoadingIcon: true,
		NcEmptyContent: {
			props: ['name'],
			template: '<div class="empty">{{ name }}</div>',
		},
	},
}
const manifest = JSON.parse(
	fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'),
)

describe('SurveyResponseRateWidget', () => {
	beforeEach(() => get.mockReset())

	it('shows 20% for 10 answered of 50 delivered, with held back and failed beside it', async () => {
		get.mockResolvedValue({
			data: {
				delivered: 50,
				responded: 10,
				rate: 20,
				suppressed: 5,
				failed: 1,
				byChannel: {
					email: {
						delivered: 50,
						responded: 10,
						rate: 20,
						suppressed: 5,
						failed: 1,
					},
				},
			},
		})
		const wrapper = mount(SurveyResponseRateWidget, { global })
		await flushPromises()
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/satisfaction/response-rate',
			{ params: { days: 30 } },
		)
		const headline = wrapper.find('[data-testid="survey-response-rate"]').text()
		expect(headline).toContain('20%')
		expect(headline).toContain('10 of 50 delivered')
		expect(wrapper.text()).toContain('5 held back, 1 failed')
		expect(wrapper.findAll('tbody tr').length).toBe(1)
	})

	it('reads all time when the period is set to all time', async () => {
		get.mockResolvedValue({ data: {} })
		const wrapper = mount(SurveyResponseRateWidget, { global })
		await flushPromises()
		await wrapper.find('select').setValue('0')
		await flushPromises()
		expect(get.mock.calls[1][1]).toEqual({ params: {} })
		expect(wrapper.text()).toContain('No survey invitations yet')
	})

	it('sits on the Operational dashboard', () => {
		const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
		expect(page.slots['widget-survey-response-rate']).toBe(
			'SurveyResponseRateWidget',
		)
		expect(
			page.config.widgets.some((w) => w.id === 'survey-response-rate'),
		).toBe(true)
		expect(
			page.config.layout.some((l) => l.widgetId === 'survey-response-rate'),
		).toBe(true)
	})
})

describe('ClientSatisfactionSection', () => {
	beforeEach(() => get.mockReset())

	it('shows NPS, count, trend and at most three comments', async () => {
		get.mockResolvedValue({
			data: {
				empty: false,
				responseCount: 6,
				nps: 33.3,
				averageRating: 4.2,
				trend: 'up',
				verbatims: [
					{ text: 'a' },
					{ text: 'b' },
					{ text: 'c' },
					{ text: 'd' },
				],
			},
		})
		const wrapper = mount(ClientSatisfactionSection, {
			props: { clientId: 'c-1' },
			global,
		})
		await flushPromises()
		expect(get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/satisfaction/client/c-1',
		)
		expect(wrapper.find('[data-testid="client-satisfaction-nps"]').text()).toBe(
			(33.3).toLocaleString(undefined, { maximumFractionDigits: 1 }),
		)
		expect(wrapper.text()).toContain('6')
		expect(wrapper.text()).toContain('Better than the 90 days before')
		expect(wrapper.findAll('li').length).toBe(3)
	})

	it('explains the empty state for a client nobody surveyed', async () => {
		get.mockResolvedValue({
			data: { empty: true, responseCount: 0, nps: null, verbatims: [] },
		})
		const wrapper = mount(ClientSatisfactionSection, {
			props: { clientId: 'c-2' },
			global,
		})
		await flushPromises()
		expect(
			wrapper.find('[data-testid="client-satisfaction-empty"]').text(),
		).toContain('No satisfaction data has been collected')
	})

	it('sits on the client page', () => {
		const page = manifest.pages.find((p) => p.id === 'ClientDetail')
		const widget = page.config.bodyWidgets.find(
			(w) => w.component === 'ClientSatisfactionSection',
		)
		expect(widget.props.clientId).toBe('@objectId')
	})
})
