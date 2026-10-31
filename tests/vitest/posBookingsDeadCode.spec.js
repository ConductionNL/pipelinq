/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * POS, product and booking code no user could reach is gone: four product
 * editors mounted nowhere (with the barcode composable and the variant dialog
 * only they used), the walk-in panel no page mounted, the old product form,
 * and the refund edit route nothing linked to. A walk-in page is specified
 * again as manifest config (openspec change walk-in-queue-page).
 *
 * @spec openspec/changes/walk-in-queue-page/specs/appointment-booking/spec.md#requirement-a-walk-in-operator-works-the-queue-on-a-manifest-page
 */
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const root = resolve(__dirname, '../..')

describe('unreachable POS, product and booking code', () => {
	it.each([
		'src/components/products/BarcodeScanner.vue',
		'src/composables/useBarcodeScanner.js',
		'src/components/products/ModifierGroupPanel.vue',
		'src/components/products/PriceTierTable.vue',
		'src/components/products/ProductVariantPanel.vue',
		'src/modals/ProductVariantDialog.vue',
		'src/components/bookings/WalkInQueuePanel.vue',
		'src/views/products/ProductForm.vue',
	])('%s is deleted', (file) => {
		expect(existsSync(resolve(root, file))).toBe(false)
	})

	it('has no refund edit page', () => {
		const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
		expect(manifest.pages.some((page) => page.id === 'PosRefundEdit' || page.route === '/pos/refunds/:id/edit')).toBe(false)
	})

	it('specifies the walk-in page again', () => {
		expect(existsSync(resolve(root, 'openspec/changes/walk-in-queue-page/specs/appointment-booking/spec.md'))).toBe(true)
	})
})
