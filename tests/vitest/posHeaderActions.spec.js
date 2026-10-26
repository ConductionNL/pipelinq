/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS transaction, refund and cash-shift headers are declared actions, one
 * primary button per state (PL-ABSTRACT Q9, boards PqKassabon and
 * PqKassadienst), instead of three pipelinq components.
 *
 * @spec openspec/changes/pl2-pos-header-actions/specs/pos-lifecycle-guard-adoption/spec.md#requirement-the-pos-header-shows-one-primary-action-per-state
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))

/**
 * @param {string} id The page id.
 * @return {object} The page.
 */
const page = (id) => manifest.pages.find((p) => p.id === id)

/**
 * @param {object} declared The page.
 * @param {string} id The action id.
 * @return {object} The header action.
 */
const action = (declared, id) => declared.config.headerActions.find((a) => a.id === id)

describe('the POS transaction header', () => {
	const tx = page('PosTransactionDetail')

	it('has one primary action per state and no component of its own', () => {
		expect(tx.actionsComponent).toBeUndefined()
		expect(tx.config.primaryActionByStage).toEqual({ draft: 'tx-confirm', parked: 'tx-resume', confirmed: 'tx-settle' })
		expect(tx.config.quickActions).toEqual(['tx-print-receipt', 'tx-email-receipt', 'tx-register-refund'])
	})

	it('confirms only a cart with lines', () => {
		const confirm = action(tx, 'tx-confirm')
		expect(confirm.url).toBe('/apps/pipelinq/api/pos-transactions/@objectId/confirm')
		expect(confirm.visibleWhen.all[1]).toEqual({ source: { register: 'pipelinq', schema: 'posTransactionLine', filter: { transaction: '@objectId' } }, field: '@total', op: 'gt', value: 0 })
	})

	it('opens the receipt modals with the transaction and goes to the cart editor and the refund form', () => {
		expect(action(tx, 'tx-print-receipt')).toMatchObject({ type: 'open-modal', target: 'PrintReceiptModal', props: { transactionId: '@objectId' } })
		expect(action(tx, 'tx-email-receipt')).toMatchObject({ type: 'open-modal', target: 'EmailReceiptModal', props: { transactionId: '@objectId' } })
		expect(action(tx, 'tx-edit-lines').target).toBe('/pos/@objectId/edit')
		expect(action(tx, 'tx-register-refund').target).toBe('/pos/refunds/new/@objectId')
	})

	it('reverses for an admin only, with a required reason', () => {
		const reverse = action(tx, 'tx-reverse')
		expect(reverse.adminOnly).toBe(true)
		expect(reverse.formProperties.reason.required).toBe(true)
	})
})

describe('the POS refund header', () => {
	const refund = page('PosRefundDetail')

	it('confirms a pending refund as its primary action and rejects it with a reason, both for admins', () => {
		expect(refund.actionsComponent).toBeUndefined()
		expect(refund.config.primaryActionByStage).toEqual({ pending: 'refund-confirm' })
		expect(action(refund, 'refund-confirm').adminOnly).toBe(true)
		const reject = action(refund, 'refund-reject')
		expect(reject.adminOnly).toBe(true)
		expect(reject.endpoint).toEqual({ url: '/apps/pipelinq/api/pos-refunds/@objectId/reject', method: 'POST' })
		expect(reject.formProperties.reason.required).toBe(true)
	})
})

describe('the cash shift header', () => {
	const shift = page('CashShiftDetail')

	it('closes and counts an open shift, asking for the amount', () => {
		expect(shift.actionsComponent).toBeUndefined()
		expect(shift.config.primaryActionByStage).toEqual({ open: 'shift-count' })
		const count = action(shift, 'shift-count')
		expect(count.endpoint.url).toBe('/apps/pipelinq/api/pos-shifts/@objectId/count')
		expect(count.formProperties.amount).toMatchObject({ type: 'number', minimum: 0, required: true })
	})
})

describe('the replaced code', () => {
	it.each([
		'src/components/pos/PosTransactionHeaderActions.vue',
		'src/components/pos/PosRefundHeaderActions.vue',
		'src/components/pos/CashShiftHeaderActions.vue',
		'src/modals/CashShiftCountDialog.vue',
		'src/modals/PosRefundDialog.vue',
		'src/modals/PosRefundRejectDialog.vue',
	])('%s is deleted', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})

	it('registers the receipt modals, which read their own templates', () => {
		const registry = readFileSync(resolve(root, 'src/registry.js'), 'utf8')
		expect(registry).toMatch(/PrintReceiptModal: \{\n\t\tkind: 'modal'/)
		expect(registry).toMatch(/EmailReceiptModal: \{\n\t\tkind: 'modal'/)
		for (const modal of ['PrintReceiptModal', 'EmailReceiptModal']) {
			expect(readFileSync(resolve(root, `src/modals/${modal}.vue`), 'utf8')).toContain('loadActiveReceiptTemplates()')
		}
	})
})
