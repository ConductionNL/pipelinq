/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment-provider card on a POS transaction and the cash difference on a
 * shift are `facts` widgets in the manifest, no longer pipelinq components.
 *
 * THE FAILURE THIS GUARDS: the old provider card's "Try again" emitted an event
 * nothing listened to, so a failed payment could not be retried. Its facts
 * action starts the payment again with the provider and method the
 * transaction already holds. The tests read the manifest the page renders.
 *
 * @spec openspec/changes/pl2-pos-facts-widgets/specs/pos-payment-methods/spec.md#requirement-a-failed-payment-can-be-tried-again-from-the-transaction
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))

/**
 * A page's grid widget by id.
 *
 * @param {string} pageId The page id.
 * @param {string} widgetId The widget id.
 * @return {object|undefined} The widget.
 */
function widget(pageId, widgetId) {
	const page = manifest.pages.find((p) => p.id === pageId)
	return page?.config?.widgets?.find((w) => w.id === widgetId)
}

/**
 * The action of a widget by id.
 *
 * @param {object} declared The widget.
 * @param {string} id The action id.
 * @return {object|undefined} The action.
 */
function action(declared, id) {
	return declared.content.actions.find((a) => a.id === id)
}

describe('the payment provider on a POS transaction', () => {
	const provider = widget('PosTransactionDetail', 'pos-tx-provider')

	it('is a facts card shown only when a provider handled the payment', () => {
		expect(provider.type).toBe('facts')
		expect(provider.visibleWhen.any.map((c) => c.field)).toEqual(['paymentProvider', 'paymentStatus'])
		const panels = manifest.pages.find((p) => p.id === 'PosTransactionDetail').config.widgets.find((w) => w.id === 'pos-tx-panels')
		expect(panels.content.tabs[0].widgetIds).toContain('pos-tx-provider')
	})

	it('tries a failed payment again with the provider and method it already has', () => {
		const retry = action(provider, 'payment-retry')
		expect(retry.type).toBe('api-call')
		expect(retry.url).toBe('/apps/pipelinq/api/pos-payments/@source.id/initiate')
		expect(retry.payload).toEqual({ providerName: '@source.paymentProvider', paymentMethod: '@source.paymentMethod' })
		expect(retry.visibleWhen).toEqual({ field: 'paymentStatus', value: 'failed' })
	})

	it('completes a pending payment, and reverses a settled one for an admin with a reason', () => {
		expect(action(provider, 'payment-complete').url).toBe('/apps/pipelinq/api/pos-payments/@source.id/capture')
		const reverse = action(provider, 'payment-reverse')
		expect(reverse.adminOnly).toBe(true)
		expect(reverse.endpoint).toEqual({ url: '/apps/pipelinq/api/pos-payments/@source.id/refund', method: 'POST' })
		expect(Object.keys(reverse.formProperties)).toEqual(['reason'])
	})

	it('no longer ships the component whose Try again did nothing', () => {
		expect(existsSync(resolve(root, 'src/components/pos/PaymentStatusCard.vue'))).toBe(false)
		expect(readFileSync(resolve(root, 'src/components/pos/PosTransactionPaymentWidget.vue'), 'utf8')).not.toContain('PaymentStatusCard')
	})
})

describe('the cash difference on a shift', () => {
	const variance = widget('CashShiftDetail', 'shift-variance')

	it('reads the latest count of the shift', () => {
		expect(variance.type).toBe('facts')
		expect(variance.content.source).toEqual({
			register: 'pipelinq',
			schema: 'cashDiff',
			filter: { shift: '@objectId' },
			sort: { field: '_created', dir: 'desc' },
		})
		expect(variance.content.emptyText).toMatch(/^Not counted yet/)
	})

	it('shows the amounts as money and the tolerance under the difference', () => {
		const byPath = Object.fromEntries(variance.content.fields.map((f) => [f.path, f]))
		expect(['expectedAmount', 'actualAmount', 'diffAmount'].map((p) => byPath[p].format)).toEqual(['currency', 'currency', 'currency'])
		expect(byPath.diffAmount.note.parts[1]).toEqual({ path: 'withinTolerance', map: { true: 'within the margin', false: 'outside the margin' } })
		expect(byPath.approvedBy.format).toBe('user')
	})

	it('lets an admin approve or reject a pending difference, naming the difference shown', () => {
		const approve = action(variance, 'diff-approve')
		expect(approve.payload).toEqual({ diffId: '@source.id' })
		const reject = action(variance, 'diff-reject')
		expect(reject.url).toBe('/apps/pipelinq/api/pos-shifts/@objectId/diff/reject')
		expect(reject.payload).toEqual({ diffId: '@source.id', reason: '@input.reason' })
		for (const declared of [approve, reject]) {
			expect(declared.adminOnly).toBe(true)
			expect(declared.visibleWhen).toEqual({ field: 'status', value: 'pending' })
		}
		expect(existsSync(resolve(root, 'src/components/pos/CashShiftVarianceWidget.vue'))).toBe(false)
	})
})
