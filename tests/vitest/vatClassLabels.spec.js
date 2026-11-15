// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The VAT class options follow the configured rates (pipelinq-forms-review).
 */

import { describe, expect, it } from 'vitest'
import {
	seedVatClassLabels,
	vatClassLabels,
} from '../../src/utils/vatClassLabels.js'

describe('vatClassLabels', () => {
	it('puts the configured rate after each class name', () => {
		expect(vatClassLabels({ high: 19, low: 7.5, zero: 0, exempt: 0 })).toEqual({
			high: 'High (19%)',
			low: 'Low (7.5%)',
			zero: 'Zero (0%)',
			exempt: 'Exempt',
		})
	})

	it('gives nothing without rates', () => {
		expect(vatClassLabels(null)).toBeNull()
	})
})

describe('seedVatClassLabels', () => {
	it('labels product pages only and keeps their other overrides', () => {
		const manifest = {
			pages: [
				{
					id: 'Products',
					config: {
						schema: 'product',
						fieldOverrides: { name: { order: 1 } },
					},
				},
				{ id: 'Clients', config: { schema: 'client' } },
			],
		}
		seedVatClassLabels(manifest, { high: 21, low: 9, zero: 0 }, (s) => s)

		expect(manifest.pages[0].config.fieldOverrides.name).toEqual({ order: 1 })
		expect(
			manifest.pages[0].config.fieldOverrides.vatClass.enumLabels.high,
		).toBe('High (21%)')
		expect(manifest.pages[1].config.fieldOverrides).toBeUndefined()
	})
})
