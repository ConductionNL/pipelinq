// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Barcode lookup on the till (openspec/changes/screens-kassa-till-board).
 */

import { describe, expect, it } from 'vitest'
import { findProductByBarcode } from '../../src/services/posBarcode.js'

const products = [
	{ id: 'a', name: 'Afvalpas', barcode: '8712345000124', sku: 'AFV' },
	{ id: 'b', name: 'Stadsplattegrond', sku: '8712345000999' },
]

describe('findProductByBarcode', () => {
	it('finds by barcode', () => {
		expect(findProductByBarcode(products, '8712345000124').id).toBe('a')
	})

	it('falls back to the SKU and trims the code', () => {
		expect(findProductByBarcode(products, ' 8712345000999 ').id).toBe('b')
	})

	it('returns null for an unknown or empty code', () => {
		expect(findProductByBarcode(products, '000')).toBeNull()
		expect(findProductByBarcode(products, '')).toBeNull()
		expect(findProductByBarcode(null, '8712345000124')).toBeNull()
	})
})
