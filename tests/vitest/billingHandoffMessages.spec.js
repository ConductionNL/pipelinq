// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Sending to invoicing and to billing: the request a contract's send posts,
 * what each answer and refusal says, and the line the client's billing
 * handoff shows for its last result.
 *
 * @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-to-invoicing-handoff-emit
 */
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => '/index.php' + url.replace('{id}', params?.id) }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcLoadingIcon: {}, NcNoteCard: {} }))

let invoicing
let billing

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)
	invoicing = (await import('../../src/views/contracts/ContractInvoicingSection.vue')).default
	billing = (await import('../../src/components/billing/ClientBillingHandoffSection.vue')).default
}, 60000)

beforeEach(() => {
	http.post.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

function section(state) {
	const ctx = { busy: false, lastInvoiceReference: '', resolvedId: 'k1', loadAvailability: vi.fn(), $emit: vi.fn(), ...state }
	for (const [name, fn] of Object.entries(invoicing.methods)) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return ctx
}

describe('ContractInvoicingSection.sendToInvoicing', () => {
	it('posts the contract, keeps the invoice reference, says so and emits it', async () => {
		http.post.mockResolvedValueOnce({ data: { invoiceReference: 'inv-1' } })
		const ctx = section({})
		await ctx.sendToInvoicing()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/handoff/contract/k1/send-to-invoicing', {})
		expect(ctx.lastInvoiceReference).toBe('inv-1')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Contract sent to invoicing.')
		expect(ctx.$emit).toHaveBeenCalledWith('sent', { invoiceReference: 'inv-1' })
		expect(ctx.busy).toBe(false)
		http.post.mockResolvedValueOnce({ data: {} })
		await ctx.sendToInvoicing()
		expect(ctx.lastInvoiceReference).toBe('')
	})

	it('says why it failed, and reads the availability again when sending is gone', async () => {
		const cases = [
			[{ response: { data: { status: 'invalid-status' } } }, 'Sending to invoicing is no longer available for this contract.', 1],
			[{ response: { data: { status: 'not-available' } } }, 'Sending to invoicing is no longer available for this contract.', 1],
			[{ response: { data: { status: 'handoff-failed', reason: 'No debtor' } } }, 'Could not send this contract to invoicing: No debtor', 0],
			[{ response: { data: { status: 'handoff-failed' } } }, 'Could not send this contract to invoicing: unknown error', 0],
			[new Error('x'), 'Could not send this contract to invoicing.', 0],
			[null, 'Could not send this contract to invoicing.', 0],
		]
		for (const [failure, message, reloads] of cases) {
			http.post.mockRejectedValueOnce(failure)
			const ctx = section({})
			await ctx.sendToInvoicing()
			expect(dialogs.showError).toHaveBeenLastCalledWith(message)
			expect(ctx.loadAvailability).toHaveBeenCalledTimes(reloads)
			expect(ctx.$emit).not.toHaveBeenCalled()
			expect(ctx.busy).toBe(false)
		}
	})

	it('posts nothing while busy or without a contract', async () => {
		await section({ busy: true }).sendToInvoicing()
		await section({ resolvedId: '' }).sendToInvoicing()
		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('ClientBillingHandoffSection.lastResultMessage', () => {
	const message = (lastResult) => billing.computed.lastResultMessage.call({ lastResult })

	it('says what the last send did', () => {
		expect(message(null)).toBe('')
		expect(message({ status: 'synced', invoiceNumber: 'F-1', entryCount: 3 })).toBe('Sent to billing — draft invoice F-1 created (3 entries).')
		expect(message({ status: 'synced', duplicated: true, invoiceNumber: 'F-1' })).toBe('Already sent — Shillinq returned the existing draft invoice F-1.')
		expect(message({ status: 'empty' })).toBe('No approved, un-billed hours in this period.')
		expect(message({ status: 'conflict' })).toBe('This batch is already being processed by Shillinq — try again shortly.')
		expect(message({ status: 'unmapped', message: 'No rate' })).toBe('No rate')
		expect(message({ status: 'unmapped' })).toBe('Shillinq could not resolve this client or a rate. Check the Shillinq organisation reference on this client.')
		expect(message({ status: 'failed', message: 'Down' })).toBe('Down')
		expect(message({ status: 'toString' })).toBe('Could not send this batch to billing.')
	})
})
