/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The quote a salesperson makes from a lead (PqOfferte): its schemas and
 * its page.
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
}
const register = read('lib/Settings/register.d/97-quotes.json')
const pages = read('src/manifest.d/82-quotes.json').pages
const detail = pages.find((p) => p.id === 'QuoteDetail')

describe('quote schemas', () => {
	const { schemas, registers } = register.components

	it('uses slugs no other app owns, and joins the pipelinq register', () => {
		expect(Object.keys(schemas)).toEqual(['salesQuote', 'salesQuoteLine'])
		expect(registers.pipelinq.schemas).toEqual(['salesQuote', 'salesQuoteLine'])
	})

	it('moves a quote from draft through sent to accepted or rejected', () => {
		const lifecycle =
			schemas.salesQuote.configuration['x-openregister-lifecycle']
		expect(lifecycle.initial).toBe('draft')
		expect(lifecycle.transitions.send).toEqual(
			expect.objectContaining({ from: ['draft'], to: 'sent' }),
		)
		expect(lifecycle.transitions.accept.from).toEqual(['sent'])
		expect(lifecycle.transitions.toOrder.from).toEqual(['accepted'])
	})

	it('hangs a line on its quote and optionally on a catalogue product', () => {
		const line = schemas.salesQuoteLine
		expect(line.properties.quote.$ref).toBe('salesQuote')
		expect(line.properties.product.$ref).toBe('product')
		expect(line.required).toEqual(['quote', 'description'])
	})
})

describe('quote page', () => {
	it('edits the quote, lists its lines and ends with totals and notes', () => {
		expect(detail.config.layout.map((item) => item.widgetId)).toEqual([
			'quote-facts',
			'quote-lines',
			'quote-totals',
			'quote-notes',
		])
		const lines = detail.config.widgets.find((w) => w.id === 'quote-lines')
		expect(lines.content).toMatchObject({
			schema: 'salesQuoteLine',
			filter: { quote: '@objectId' },
			allowCreate: true,
			allowEdit: true,
		})
	})

	it('sends a draft to the client through the lifecycle', () => {
		const send = detail.config.headerActions.find((a) => a.id === 'send-quote')
		expect(send.payload).toEqual({ action: 'send' })
		expect(detail.config.quickActions).toEqual(['send-quote'])
		expect(detail.config.sideColumn).toEqual([
			'side-status',
			'side-client',
			'side-history',
		])
	})
})
