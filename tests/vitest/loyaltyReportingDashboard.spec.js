// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The loyalty reporting page as board PqLoyaliteit draws it: a dashboard with
 * a programme filter read from the programmes, a period filter, eight KPI
 * tiles in rows of four, the tier card with its CSV download and the
 * enrolment card, whose create waits for the customer's opt-in.
 *
 * @spec exclude This app has no canonical loyalty spec: the loyalty-program
 *  change is archived and no spec inherited it (`ls openspec/specs/ | grep -i loyal`).
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h, ref } from 'vue'
import fragment from '../../src/manifest.d/70-loyalty-program.json'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormWidgetBase: {
		name: 'CnFormWidgetBase',
		props: ['fields', 'model', 'canSubmit'],
		render() {
			return h('div', this.fields.map((field) => (this.$slots[`field-${field.key}`] ? this.$slots[`field-${field.key}`]() : h('span'))).concat(this.$slots.actions ? this.$slots.actions() : []))
		},
	},
	CnResourceSelect: { name: 'CnResourceSelect', render: () => h('div') },
}))

const { default: LoyaltyEnrollmentWidget } = await import('../../src/components/loyalty/LoyaltyEnrollmentWidget.vue')

const page = fragment.pages.find((p) => p.id === 'LoyaltyReporting')
const config = page.config
const widget = (id) => config.widgets.find((w) => w.id === id)

describe('the loyalty reporting page', () => {
	it('is a dashboard with a programme filter from the programmes and a period filter', () => {
		expect(page.type).toBe('dashboard')
		expect(config.pageFilters.map((f) => f.key)).toEqual(['programme', 'period'])
		expect(config.pageFilters[0].optionsFrom).toEqual({ register: 'pipelinq', schema: 'loyaltyProgramme', labelField: 'name' })
		expect(config.pageFilters[1].control).toBe('segmented')
		expect(config.pageFilters[1].options.map((o) => o.value)).toEqual(['30', '90', '365'])
		expect(config.pageFilters[1].default).toBe('90')
	})

	it('draws the eight board KPIs in rows of four from the kpis endpoint over the chosen period', () => {
		expect(config.kpiColumns).toBe(4)
		expect(config.kpiRow.map((id) => widget(id).content.valueField)).toEqual([
			'activeAccounts', 'pointsIssued', 'pointsRedeemed', 'pointsExpired',
			'breakagePercent', 'redemptionRate', 'outstandingPoints', 'estimatedLiability',
		])
		for (const id of config.kpiRow) {
			expect(widget(id).content.endpointSource).toEqual({
				url: '/apps/pipelinq/api/loyalty/reporting/@workspace.programme/kpis',
				params: { days: '@workspace.period' },
			})
		}
	})

	it('downloads the KPIs and the tiers as CSV', () => {
		expect(config.headerActions[0]).toMatchObject({ type: 'api-call', download: true, params: { format: 'csv' } })
		const tierAction = widget('loyalty-tiers').headerActions[0]
		expect(tierAction.variant).toBe('tonal')
		expect(tierAction.action).toMatchObject({ type: 'api-call', url: '/apps/pipelinq/api/loyalty/reporting/@workspace.programme/tiers', params: { format: 'csv' }, download: true })
	})

	it('puts the tier bars and the tier table in one card beside the enrolment card', () => {
		expect(widget('loyalty-tiers').content.sections.map((s) => s.widgetId)).toEqual(['loyalty-tier-bars', 'loyalty-tier-table'])
		expect(widget('loyalty-tier-bars').content.chartKind).toBe('bar-list')
		expect(config.layout.map((l) => [l.widgetId, l.gridX, l.gridWidth])).toEqual([['loyalty-tiers', 0, 8], ['loyalty-enrollment', 8, 4]])
		expect(widget('loyalty-enrollment').type).toBe('LoyaltyEnrollmentWidget')
	})
})

describe('the enrolment card', () => {
	const programmes = { data: { results: [
		{ '@self': { id: 'p-1' }, name: 'Zuiddrecht Spaarpas', termsUrl: 'https://example.org/voorwaarden' },
		{ '@self': { id: 'p-2' }, name: 'Tweede programma' },
	] } }

	function mountWidget(workspace = {}) {
		axiosMock.get.mockResolvedValue(programmes)
		axiosMock.post.mockReset()
		axiosMock.post.mockResolvedValue({ data: {} })
		return mount(LoyaltyEnrollmentWidget, {
			props: { content: { termsVersion: '2026-1' } },
			global: { provide: { cnWorkspaceContext: ref(workspace) } },
		})
	}

	it('follows the page programme and links its terms', async () => {
		const wrapper = mountWidget({ programme: 'p-1' })
		await flushPromises()
		expect(wrapper.vm.form.programmeId).toBe('p-1')
		expect(wrapper.vm.form.optInTermsVersion).toBe('2026-1')
		expect(wrapper.find('[data-testid="loyalty-enrollment-terms"]').attributes('href')).toBe('https://example.org/voorwaarden')
	})

	it('creates nothing without the opt-in', async () => {
		const wrapper = mountWidget({ programme: 'p-1' })
		await flushPromises()
		wrapper.vm.onFieldUpdate({ key: 'customerId', value: 'c-1' })
		await wrapper.vm.enroll()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('creates the account with the opt-in, its time and the terms version', async () => {
		const wrapper = mountWidget({ programme: 'p-2' })
		await flushPromises()
		wrapper.vm.onFieldUpdate({ key: 'customerId', value: 'c-1' })
		wrapper.vm.onFieldUpdate({ key: 'optInAccepted', value: true })
		await wrapper.vm.enroll()
		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/openregister/api/objects/pipelinq/customerLoyaltyAccount')
		expect(body).toMatchObject({ customerId: 'c-1', programmeId: 'p-2', optInAccepted: true, optInTermsVersion: '2026-1', status: 'active' })
		expect(typeof body.optInTimestamp).toBe('string')
		expect(wrapper.vm.form.optInAccepted).toBe(false)
	})
})
