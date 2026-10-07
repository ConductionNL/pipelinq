// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The booking page shows the customer's and the service's names, not their
 * ids (openspec/changes/review-finish).
 */

import { describe, expect, it, vi } from 'vitest'
import { computed } from 'vue'
import { createNameFormatter, objectLabel, PENDING_LABEL } from '../../src/services/nameFormatters.js'

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('createNameFormatter', () => {
	it('shows a placeholder, then the name, and a computed follows', async () => {
		const format = createNameFormatter([async () => ({ name: 'Jan Jansen' })])
		const shown = computed(() => format('c1'))
		expect(shown.value).toBe(PENDING_LABEL)
		await flush()
		expect(shown.value).toBe('Jan Jansen')
	})

	it('falls through to the next lookup (contact, then client)', async () => {
		const contact = vi.fn(async () => null)
		const client = vi.fn(async () => ({ name: 'Acme BV' }))
		const format = createNameFormatter([contact, client])
		format('x1')
		await flush()
		expect(format('x1')).toBe('Acme BV')
		expect(contact).toHaveBeenCalledWith('x1')
	})

	it('looks an id up once', async () => {
		const lookup = vi.fn(async () => ({ name: 'Haircut' }))
		const format = createNameFormatter([lookup])
		format('s1')
		format('s1')
		await flush()
		format('s1')
		expect(lookup).toHaveBeenCalledTimes(1)
	})

	it('shows the id when nothing is found, and nothing for an empty value', async () => {
		const format = createNameFormatter([async () => { throw new Error('404') }])
		format('gone')
		await flush()
		expect(format('gone')).toBe('gone')
		expect(format('')).toBe('')
	})
})

describe('objectLabel', () => {
	it('reads name, full name, title or first plus last name', () => {
		expect(objectLabel({ fullName: 'A B' })).toBe('A B')
		expect(objectLabel({ title: 'T' })).toBe('T')
		expect(objectLabel({ firstName: 'Piet', lastName: 'Klaas' })).toBe('Piet Klaas')
		expect(objectLabel(null)).toBe('')
	})
})
