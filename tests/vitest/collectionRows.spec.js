// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Reading an object-store collection as rows, as the till and the refund
 * editor do (the POS pages and the export job form).
 */

import { describe, expect, it } from 'vitest'
import { collectionRows } from '../../src/utils/collectionRows.js'

describe('collectionRows', () => {
	it('takes the array the store returns', () => {
		expect(collectionRows([{ id: 'a' }])).toEqual([{ id: 'a' }])
	})

	it('unwraps a results envelope', () => {
		expect(collectionRows({ results: [{ id: 'b' }] })).toEqual([{ id: 'b' }])
	})

	it('is empty for nothing', () => {
		expect(collectionRows(null)).toEqual([])
		expect(collectionRows(undefined)).toEqual([])
		expect(collectionRows({})).toEqual([])
	})
})
