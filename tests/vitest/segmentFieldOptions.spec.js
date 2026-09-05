// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/segmentFieldOptions.js — the curated segment
 * rule-builder field lists SegmentForm.vue offers per audience.
 *
 * The dotted contact-channel-details fields (`emails.kind`, `phones.kind`,
 * `socialProfiles.network`) are the case that matters most here: they are
 * the UI consumer for SegmentService::resolveFieldType()/
 * evaluateProjectedLeaf()'s dotted array-of-object support — without an
 * entry in this list, a marketer has no way to reach that backend support
 * from the Segments page at all.
 */

import { describe, expect, it } from 'vitest'
import {
	CHANNEL_FIELD_OPTIONS,
	CONTACT_FIELD_OPTIONS,
	CUSTOMER_FIELD_OPTIONS,
	fieldOptionsFor,
} from '../../src/services/segmentFieldOptions.js'

describe('CHANNEL_FIELD_OPTIONS', () => {
	it('offers the three dotted array-of-object fields the SegmentService dotted-path support resolves', () => {
		const values = CHANNEL_FIELD_OPTIONS.map((o) => o.value)
		expect(values).toEqual(
			expect.arrayContaining([
				'emails.kind',
				'phones.kind',
				'socialProfiles.network',
			]),
		)
	})

	it('types every dotted field as string, matching the sub-schema SegmentService resolves against', () => {
		for (const option of CHANNEL_FIELD_OPTIONS) {
			expect(option.type).toBe('string')
			expect(option.label).toBeTruthy()
		}
	})
})

describe('fieldOptionsFor', () => {
	it('includes the dotted channel fields for the contact audience', () => {
		const values = fieldOptionsFor('contact').map((o) => o.value)
		expect(values).toEqual(
			expect.arrayContaining([
				'emails.kind',
				'phones.kind',
				'socialProfiles.network',
			]),
		)
	})

	it('includes the dotted channel fields for the customer audience', () => {
		const values = fieldOptionsFor('customer').map((o) => o.value)
		expect(values).toEqual(
			expect.arrayContaining([
				'emails.kind',
				'phones.kind',
				'socialProfiles.network',
			]),
		)
	})

	it('returns the contact list for an unrecognised entityType, matching SegmentForm.entityType.set()\'s "contact" fallback', () => {
		expect(fieldOptionsFor('nonsense')).toBe(CONTACT_FIELD_OPTIONS)
	})

	it('keeps the contact and customer lists free of duplicate field values', () => {
		for (const list of [CONTACT_FIELD_OPTIONS, CUSTOMER_FIELD_OPTIONS]) {
			const values = list.map((o) => o.value)
			expect(new Set(values).size).toBe(values.length)
		}
	})
})
