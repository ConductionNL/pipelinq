// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The quote and lead forms edit their lines as a table (PqOfferte, PqLead):
 * the lines property is an inverse relation the library turns into
 * CnChildRecordsField, and the quote's line totals add up as the board says.
 *
 * @spec openspec/changes/adopt-library-child-records-lines/tasks.md#task-1
 */

import { computeTotals, rowFromSource, storeComputed } from '@conduction/nextcloud-vue/src/utils/childRecordTotals.js'
import { fieldsFromSchema } from '@conduction/nextcloud-vue/src/utils/schema.js'
import { describe, expect, it } from 'vitest'
import register from '../../lib/Settings/pipelinq_register.json'
import overlay from '../../lib/Settings/register.d/99-zzzzzz-quote-and-lead-lines.json'

const schemas = overlay.components.schemas
const linesField = (slug) => fieldsFromSchema({ properties: { lines: schemas[slug].properties.lines } })[0]

describe('the lines property', () => {
	it('makes the quote form a table of salesQuoteLine pointing back through quote', () => {
		const field = linesField('salesQuote')
		expect(field.widget).toBe('child-records')
		expect(field.childRecords).toMatchObject({ schema: 'salesQuoteLine', parentField: 'quote' })
	})

	it('makes the lead form a table of leadProduct pointing back through lead', () => {
		const field = linesField('lead')
		expect(field.widget).toBe('child-records')
		expect(field.childRecords).toMatchObject({ schema: 'leadProduct', parentField: 'lead' })
	})

	it('moves the schema and register versions', () => {
		expect(schemas.salesQuote.version).toBe('1.2.0')
		expect(schemas.lead.version).toBe('1.6.0')
		expect(schemas.leadProduct.version).toBe('1.2.0')
		expect(register.info['x-changelog']).toContain('1.14.0: salesQuote and lead gain lines')
	})

	it('leaves the lead line total to the server calculation', () => {
		expect(schemas.lead.properties.lines.computed).toBeUndefined()
	})
})

describe('the quote lines', () => {
	const lines = schemas.salesQuote.properties.lines

	it('stores the line total: 2 x 100 less 10% is 180', () => {
		const row = storeComputed({ quantity: 2, unitPrice: 100, discount: 10 }, lines.computed)
		expect(row.total).toBe(180)
	})

	it('adds the stored line totals into the subtotal', () => {
		const rows = [{ quantity: 2, unitPrice: 100, discount: 10 }, { quantity: 1, unitPrice: 45, discount: 0 }]
		expect(computeTotals(rows, lines.totals, lines.computed)).toEqual({ subtotal: 225 })
	})

	it('fills a picked product into a new line', () => {
		const row = rowFromSource({ id: 'p-1', name: 'Adviesuur', unitPrice: 95 }, lines.addFrom.map)
		expect(row).toEqual({ product: 'p-1', name: 'Adviesuur', description: 'Adviesuur', unitPrice: 95 })
	})
})
