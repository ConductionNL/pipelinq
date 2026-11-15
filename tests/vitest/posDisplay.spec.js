// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * POS and product screens speak the user's language and show names, not
 * uuids (pipelinq-audit-admin-forms-pos, B4 and the POS extras).
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/pos-display/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import en from '../../l10n/en.json'
import manifest from '../../src/manifest.json'

const TYPE_ID = 'b1a0e0c0-0001-4001-a001-000000000001'

const axiosGet = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		path.replace(/\{(\w+)\}/g, (_m, key) => params[key]),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		render() {
			return h('button', this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: { name: 'ConfirmDialog', render: () => null },
}))
vi.mock('../../src/modals/AddTenderDialog.vue', () => ({
	default: { name: 'AddTenderDialog', render: () => null },
}))

globalThis.t = (_app, text) => text

const { default: TenderEntryPanel } =
	await import('../../src/components/pos/TenderEntryPanel.vue')

function page(id) {
	return manifest.pages.find((p) => p.id === id)
}

describe('product detail', () => {
	it('shows prices in a currency', () => {
		const data = page('ProductDetail').config.widgets.find(
			(w) => w.id === 'product-data',
		)
		expect(data.content.overrides.unitPrice.formatter).toBe('objectCurrency')
		expect(data.content.overrides.cost.formatter).toBe('objectCurrency')
	})

	it('names the lead on the Used on deals table', () => {
		const deals = page('ProductDetail').config.widgets.find(
			(w) => w.title === 'Used on deals',
		)
		const lead = deals.content.columns.find((c) => c.key === 'lead')
		expect(lead.widget).toBe('fkResolve')
		expect(lead.widgetProps.schema).toBe('lead')
	})
})

describe('POS labels in English', () => {
	it.each([
		['Kassabon', 'Receipts'],
		['Kassakoppeling audit', 'Cash register audit'],
		['Transactie', 'Transaction'],
		['Retouren', 'Returns'],
		['Kassalade', 'Cash drawer'],
	])('translates %s', (key, english) => {
		expect(en.translations[key]).toBe(english)
	})
})

describe('tender type on the POS transaction', () => {
	it('shows the tender type name, never its uuid', async () => {
		axiosGet.mockImplementation(async (url) => {
			if (url.endsWith('/tenders')) {
				return {
					data: {
						results: [
							{ id: 'tender-1', tenderType: TYPE_ID, amount: 10 },
						],
					},
				}
			}
			if (url.includes('activeOnly')) {
				return { data: { results: [] } }
			}
			if (url.endsWith(TYPE_ID)) {
				return { data: { id: TYPE_ID, name: 'Cash' } }
			}
			throw new Error('unexpected ' + url)
		})
		const wrapper = mount(TenderEntryPanel, {
			props: { transactionId: 'tx-1', transactionStatus: 'open' },
			global: { mocks: { t: (_app, text) => text } },
		})
		await flushPromises()
		expect(wrapper.text()).toContain('Cash')
		expect(wrapper.text()).not.toContain(TYPE_ID)
	})
})
