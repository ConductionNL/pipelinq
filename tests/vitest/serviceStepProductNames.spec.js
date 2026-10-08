// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A composition step saved with a product shows the product's name at once,
 * not its uuid until a reload (pipelinq audit 7 October).
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/appointment-booking/spec.md#requirement-a-saved-composition-step-shows-its-product-name-req-raf-040
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: {},
	CnDetailPage: {},
	CnFormDialog: {},
	useObjectSubscription: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('../../src/components/bookings/ServiceStepsEditor.vue', () => ({
	default: {},
}))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/bookings/ServiceForm.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let methods

beforeAll(async () => {
	globalThis.t = (app, text) => text
	methods = (await import('../../src/views/bookings/ServiceDetail.vue')).default
		.methods
})

/**
 * A ServiceDetail context with a product store stand-in.
 *
 * @param {Function} fetchObject The store's fetchObject.
 * @return {object} The context.
 */
function context(fetchObject) {
	const ctx = { productNames: {}, objectStore: { fetchObject } }
	ctx.productName = methods.productName.bind(ctx)
	ctx.loadProductNames = methods.loadProductNames.bind(ctx)
	ctx.rememberProductNames = methods.rememberProductNames.bind(ctx)
	return ctx
}

describe('composition step product names', () => {
	it('asks again for a product whose first read failed', async () => {
		const fetchObject = vi
			.fn()
			.mockResolvedValueOnce(null)
			.mockResolvedValueOnce({ id: 'p-1', name: 'Adviesuur' })
		const ctx = context(fetchObject)

		await ctx.loadProductNames([{ productId: 'p-1' }])
		await ctx.loadProductNames([{ productId: 'p-1' }])

		expect(fetchObject).toHaveBeenCalledTimes(2)
		expect(ctx.productName('p-1')).toBe('Adviesuur')
	})

	it('names a step from the editor catalogue without a read', () => {
		const fetchObject = vi.fn()
		const ctx = context(fetchObject)

		ctx.rememberProductNames([{ id: 'p-2', name: 'Knipbeurt' }, { id: 'p-3' }])

		expect(ctx.productName('p-2')).toBe('Knipbeurt')
		expect(ctx.productName('p-3')).toBe('p-3')
		expect(fetchObject).not.toHaveBeenCalled()
	})
})
