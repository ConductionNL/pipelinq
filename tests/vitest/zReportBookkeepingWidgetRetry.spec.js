// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqZRapport's Boekhouding card: passing the day on to shillinq again —
 * the confirm step, the record the endpoint returns, a refusal, and the card
 * going back to the page's record when the page re-reads it.
 *
 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
 */
import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button class="nc-button"><slot name="icon" /><slot /></button>' },
	NcNoteCard: { props: ['type'], template: '<div class="nc-note-card"><slot /></div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnWidgetWrapper: { props: ['title'], template: '<section class="cn-widget"><slot /></section>' },
	CnIcon: { template: '<i />' },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: {
		name: 'ConfirmDialog',
		props: ['name', 'message', 'confirmLabel', 'variant'],
		emits: ['confirm', 'cancel'],
		template: '<div class="confirm-stub" />',
	},
}))
vi.mock('../../src/services/posBookkeepingApi.js', () => ({ raiseJournalEntry: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

globalThis.t = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
window.OC = { isUserAdmin: () => true }

const { raiseJournalEntry } = await import('../../src/services/posBookkeepingApi.js')
const { showError, showSuccess } = await import('@nextcloud/dialogs')
const { default: ZReportBookkeepingWidget } = await import('../../src/components/pos/ZReportBookkeepingWidget.vue')

const report = { id: 'z1', bookkeepingStatus: 'failed', transactionCount: 29 }
const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Mount the card.
 *
 * @param {object} objectData The Z-report.
 * @param {string} objectId The page's object id.
 * @return {object} The mounted card.
 */
function mountCard(objectData = report, objectId = 'z1') {
	return mount(ZReportBookkeepingWidget, {
		props: { objectData, objectId },
		global: { mocks: { t: globalThis.t } },
	})
}

describe('passing the Z report on to shillinq again', () => {
	beforeEach(() => {
		raiseJournalEntry.mockReset()
		showError.mockReset()
		showSuccess.mockReset()
	})

	it('asks first, and cancelling closes the question without raising anything', async () => {
		const wrapper = mountCard()
		expect(wrapper.find('.confirm-stub').exists()).toBe(false)

		await wrapper.find('[data-testid="pos-eod-retry"]').trigger('click')
		expect(wrapper.find('.confirm-stub').exists()).toBe(true)

		await wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('cancel')
		expect(wrapper.find('.confirm-stub').exists()).toBe(false)
		expect(raiseJournalEntry).not.toHaveBeenCalled()
	})

	it('confirming raises the entry and shows the record the endpoint returned until the page re-reads', async () => {
		raiseJournalEntry.mockResolvedValue({ ...report, bookkeepingStatus: 'raised', shillinqJournalEntryId: 'je-77' })
		const wrapper = mountCard()

		await wrapper.find('[data-testid="pos-eod-retry"]').trigger('click')
		wrapper.findComponent({ name: 'ConfirmDialog' }).vm.$emit('confirm')
		await flush()

		expect(raiseJournalEntry).toHaveBeenCalledWith('z1')
		expect(showSuccess).toHaveBeenCalledWith('Journal entry raised at shillinq.')
		expect(wrapper.find('[data-testid="pos-eod-journal-id"]').text()).toBe('je-77')
		expect(wrapper.find('[data-testid="pos-eod-bookkeeping-status"]').text()).toBe('Passed on to shillinq')
		expect(wrapper.find('[data-testid="pos-eod-retry"]').exists()).toBe(false)

		await wrapper.setProps({ objectData: { ...report } })
		expect(wrapper.find('[data-testid="pos-eod-journal-id"]').exists()).toBe(false)
	})

	it('an empty answer keeps the page record; the report id is used without a page id', async () => {
		raiseJournalEntry.mockResolvedValue(null)
		const wrapper = mountCard(report, '')

		await wrapper.vm.performRetry()

		expect(raiseJournalEntry).toHaveBeenCalledWith('z1')
		expect(showSuccess).toHaveBeenCalled()
		expect(wrapper.find('[data-testid="pos-eod-bookkeeping-status"]').text()).toBe('Failed')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('a refusal shows the server message, or a plain one without it', async () => {
		raiseJournalEntry.mockRejectedValueOnce({ response: { data: { error: 'Day is already closed' } } })
		raiseJournalEntry.mockRejectedValueOnce(new Error('network'))
		const wrapper = mountCard()

		await wrapper.vm.performRetry()
		await wrapper.vm.performRetry()

		expect(showError.mock.calls).toEqual([['Day is already closed'], ['Raise failed.']])
		expect(wrapper.vm.busy).toBe(false)
	})

	it('without any id nothing is raised', async () => {
		const wrapper = mountCard({ bookkeepingStatus: 'failed', transactionCount: 3 }, '')

		await wrapper.vm.performRetry()

		expect(raiseJournalEntry).not.toHaveBeenCalled()
	})

	it('shows a failure with its reason and time', () => {
		const wrapper = mountCard({
			...report,
			bookkeepingFailureReason: 'not_configured',
			bookkeepingFailedAt: '2026-11-13T09:30:00Z',
		})

		expect(wrapper.find('[data-testid="pos-eod-bookkeeping-status"]').text())
			.toMatch(/^Failed: no shillinq connection is set up at .+$/)
	})
})
