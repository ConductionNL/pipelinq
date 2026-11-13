// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tender panel of a POS transaction: when it may be edited, how the
 * variance reads, how tender type names are found, and what adding and
 * removing a tender does.
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosGet = vi.hoisted(() => vi.fn())
const axiosDelete = vi.hoisted(() => vi.fn())
const showError = vi.hoisted(() => vi.fn())
const showSuccess = vi.hoisted(() => vi.fn())
vi.mock('@nextcloud/axios', () => ({ default: { get: axiosGet, delete: axiosDelete } }))
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))
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

const TENDERS_URL = '/apps/pipelinq/api/pos-transactions/tx-1/tenders'
const TYPES_URL = '/apps/pipelinq/api/pos/tender-types?activeOnly=1'

/**
 * Answer GETs from a url map; a function value is called, an Error rejects.
 *
 * @param {object} answers url => data | Error
 */
function serve(answers) {
	axiosGet.mockImplementation(async (url) => {
		if (!(url in answers)) {
			throw new Error('unexpected ' + url)
		}
		const answer = answers[url]
		if (answer instanceof Error) {
			throw answer
		}
		return { data: answer }
	})
}

/**
 * Mount the panel and let its loads settle.
 *
 * @param {object} props Props over the defaults.
 * @return {Promise<object>} The wrapper.
 */
