// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Board PqZRapport's Boekhouding card: a note on who keeps the books, the
 * bookkeeping status and journal entry id, the button that passes the day on
 * again, and the VAT and payment tables with their amounts on the right.
 *
 * @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button class="nc-button"><slot name="icon" /><slot /></button>' },
	NcNoteCard: { props: ['type'], template: '<div class="nc-note-card" :data-type="type"><slot /></div>' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnWidgetWrapper: {
		props: ['title'],
		computed: { shown() { return globalThis.t('pipelinq', this.title) } },
		template: '<section class="cn-widget"><h3 class="cn-widget__title">{{ shown }}</h3><slot /></section>',
	},
	CnIcon: { template: '<i />' },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: { template: '<div />' } }))
vi.mock('../../src/services/posBookkeepingApi.js', () => ({ raiseJournalEntry: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))

const dutch = {
	Bookkeeping: 'Boekhouding',
	'Bookkeeping status': 'Boekhoudstatus',
	'Journal entry id in shillinq': 'Id journaalpost in Shillinq',
	'none yet': 'nog geen',
	Failed: 'Mislukt',
	'Pass on to shillinq again': 'Opnieuw doorgeven aan shillinq',
	'VAT breakdown': 'Btw-uitsplitsing',
	'Payment methods': 'Betaalwijzen',
}
globalThis.t = (_app, text) => dutch[text] || text
window.OC = { isUserAdmin: () => true }
const { default: ZReportBookkeepingWidget } = await import('../../src/components/pos/ZReportBookkeepingWidget.vue')

const report = {
	id: 'z1',
	bookkeepingStatus: 'failed',
	transactionCount: 29,
	taxBreakdown: [{ rate: 21, base: 412.4, tax: 86.6 }],
	paymentMethodBreakdown: [{ method: 'Pin', amount: 702.5 }],
}

/**
 * @param {object} objectData The Z-report.
 * @return {object} The mounted card.
 */
function mountCard(objectData = report) {
	return mount(ZReportBookkeepingWidget, {
		props: { objectData, objectId: 'z1', title: 'Bookkeeping' },
		global: { mocks: { t: globalThis.t }, stubs: { ConfirmDialog: true } },
	})
}

describe('the Z report bookkeeping card', () => {
	it('is one card titled Boekhouding, opening with the note on who keeps the books', () => {
		const wrapper = mountCard()
		expect(wrapper.find('.cn-widget__title').text()).toBe('Boekhouding')
		expect(wrapper.find('.nc-note-card').attributes('data-type')).toBe('info')
	})

	it('writes the status and the journal entry id as label and value rows', () => {
		const rows = mountCard().findAll('[data-testid="z-bookkeeping-fact"]').map((row) => `${row.find('dt').text()} ${row.find('dd').text()}`)
		expect(rows).toEqual(['Boekhoudstatus Mislukt', 'Id journaalpost in Shillinq nog geen'])
	})

	it('offers to pass the day on again as a secondary button', () => {
		const button = mountCard().find('[data-testid="pos-eod-retry"]')
		expect(button.text()).toBe('Opnieuw doorgeven aan shillinq')
	})

	it('titles the tables as the board and puts the amounts on the right', () => {
		const wrapper = mountCard()
		expect(wrapper.findAll('.z-bookkeeping__subtitle').map((h) => h.text())).toEqual(['Btw-uitsplitsing', 'Betaalwijzen'])
		expect(wrapper.findAll('[data-testid="z-report-tax-table"] td.z-bookkeeping__amount')).toHaveLength(2)
		expect(wrapper.findAll('[data-testid="z-report-payment-table"] td.z-bookkeeping__amount')).toHaveLength(1)
	})

	it('shows no button on a day that is passed on', () => {
		expect(mountCard({ ...report, bookkeepingStatus: 'raised', shillinqJournalEntryId: 'JE-1' }).find('[data-testid="pos-eod-retry"]').exists()).toBe(false)
	})
})
