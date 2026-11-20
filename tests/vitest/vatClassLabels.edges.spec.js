// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Edge cases of the VAT class labels: missing or non-numeric rates, a custom
 * translator, and manifests without product pages or overrides.
 */

import { describe, expect, it } from 'vitest'
import {
	seedVatClassLabels,
	vatClassLabels,
} from '../../src/utils/vatClassLabels.js'

describe('vatClassLabels edge cases', () => {
	it('refuses a non-object rate map', () => {
		expect(vatClassLabels('19')).toBeNull()
		expect(vatClassLabels(undefined)).toBeNull()
	})

	it('drops the percentage for a class whose rate is missing or not a number', () => {
		expect(vatClassLabels({ high: 21, low: 'n/a' })).toEqual({
			exempt: 'Exempt',
			high: 'High (21%)',
			low: 'Low',
			zero: 'Zero',
		})
	})

	it('accepts numeric strings as rates', () => {
		expect(vatClassLabels({ high: '21', low: '9', zero: '0' }).low).toBe('Low (9%)')
	})

	it('passes every class name through the translator', () => {
		const seen = []
		const translate = (text) => {
			seen.push(text)
			return `nl:${text}`
		}
		expect(vatClassLabels({ high: 21 }, translate)).toEqual({
			exempt: 'nl:Exempt',
			high: 'nl:High (21%)',
			low: 'nl:Low',
			zero: 'nl:Zero',
		})
		expect(seen).toEqual(['Exempt', 'High', 'Low', 'Zero'])
	})
})

describe('seedVatClassLabels edge cases', () => {
	it('returns the manifest untouched without rates', () => {
		const manifest = { pages: [{ config: { schema: 'product' } }] }
		expect(seedVatClassLabels(manifest, null)).toBe(manifest)
		expect(manifest.pages[0].config.fieldOverrides).toBeUndefined()
	})

	it('tolerates a manifest without pages and pages without config', () => {
		expect(seedVatClassLabels({}, { high: 21 })).toEqual({})
		const manifest = { pages: [{ id: 'Dashboard' }] }
		expect(seedVatClassLabels(manifest, { high: 21 })).toEqual({ pages: [{ id: 'Dashboard' }] })
	})

	it('creates the vatClass override on a product page that had none', () => {
		const manifest = { pages: [{ config: { schema: 'product' } }] }
		seedVatClassLabels(manifest, { high: 21, low: 9, zero: 0 })
		expect(manifest.pages[0].config.fieldOverrides).toEqual({
			vatClass: {
				enumLabels: { exempt: 'Exempt', high: 'High (21%)', low: 'Low (9%)', zero: 'Zero (0%)' },
			},
		})
	})
})