async function mountPanel(props = {}) {
	const wrapper = mount(TenderEntryPanel, {
		props: { transactionId: 'tx-1', transactionStatus: 'open', ...props },
		global: { mocks: { t: (_app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	axiosGet.mockReset()
	axiosDelete.mockReset()
	showError.mockReset()
	showSuccess.mockReset()
})

describe('TenderEntryPanel', () => {
	it('may be edited until the transaction is settled or refunded', async () => {
		serve({ [TENDERS_URL]: { results: [] }, [TYPES_URL]: { results: [] } })
		for (const [status, editable] of [['draft', true], ['open', true], ['settled', false], ['refunded', false]]) {
			const wrapper = await mountPanel({ transactionStatus: status })
			expect(wrapper.vm.canEdit).toBe(editable)
		}
	})

	it('reads an under-, over- or balanced payment from the server validation', async () => {
		serve({
			[TENDERS_URL]: { results: [], validation: { tenderSum: 5, transactionTotal: 12, variance: 7, balanced: false } },
			[TYPES_URL]: { results: [] },
		})
		const wrapper = await mountPanel()
		expect(wrapper.vm.remainingAmount).toBe(7)
		expect(wrapper.vm.remainingLabel).toBe('Underpayment')
		expect(wrapper.vm.summaryClass).toBe('tender-panel__summary--warn')

		wrapper.vm.validation = { variance: -3, balanced: false }
		expect(wrapper.vm.remainingAmount).toBe(0)
		expect(wrapper.vm.remainingLabel).toBe('Overpayment')
		expect(wrapper.vm.summaryClass).toBe('tender-panel__summary--error')

		wrapper.vm.validation = { variance: 0, balanced: true }
		expect(wrapper.vm.remainingLabel).toBe('Variance')
		expect(wrapper.vm.summaryClass).toBe('tender-panel__summary--ok')
	})

	it('offers only active tender types', async () => {
		serve({
			[TENDERS_URL]: { results: [] },
			[TYPES_URL]: { results: [{ id: 'a', name: 'Cash' }, { id: 'b', name: 'Old', isActive: false }, { id: 'c', name: 'Pin', isActive: true }] },
		})
		const wrapper = await mountPanel()
		expect(wrapper.vm.activeTenderTypes.map((type) => type.id)).toEqual(['a', 'c'])
	})

	it('names a listed type, looks up an unlisted one once, and reads Unknown when that fails', async () => {
		serve({
			[TENDERS_URL]: {
				results: [
					{ id: 't1', tenderType: 'cash' },
					{ id: 't2', tenderType: 'gift' },
					{ id: 't3', tenderType: 'gift' },
					{ id: 't4', tenderType: 'gone' },
					{ id: 't5' },
				],
			},
			[TYPES_URL]: { results: [{ id: 'cash', name: '', code: 'CASH' }] },
			'/apps/pipelinq/api/pos/tender-types/gift': { code: 'GIFT' },
			'/apps/pipelinq/api/pos/tender-types/gone': new Error('404'),
		})
		const wrapper = await mountPanel()
		const label = (id) => wrapper.vm.tenderTypeLabel(wrapper.vm.tenders.find((tender) => tender.id === id))

		expect(label('t1')).toBe('CASH')
		expect(label('t2')).toBe('GIFT')
		expect(label('t4')).toBe('Unknown')
		expect(label('t5')).toBe('Unknown')
		expect(axiosGet.mock.calls.filter(([url]) => url.endsWith('/gift'))).toHaveLength(1)
		expect(wrapper.vm.unlistedTypeLabel('pending')).toBe('…')
	})

	it('shows the server error, or a default, when the tenders cannot be loaded', async () => {
		serve({ [TENDERS_URL]: Object.assign(new Error('x'), { response: { data: { error: 'Transaction locked' } } }), [TYPES_URL]: new Error('down') })
		const wrapper = await mountPanel()
		expect(wrapper.vm.errorMessage).toBe('Transaction locked')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.vm.tenderTypes).toEqual([])

		serve({ [TENDERS_URL]: new Error('x'), [TYPES_URL]: { results: [] } })
		const other = await mountPanel()
		expect(other.vm.errorMessage).toBe('Failed to load tenders')
	})

	it('reloads the tender types when the add dialog opens without any', async () => {
		serve({ [TENDERS_URL]: { results: [] }, [TYPES_URL]: { results: [] } })
		const wrapper = await mountPanel()
		axiosGet.mockClear()

		wrapper.vm.openAddDialog()
		await flushPromises()

		expect(wrapper.vm.showAdd).toBe(true)
		expect(axiosGet).toHaveBeenCalledWith(TYPES_URL)
	})

	it('closes the dialog, reloads and reports a change once a tender is added', async () => {
		serve({ [TENDERS_URL]: { results: [] }, [TYPES_URL]: { results: [{ id: 'cash', name: 'Cash' }] } })
		const wrapper = await mountPanel()
		wrapper.vm.showAdd = true
		serve({ [TENDERS_URL]: { results: [{ id: 't1', tenderType: 'cash', amount: 5 }] }, [TYPES_URL]: { results: [] } })

		await wrapper.vm.onTenderAdded()

		expect(wrapper.vm.showAdd).toBe(false)
		expect(wrapper.vm.tenders).toHaveLength(1)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('removes a confirmed tender, reloads and reports a change', async () => {
		serve({ [TENDERS_URL]: { results: [{ id: 't1', tenderType: 'cash' }] }, [TYPES_URL]: { results: [] } })
		const wrapper = await mountPanel()
		axiosDelete.mockResolvedValue({})

		wrapper.vm.removeTender({})
		expect(wrapper.vm.pendingRemoveTender).toBeNull()
		wrapper.vm.removeTender({ id: 't1' })
		await wrapper.vm.performRemoveTender()

		expect(axiosDelete).toHaveBeenCalledWith('/apps/pipelinq/api/pos-transactions/tx-1/tenders/t1')
		expect(showSuccess).toHaveBeenCalledWith('Tender removed')
		expect(wrapper.vm.pendingRemoveTender).toBeNull()
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('reports a failed removal and does not report a change', async () => {
		serve({ [TENDERS_URL]: { results: [] }, [TYPES_URL]: { results: [] } })
		const wrapper = await mountPanel()
		axiosDelete.mockRejectedValue(Object.assign(new Error('x'), { response: { data: { error: 'Settled' } } }))

		wrapper.vm.removeTender({ id: 't1' })
		await wrapper.vm.performRemoveTender()
		expect(showError).toHaveBeenCalledWith('Settled')

		axiosDelete.mockRejectedValue(new Error('x'))
		wrapper.vm.removeTender({ id: 't1' })
		await wrapper.vm.performRemoveTender()
		expect(showError).toHaveBeenLastCalledWith('Failed to remove tender')

		await wrapper.vm.performRemoveTender()
		expect(axiosDelete).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('changed')).toBeUndefined()
	})
})
